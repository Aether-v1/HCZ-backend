<?php
header('Content-Type: text/plain; charset=utf-8');

$keyFile = __DIR__ . '/public.pem';

if (!file_exists($keyFile)) {
    http_response_code(500);
    echo 'PUBLIC KEY NOT FOUND';
    exit;
}

readfile($keyFile);
