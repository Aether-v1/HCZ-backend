<?php
namespace app\middleware;

use Closure;
use think\Request;
use think\Response;
use think\response\Json;

class ApiResponseFormat
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->shouldNormalize($request, $response)) {
            return $response;
        }

        if ($response instanceof Json) {
            $payload = $response->getData();
            $httpCode = method_exists($response, 'getCode') ? (int) $response->getCode() : 200;
            return json($this->normalizePayload($payload, $httpCode), $httpCode);
        }

        return $response;
    }

    protected function shouldNormalize(Request $request, Response $response): bool
    {
        if (!($response instanceof Json)) {
            return false;
        }

        if ($request->isAjax() || $request->isJson() || str_starts_with($request->pathinfo(), 'api/')) {
            return true;
        }

        $accept = strtolower((string) $request->header('accept', ''));
        return str_contains($accept, 'application/json');
    }

    protected function normalizePayload($payload, int $httpCode = 200): array
    {
        if (!is_array($payload)) {
            $isOk = $httpCode >= 200 && $httpCode < 400;
            return [
                'code' => $isOk ? 200 : ($httpCode ?: 500),
                'status' => $isOk ? 'success' : 'error',
                'message' => $isOk ? 'ok' : '请求失败',
                'data' => $payload,
                'success' => $isOk,
                'raw_code' => null,
            ];
        }

        $message = $payload['message'] ?? $payload['msg'] ?? $payload['error'] ?? $payload['info'] ?? ($httpCode >= 400 ? '请求失败' : 'ok');

        $code = $payload['code'] ?? $payload['status_code'] ?? null;
        if ($code === null && isset($payload['status']) && is_numeric($payload['status'])) {
            $code = (int) $payload['status'];
        }
        if ($code === null && isset($payload['success'])) {
            $code = $payload['success'] ? 200 : ($httpCode >= 400 ? $httpCode : 500);
        }
        if ($code === null && isset($payload['status']) && is_string($payload['status'])) {
            $status = strtolower($payload['status']);
            if ($status === 'success' || $status === 'ok') {
                $code = 200;
            } elseif ($status === 'error' || $status === 'fail' || $status === 'failed') {
                $code = $httpCode >= 400 ? $httpCode : 500;
            }
        }
        if ($code === null) {
            $code = $httpCode >= 400 ? $httpCode : 200;
        }

        $rawCode = $code;
        $numericCode = is_numeric($code) ? (int) $code : 200;
        $isOk = $this->isSuccess($payload, $numericCode, $httpCode);
        $status = $isOk ? 'success' : 'error';
        $data = $this->extractData($payload);

        return [
            'code' => $numericCode,
            'status' => $status,
            'message' => (string) $message,
            'data' => $data,
            'success' => $isOk,
            'raw_code' => $rawCode,
        ];
    }

    protected function extractData(array $payload)
    {
        if (array_key_exists('data', $payload)) {
            return $payload['data'];
        }

        $metaKeys = ['code', 'status', 'message', 'msg', 'success', 'error', 'info', 'status_code'];
        $data = $payload;
        foreach ($metaKeys as $key) {
            unset($data[$key]);
        }

        return empty($data) ? null : $data;
    }

    protected function isSuccess(array $payload, int $code, int $httpCode): bool
    {
        if (isset($payload['success'])) {
            return (bool) $payload['success'];
        }

        $status = strtolower((string) ($payload['status'] ?? ''));
        if (in_array($status, ['success', 'ok'], true)) {
            return true;
        }
        if (in_array($status, ['error', 'fail', 'failed'], true)) {
            return false;
        }

        if ($httpCode >= 400) {
            return false;
        }

        return in_array($code, [0, 1, 200], true);
    }
}
