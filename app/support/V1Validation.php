<?php
declare(strict_types=1);

namespace app\support;

use think\Validate;

/**
 * V1 输入校验 Foundation。
 *
 * 职责：白名单字段提取 + ThinkPHP Validate 执行 + 错误详情归一化。
 * 不查询数据库、不检查订单状态、不检查余额、不加载 Model。
 *
 * 安全约束：
 *   - 默认 unknown field = ignore（白名单提取后丢弃）
 *   - strictUnknown=true 时未知字段 → VALIDATION_FAILED
 *   - 不自动 trim / 类型转换，避免 Foundation 擅自修改 password/token/signature
 *   - 返回白名单-only 数组，切断 mass assignment
 *
 * 依赖：ThinkPHP Validate（需 App 容器已初始化，Web 请求与 phpunit 环境均满足）。
 */
class V1Validation
{
    /**
     * 校验输入并返回白名单-only 归一化数组。
     *
     * @param array  $data          原始输入（query 或 JSON body）
     * @param array  $rules         ThinkPHP Validate 规则，如 ['email'=>'require|email']
     * @param array  $messages      自定义错误消息（可选）
     * @param array  $allowedFields 允许字段白名单；为空时不做白名单过滤（不推荐）
     * @param bool   $strictUnknown true 时未知字段触发校验失败
     *
     * @return array 白名单-only 数据（未被 Foundation 修改原值）
     *
     * @throws V1ValidationException 校验失败，details 为 field=>messages[]
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $allowedFields = [],
        bool $strictUnknown = false
    ): array {
        // 1. strict unknown 检测（在白名单提取前，识别客户端传入的未声明字段）
        if ($strictUnknown && $allowedFields !== []) {
            $unknown = array_diff(array_keys($data), $allowedFields);
            if ($unknown !== []) {
                $details = [];
                foreach ($unknown as $field) {
                    $details[(string) $field] = ['Unknown field'];
                }
                throw new V1ValidationException($details);
            }
        }

        // 2. 白名单提取：只保留声明字段，切断 mass assignment
        $filtered = $allowedFields === []
            ? $data
            : array_intersect_key($data, array_flip($allowedFields));

        // 3. ThinkPHP Validate 执行（batch 模式，一次返回多字段错误）
        $v = new Validate();
        $v->rule($rules);
        if ($messages !== []) {
            $v->message($messages);
        }
        $v->batch(true);

        if ($v->check($filtered)) {
            return $filtered;
        }

        // 4. 错误归一化为 field => messages[]
        $raw = $v->getError();
        $details = self::normalizeErrors($raw);
        if ($details === []) {
            $details['_form'] = ['Validation failed'];
        }

        throw new V1ValidationException($details);
    }

    /**
     * 将 ThinkPHP Validate 错误归一化为 field => messages[]。
     *
     * @param mixed $raw getError() 返回值（batch 模式为 array，非 batch 为 string）
     *
     * @return array<string, array<int, string>>
     */
    private static function normalizeErrors(mixed $raw): array
    {
        if (!is_array($raw)) {
            if (is_string($raw) && $raw !== '') {
                return ['_form' => [$raw]];
            }
            return [];
        }

        $details = [];
        foreach ($raw as $field => $msg) {
            $key = (string) $field;
            if (is_array($msg)) {
                $msgs = array_values(array_map('strval', $msg));
            } else {
                $msgs = [(string) $msg];
            }
            if ($msgs === []) {
                continue;
            }
            if (isset($details[$key])) {
                $details[$key] = array_merge($details[$key], $msgs);
            } else {
                $details[$key] = $msgs;
            }
        }
        return $details;
    }
}
