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
