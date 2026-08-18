<?php
declare (strict_types = 1);

namespace app\service;

use app\model\AdminOperationLog;
use think\facade\Log;
use think\facade\Session;
use think\Request;

class AdminOperationLogService
{
    protected Request $request;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?: app()->request;
    }

    public function record(string $action, string $module, string $content, array $options = []): void
    {
        try {
            $admin = $options['admin'] ?? Session::get('admin', []);
            $adminId = (int)($options['admin_id'] ?? ($admin['id'] ?? 0));
            $adminUsername = (string)($options['admin_username'] ?? ($admin['account'] ?? ($admin['name'] ?? '')));

            AdminOperationLog::create([
                'admin_id' => $adminId,
                'admin_username' => $this->limit($adminUsername, 100),
                'action' => $this->limit(trim($action), 100),
                'module' => $this->limit(trim($module), 100),
                'target_id' => isset($options['target_id']) && $options['target_id'] !== '' ? (int)$options['target_id'] : null,
                'target_type' => $this->nullOrLimit($options['target_type'] ?? null, 100),
                'content' => $this->sanitizeRecordContent($content !== '' ? $content : $action, trim($module)),
                'ip' => $this->limit((string)($options['ip'] ?? $this->request->ip()), 64),
                'user_agent' => $this->limit((string)($options['user_agent'] ?? $this->request->header('user-agent', '')), 255),
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::error('admin operation log write error: ' . $e->getMessage(), [
                'action' => $action,
                'module' => $module,
            ]);
        }
    }

    public function summarizeChanges(array $changedFields, array $labels = []): string
    {
        $parts = [];
        foreach ($changedFields as $key => $change) {
            $label = $labels[$key] ?? $key;
            $before = $this->sanitizeFieldValue((string)$key, $change['before'] ?? null);
            $after = $this->sanitizeFieldValue((string)$key, $change['after'] ?? null);

            if ($this->isLongTextField((string)$key)) {
                $parts[] = $label . '已更新';
                continue;
            }

            $parts[] = $label . '：' . $before . ' -> ' . $after;
        }

        if (empty($parts)) {
            return '未检测到关键字段变更';
        }

        return $this->limit(implode('；', $parts), 1000);
    }

    public function maskValue(string $field, $value): string
    {
        $value = $this->normalizeValue($value);
        if ($value === '') {
            return '空';
        }

        if ($this->isSecretField($field)) {
            return $this->maskMiddle($value, 3, 3);
        }

        if ($this->isPhoneField($field)) {
            return $this->maskPhone($value);
        }

        if ($this->isWalletField($field)) {
            return $this->maskMiddle($value, 6, 4);
        }

        if ($this->isCardField($field)) {
            return $this->maskMiddle($value, 4, 4);
        }

        return $this->limit($value, 120);
    }

    protected function sanitizeFieldValue(string $field, $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = trim(strip_tags((string)$value));
        if ($value === '') {
            return '空';
        }

        return $this->maskValue($field, $value);
    }

    protected function normalizeValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return trim((string)$value);
    }

    protected function isSecretField(string $field): bool
    {
        $field = strtolower($field);
        foreach (['password', 'secret', 'token', 'private', 'key'] as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected function isWalletField(string $field): bool
    {
        $field = strtolower($field);
        foreach (['wallet', 'address', 'trc20'] as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected function isPhoneField(string $field): bool
    {
        $field = strtolower($field);
        foreach (['phone', 'mobile', 'telephone', 'tel'] as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected function isCardField(string $field): bool
    {
        $field = strtolower($field);
        foreach (['card', 'bank'] as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected function isLongTextField(string $field): bool
    {
        $field = strtolower($field);
        foreach (['agreement', 'privacy', 'intro', 'notice', 'content', 'describe'] as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected function maskMiddle(string $value, int $left = 3, int $right = 3): string
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length <= ($left + $right)) {
            return str_repeat('*', max(3, $length));
        }

        return mb_substr($value, 0, $left, 'UTF-8')
            . '***'
            . mb_substr($value, -1 * $right, null, 'UTF-8');
    }

    protected function maskPhone(string $value): string
    {
        return preg_replace('/(?<!\d)(1\d{2})\d{4}(\d{4})(?!\d)/u', '$1****$2', $value) ?? $value;
    }

    protected function sanitizeRecordContent(string $content, string $module = ''): string
    {
        $content = trim(strip_tags($content));
        if ($content === '') {
            return '';
        }

        if ($this->isConfigModule($module)) {
            $content = $this->collapseConfigContent($content);
        }

        $content = $this->maskSensitiveText($content);

        return $this->limit($content, 1000);
    }

    protected function isConfigModule(string $module): bool
    {
        return trim($module) === '系统配置';
    }

    protected function collapseConfigContent(string $content): string
    {
        $segments = preg_split('/；/u', $content) ?: [];
        $result = [];

        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^([^：]+)：/u', $segment, $matches)) {
                $result[] = trim((string)$matches[1]) . '：已更新';
                continue;
            }

            $result[] = $segment;
        }

        if ($result === []) {
            return '配置已更新';
        }

        return implode('；', $result);
    }

    protected function maskSensitiveText(string $content): string
    {
        $content = $this->maskPhone($content);

        $content = preg_replace_callback('/\bT[A-Za-z0-9]{33,41}\b/u', function (array $matches): string {
            return $this->maskMiddle($matches[0], 6, 4);
        }, $content) ?? $content;

        return $content;
    }

    protected function limit(string $value, int $length): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 3, 'UTF-8') . '...';
    }

    protected function nullOrLimit($value, int $length): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return $this->limit($value, $length);
    }
}