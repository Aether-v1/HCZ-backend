<?php

return [
    'data_encryption_key' => (string) env('DATA_ENCRYPTION_KEY', ''),
    'rsa_private_key' => (string) env('RSA_PRIVATE_KEY', ''),
    'rsa_private_key_path' => (string) env('RSA_PRIVATE_KEY_PATH', ''),
];