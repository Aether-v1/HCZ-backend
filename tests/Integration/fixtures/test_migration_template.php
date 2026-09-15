<?php
declare(strict_types=1);
require '__ROOT_PATH__/vendor/autoload.php';
use think\App;
use think\facade\Db;
$app = new App('__ROOT_PATH__/');
$app->initialize();
$action = $argv[1] ?? 'migrate';
$migration = new class {
    public function migrate(): void {
        Db::execute("CREATE TABLE IF NOT EXISTS `__TABLE_NAME__` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(64) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "Migration completed: __TABLE_NAME__\n";
    }
    public function rollback(): void {
        Db::execute("DROP TABLE IF EXISTS `__TABLE_NAME__`");
        echo "Rollback completed: __TABLE_NAME__\n";
    }
    public function status(): void {
        $tables = Db::query("SHOW TABLES LIKE '__TABLE_NAME__'");
        if (empty($tables)) {
            echo "STATUS: PENDING\n";
        } else {
            echo "STATUS: APPLIED\n";
        }
    }
};
match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("usage\n"),
};