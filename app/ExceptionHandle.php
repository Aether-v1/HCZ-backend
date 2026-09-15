<?php
namespace app;

use app\contracts\BusinessExceptionInterface;
use app\support\ApiResponse;
use app\support\ErrorCode;
use app\support\RequestId;
use app\support\V1ApiResponse;
use app\support\V1Context;
use app\support\V1ValidationException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 *
 * Batch 1 增强：
 * - 新增 BusinessExceptionInterface 统一处理（输出业务错误码）
 * - 新增安全事件日志分类（认证失败、越权等记录到安全日志）
 * - 新增 X-Request-ID 响应头（便于调用链追踪）
 *
 * R1.4 增强：
 * - 新增 /api/v1 V1 envelope 错误分支（V1ApiResponse + ErrorCodeV1 machine code + request_id）
 * - Legacy /api/* 错误格式完全不变
 *
 * 兼容性保证：
 * - 现有 ValidateException → code=422 行为不变（legacy）
 * - 现有 HttpException → 对应 status code 行为不变（legacy）
 * - 现有其他异常 → code=500 行为不变（legacy）
 * - 非 API 请求 → parent::render() 行为不变
 * - legacy 响应格式 {code, status, message, data, success} 不变
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * Batch 1 增强：
     * - BusinessExceptionInterface 且 isSecurityEvent → 记录安全日志
     * - 其他 → 保持现有 parent::report() 行为
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 安全事件单独记录（认证失败、越权等）
        if ($exception instanceof BusinessExceptionInterface && $exception->isSecurityEvent()) {
            try {
                Log::alert('security_exception', [
                    'request_id' => RequestId::current(),
                    'type' => get_class($exception),
                    'code' => $exception->getErrorCode(),
                    'message' => $exception->getMessage(),
                    'http_status' => $exception->getHttpStatus(),
                    'context' => $exception->getContext(),
                ]);
            } catch (\Throwable $logError) {
                // 日志失败不影响异常处理
            }
        }

        // 使用内置的方式记录异常日志（保持现有行为）
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * R1.4: 优先检测 /api/v1，走 V1 envelope；其余保持 legacy 行为不变。
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // R1.4 P2-03: /api/v1 走 V1 envelope（V1ApiResponse + machine code + request_id）
        if (V1Context::isV1Request($request)) {
            return $this->withRequestId(
                $this->renderV1($e)
            );
        }

        $isApiRequest = $request->isAjax() || $request->isJson() || str_starts_with($request->pathinfo(), "api/");

        if ($isApiRequest) {
            // Batch 1 新增：业务异常统一处理（输出业务错误码）
            if ($e instanceof BusinessExceptionInterface) {
                return $this->withRequestId(
                    ApiResponse::fromException($e)
                );
            }

            // 现有逻辑保持不变
            $httpCode = 500;
            if ($e instanceof ValidateException) {
                $httpCode = 422;
            } elseif ($e instanceof HttpException) {
                $httpCode = $e->getStatusCode();
            }

            return $this->withRequestId(
                json([
                    "code" => $httpCode,
                    "status" => "error",
                    "message" => $e->getMessage() ?: "系统异常，请稍后重试",
                    "data" => null,
                    "success" => false,
                ], $httpCode)
            );
        }

        return parent::render($request, $e);
    }

    /**
     * R1.4: V1 API 异常渲染（V1 envelope）
     *
     * 映射：
     * - BusinessExceptionInterface → numeric errorCode → ErrorCode::toPublicCode() machine code
     * - V1ValidationException → VALIDATION_FAILED + details (field→messages[])
     * - ValidateException (ThinkPHP 原生) → VALIDATION_FAILED + details
     * - HttpException(404) → RESOURCE_NOT_FOUND
     * - HttpException(405) → METHOD_NOT_ALLOWED
     * - 其他 HttpException → 对应 status code
     * - 其他 Throwable → INTERNAL_ERROR (500)，脱敏
     *
     * @param Throwable $e
     * @return Response
     */
    private function renderV1(Throwable $e): Response
    {
        // BusinessException: numeric code → machine code
        if ($e instanceof BusinessExceptionInterface) {
            $numericCode = $e->getErrorCode();
            $publicCode = ErrorCode::toPublicCode($numericCode);
            $httpStatus = $e->getHttpStatus();
            return V1ApiResponse::error(
                $publicCode,
                $e->getMessage() ?: '请求失败',
                $httpStatus
            );
        }

        // V1ValidationException (R1.2): details 已归一化为 field→messages[]
        if ($e instanceof V1ValidationException) {
            return V1ApiResponse::error(
                'VALIDATION_FAILED',
                $e->getMessage() ?: '请求参数验证失败',
                422,
                $e->details
            );
        }

        // ThinkPHP 原生 ValidateException: 提取错误信息归一化
        if ($e instanceof ValidateException) {
            $details = $this->normalizeValidationDetails($e->getError());
            return V1ApiResponse::error(
                'VALIDATION_FAILED',
                $e->getMessage() ?: '请求参数验证失败',
                422,
                $details
            );
        }

        // HttpException: 404/405/其他
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            return match ($statusCode) {
                404 => V1ApiResponse::error('RESOURCE_NOT_FOUND', $e->getMessage() ?: '资源不存在', 404),
                405 => V1ApiResponse::error('METHOD_NOT_ALLOWED', $e->getMessage() ?: '请求方法不允许', 405),
                default => V1ApiResponse::error(
                    ErrorCode::toPublicCode($statusCode * 100),
                    $e->getMessage() ?: '请求失败',
                    $statusCode
                ),
            };
        }

        // 其他异常: INTERNAL_ERROR，脱敏（不泄露 stack trace / SQL / 内部 message）
        return V1ApiResponse::internalError($e);
    }

    /**
     * 将 ThinkPHP ValidateException 错误归一化为 field→messages[]
     *
     * ThinkPHP ValidateException::getError() 可能返回 string 或 array。
     * R1.2 contract: details = {field: [messages]}
     */
    private function normalizeValidationDetails(mixed $error): array
    {
        if (is_array($error)) {
            $details = [];
            foreach ($error as $field => $message) {
                if (is_int($field)) {
                    // 无字段名的错误，归入 _global
                    $details['_global'][] = is_array($message) ? implode('; ', $message) : (string) $message;
                } else {
                    $details[$field] = is_array($message) ? array_map('strval', $message) : [(string) $message];
                }
            }
            return $details;
        }

        // string 错误: 归入 _global
        return ['_global' => [(string) $error]];
    }

    /**
     * 为响应添加 X-Request-ID 头
     */
    private function withRequestId(Response $response): Response
    {
        try {
            return $response->header(['X-Request-ID' => RequestId::current()]);
        } catch (\Throwable $e) {
            return $response;
        }
    }
}
