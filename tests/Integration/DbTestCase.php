<?php
declare(strict_types=1);

namespace tests\Integration;

use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * 集成测试基类
 *
 * 需要数据库连接。无数据库时自动 markTestSkipped。
 * 运行前需配置 .env 中的数据库连接信息。
 */
abstract class DbTestCase extends TestCase
{
    protected static bool $dbAvailable = false;
    protected static bool $dbChecked = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbChecked) {
            self::$dbChecked = true;
            try {
                Db::connect()->query('SELECT 1');
                self::$dbAvailable = true;
            } catch (\Throwable $e) {
                self::$dbAvailable = false;
            }
        }
        if (!self::$dbAvailable) {
            $this->markTestSkipped('数据库不可用，跳过集成测试。请配置 .env 数据库连接后运行。');
        }
    }

    /**
     * 在测试事务中运行，结束后回滚，避免污染数据
     */
    protected function beginTransaction(): void
    {
        Db::startTrans();
    }

    protected function rollback(): void
    {
        Db::rollback();
    }
}
