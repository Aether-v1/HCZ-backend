<?php
declare(strict_types=1);

namespace tests\Integration;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use tests\Support\TestDataFactory;
use think\facade\Session;

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
        // 确保动态创建的测试表存在（必须在事务开启前调用，CREATE TABLE 会隐式提交）
        TestDataFactory::ensureTestTables();

        // R1.6d-b.1: 每个测试方法开始前清除 Session Manager 缓存的 Store 实例。
        // 下次 Session:: 访问时会创建全新 Store（含新 session ID / 新 session file），
        // 从根本上避免读取前一个测试的 session 状态。
        // 这比 clear()+save() 可靠——Store::save() 内部会通过 clearFlashData()→get()→init()
        // 重新载入旧文件数据，导致 tearDown 清理不可靠。
        try {
            Session::forgetDriver();
        } catch (\Throwable $e) {
            // Session 可能未初始化，best-effort
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

    /**
     * R1.6d-b.1: Session isolation。
     * 不再使用 clear()+save()（该组合在 Store::init=false 时不可靠，
     * 因为 save()→clearFlashData()→get()→init() 会重新载入旧文件数据）。
     * 改为 forgetDriver() 直接放弃当前 Store 实例，下次访问创建全新 Store。
     * 旧 session 文件成为孤儿文件但永远不会被新 Store 读取（新 Store 有新 session ID）。
     */
    protected function tearDown(): void
    {
        try {
            Session::forgetDriver();
        } catch (\Throwable $e) {
            // best-effort
        }
        parent::tearDown();
    }
}
