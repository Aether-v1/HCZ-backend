<?php
declare (strict_types=1);

namespace app\common;

final class SecurityKeyResolver
{
    public static function resolveDataEncryptionKey(): string
    {
        $key = trim((string) config('security.data_encryption_key', ''));
        if ($key === '') {
            throw new \RuntimeException('敏感数据加密密钥未配置');
        }

        if (strlen($key) < 32) {
            throw new \RuntimeException('敏感数据加密密钥长度不足，请至少配置32位');
        }

        return $key;
    }

    public static function resolveRsaPrivateKey(): string
    {
        $inlineKey = self::normalizePemString((string) config('security.rsa_private_key', ''));
        if ($inlineKey !== '') {
            return self::validatePrivateKey($inlineKey);
        }

        $configuredPath = trim((string) config('security.rsa_private_key_path', ''));
        if ($configuredPath === '') {
            throw new \RuntimeException('RSA私钥未配置');
        }

        $privateKeyPath = self::resolveExternalPrivateKeyPath($configuredPath);
        $privateKeyContent = @file_get_contents($privateKeyPath);
        if (!is_string($privateKeyContent) || trim($privateKeyContent) === '') {
            throw new \RuntimeException('RSA私钥读取失败');
        }

        return self::validatePrivateKey($privateKeyContent);
    }

    private static function normalizePemString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return str_replace(["\r\n", '\\r\\n', '\\n', "\r"], "\n", $value);
    }

    private static function resolveExternalPrivateKeyPath(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new \RuntimeException('RSA私钥路径无效或不可读');
        }

        $projectRoot = realpath((string) root_path());
        if ($projectRoot !== false) {
            $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
            $normalizedPath = str_replace('\\', '/', $realPath);
            if ($normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . '/')) {
                throw new \RuntimeException('RSA私钥路径不能指向项目仓库内文件');
            }
        }

        return $realPath;
    }

    private static function validatePrivateKey(string $privateKey): string
    {
        $privateKey = trim(self::normalizePemString($privateKey));
        if ($privateKey === '') {
            throw new \RuntimeException('RSA私钥内容为空');
        }

        if (!preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----/', $privateKey) || !preg_match('/-----END (?:RSA )?PRIVATE KEY-----/', $privateKey)) {
            throw new \RuntimeException('RSA私钥格式无效');
        }

        if (!openssl_pkey_get_private($privateKey)) {
            throw new \RuntimeException('RSA私钥无效');
        }

        return $privateKey;
    }
}