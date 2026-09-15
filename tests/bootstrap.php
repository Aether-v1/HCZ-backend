<?php
/**
 * PHPUnit bootstrap
 *
 * 加载 Composer autoloader，初始化 ThinkPHP 应用（不启动 HTTP）。
 * 集成测试需要数据库连接时，通过 .env.testing 或环境变量配置。
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run: php composer.phar install\n");
    exit(1);
}
require_once $autoload;

// 初始化 ThinkPHP 应用（console 模式，不启动 HTTP）
$app = new \think\App();
$app->initialize();

// 测试环境标记
define('HCZ_TESTING', true);

// R1.6d-b: Test-only Session backend override.
// Production config/session.php uses type=cache, store=redis.
// In tests, override to file driver to eliminate non-essential Redis dependency.
// This ONLY applies under HCZ_TESTING (PHPUnit bootstrap), never in production runtime.
if (defined('HCZ_TESTING') && HCZ_TESTING) {
    $sessionConfig = config('session');
    $sessionConfig['type'] = 'file';
    $sessionConfig['store'] = null;
    config(['session' => $sessionConfig]);
}
