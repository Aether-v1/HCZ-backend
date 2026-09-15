<?php
declare(strict_types=1);
require '__ROOT_PATH__/vendor/autoload.php';
use think\App;
use think\facade\Db;

// 覆盖 ThinkPHP exception handler，确保异常时 exit code 非零
set_exception_handler(function (\Throwable $e): void {
    fwrite(STDERR, "Migration failed: " . get_class($e) . ": " . $e->getMessage() . "\n");
    exit(1);
});

$app = new App('__ROOT_PATH__/');
$app->initialize();
$action = $argv[1] ?? 'migrate';
$migration = new class {
    public function migrate(): void {
        throw new \RuntimeException("Intentional migration failure");
    }
    public function rollback(): void { echo "rollback\n"; }
    public function status(): void { echo "PENDING\n"; }
};
match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("usage\n"),
};