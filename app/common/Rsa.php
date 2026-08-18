<?php
namespace app\common;

class Rsa
{
    public static function decrypt($encrypted)
    {
        if (empty($encrypted)) return false;

        $privateKey = SecurityKeyResolver::resolveRsaPrivateKey();

        // JSEncrypt默认PKCS1填充
        $encrypted = base64_decode($encrypted);
        if ($encrypted === false) return false;

        $result = openssl_private_decrypt($encrypted, $decrypted, $privateKey, OPENSSL_PKCS1_PADDING);
        return $result ? $decrypted : false;
    }
}
