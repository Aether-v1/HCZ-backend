<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

class UploadService
{
    private const DEFAULT_MAX_BYTES = 10485760;
    private const DEFAULT_PRIVATE_ROOT = 'runtime/private';

    private const IMAGE_MIME_EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function storeImageUpload(object|string|null $source, array $options = []): array
    {
        return $this->storeByVisibility($source, $options, true);
    }

    public function storePrivateImageUpload(object|string|null $source, array $options = []): array
    {
        return $this->storeByVisibility($source, $options, false);
    }

    private function storeByVisibility(object|string|null $source, array $options, bool $isPublic): array
    {
        $directory = $this->normalizeDirectory((string)($options['directory'] ?? 'storage/picture'));
        $basename = $this->sanitizeBasename((string)($options['basename'] ?? ''));
        $maxBytes = max(1, (int)($options['max_bytes'] ?? self::DEFAULT_MAX_BYTES));
        $allowedMimeMap = $this->resolveAllowedMimeMap((array)($options['allowed_mimes'] ?? []));
        $emptyMessage = (string)($options['empty_message'] ?? '请选择图片');
        $storageRoot = $this->resolveStorageRoot((string)($options['storage_root'] ?? ''), $isPublic);

        if (is_object($source)) {
            return $this->storeUploadedFile($source, $directory, $basename, $allowedMimeMap, $maxBytes, $storageRoot, $isPublic);
        }

        $payload = trim((string)$source);
        if ($payload === '') {
            throw new RuntimeException($emptyMessage);
        }

        return $this->storeBase64Payload($payload, $directory, $basename, $allowedMimeMap, $maxBytes, $storageRoot, $isPublic);
    }

    public function formatLegacyPayload(string $publicPath): array
    {
        return [
            'url' => $publicPath,
            'src' => $publicPath,
            'path' => $publicPath,
            'result' => $publicPath,
            'image' => $publicPath,
            'file' => $publicPath,
        ];
    }

    private function storeUploadedFile(
        object $file,
        string $directory,
        string $basename,
        array $allowedMimeMap,
        int $maxBytes,
        string $storageRoot,
        bool $isPublic
    ): array {
        $error = method_exists($file, 'getError') ? (int)$file->getError() : UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('文件上传失败');
        }

        $tmpPath = '';
        if (method_exists($file, 'getRealPath')) {
            $tmpPath = (string)($file->getRealPath() ?: '');
        }
        if ($tmpPath === '' && method_exists($file, 'getPathname')) {
            $tmpPath = (string)($file->getPathname() ?: '');
        }
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('文件上传失败');
        }

        $size = method_exists($file, 'getSize') ? (int)$file->getSize() : (int)(filesize($tmpPath) ?: 0);
        $this->assertFileSize($size, $maxBytes);

        $extension = $this->detectExtensionFromPath($tmpPath, $allowedMimeMap);
        $targetPath = $this->buildTargetPath($storageRoot, $directory, $this->buildFilename($basename, $extension));
        $this->ensureDirectoryExists(dirname($targetPath));

        $moved = false;
        if (is_uploaded_file($tmpPath)) {
            $moved = move_uploaded_file($tmpPath, $targetPath);
        }
        if (!$moved) {
            $moved = @copy($tmpPath, $targetPath);
        }
        if (!$moved) {
            throw new RuntimeException('文件上传失败');
        }

        @chmod($targetPath, 0644);

        return $this->buildResult($directory, basename($targetPath), $isPublic, $storageRoot);
    }

    private function storeBase64Payload(
        string $payload,
        string $directory,
        string $basename,
        array $allowedMimeMap,
        int $maxBytes,
        string $storageRoot,
        bool $isPublic
    ): array {
        [$encoded, $declaredExtension] = $this->parseBase64Payload($payload);
        $this->assertEncodedSize($encoded, $maxBytes);

        $binary = base64_decode($encoded, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('图片上传错误');
        }

        $this->assertFileSize(strlen($binary), $maxBytes);
        $extension = $this->detectExtensionFromString($binary, $allowedMimeMap);
        if ($declaredExtension !== '' && $declaredExtension !== $extension) {
            throw new RuntimeException('图片内容与声明类型不匹配');
        }

        $targetPath = $this->buildTargetPath($storageRoot, $directory, $this->buildFilename($basename, $extension));
        $this->ensureDirectoryExists(dirname($targetPath));

        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('文件上传失败');
        }

        @chmod($targetPath, 0644);

        return $this->buildResult($directory, basename($targetPath), $isPublic, $storageRoot);
    }

    private function parseBase64Payload(string $payload): array
    {
        $payload = preg_replace('/\s+/', '', trim($payload)) ?? '';
        if ($payload === '') {
            throw new RuntimeException('图片上传错误');
        }

        if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $payload, $matches)) {
            return [$matches[2], $this->normalizeDeclaredExtension($matches[1])];
        }

        if (str_starts_with($payload, 'data:')) {
            throw new RuntimeException('图片上传类型错误');
        }

        return [$payload, ''];
    }

    private function normalizeDeclaredExtension(string $extension): string
    {
        $extension = strtolower(trim($extension));

        return match ($extension) {
            'jpeg', 'jpg', 'pjpeg' => 'jpg',
            default => $extension,
        };
    }

    private function detectExtensionFromPath(string $path, array $allowedMimeMap): string
    {
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            throw new RuntimeException('图片内容无效');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $path);
                finfo_close($finfo);
            }
        }
        if (($mime === '' || !isset($allowedMimeMap[$mime])) && is_array($imageInfo) && !empty($imageInfo['mime'])) {
            $mime = (string)$imageInfo['mime'];
        }
        if ($mime === '' || !isset($allowedMimeMap[$mime])) {
            throw new RuntimeException('图片上传类型错误');
        }

        return $allowedMimeMap[$mime];
    }

    private function detectExtensionFromString(string $binary, array $allowedMimeMap): string
    {
        if (function_exists('getimagesizefromstring') && @getimagesizefromstring($binary) === false) {
            throw new RuntimeException('图片内容无效');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_buffer($finfo, $binary);
                finfo_close($finfo);
            }
        }
        if ($mime === '' || !isset($allowedMimeMap[$mime])) {
            throw new RuntimeException('图片上传类型错误');
        }

        return $allowedMimeMap[$mime];
    }

    private function assertFileSize(int $size, int $maxBytes): void
    {
        if ($size <= 0) {
            throw new RuntimeException('图片上传错误');
        }
        if ($size > $maxBytes) {
            throw new RuntimeException('图片大小不能超过 10MB');
        }
    }

    private function assertEncodedSize(string $encoded, int $maxBytes): void
    {
        $trimmed = rtrim($encoded, '=');
        $estimated = (int)floor(strlen($trimmed) * 3 / 4);
        if ($estimated > $maxBytes) {
            throw new RuntimeException('图片大小不能超过 10MB');
        }
    }

    private function normalizeDirectory(string $directory): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '' || str_contains($directory, '..')) {
            throw new RuntimeException('上传目录配置错误');
        }

        return $directory;
    }

    private function sanitizeBasename(string $basename): string
    {
        $basename = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($basename)) ?? '';
        return trim($basename, '._-');
    }

    private function resolveAllowedMimeMap(array $allowedMimes): array
    {
        if ($allowedMimes === []) {
            return self::IMAGE_MIME_EXTENSION_MAP;
        }

        $map = [];
        foreach ($allowedMimes as $mime) {
            $mime = strtolower((string)$mime);
            if (isset(self::IMAGE_MIME_EXTENSION_MAP[$mime])) {
                $map[$mime] = self::IMAGE_MIME_EXTENSION_MAP[$mime];
            }
        }
        if ($map === []) {
            throw new RuntimeException('上传类型配置错误');
        }

        return $map;
    }

    private function buildFilename(string $basename, string $extension): string
    {
        if ($basename === '') {
            try {
                $basename = bin2hex(random_bytes(16));
            } catch (\Exception) {
                $basename = str_replace('.', '', uniqid('', true));
            }
        }

        return $basename . '.' . $extension;
    }

    private function resolveStorageRoot(string $configuredRoot, bool $isPublic): string
    {
        $root = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configuredRoot));
        if ($root !== '') {
            if (preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $configuredRoot)) {
                return rtrim($configuredRoot, "\\/");
            }

            return rtrim(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $root, DIRECTORY_SEPARATOR);
        }

        if ($isPublic) {
            return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public';
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_PRIVATE_ROOT);
    }

    private function buildTargetPath(string $storageRoot, string $directory, string $filename): string
    {
        return rtrim($storageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory)
            . DIRECTORY_SEPARATOR . $filename;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('图片缓存目录创建失败，请联系平台客服处理！');
        }
    }

    private function buildResult(string $directory, string $filename, bool $isPublic, string $storageRoot): array
    {
        $relativePath = trim($directory, '/') . '/' . $filename;

        return [
            'public_path' => $isPublic ? '/' . $relativePath : '',
            'relative_path' => $relativePath,
            'absolute_path' => $this->buildTargetPath($storageRoot, $directory, $filename),
            'filename' => $filename,
        ];
    }
}
