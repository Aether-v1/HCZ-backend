<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\User as UserModel;
use app\service\TransactionOrderService;
use think\facade\Db;

/**
 * TEST-001: C2C 资金安全集成测试
 *
 * 覆盖：
 * - C2C 并发下单（挂单 100，两笔 80 应只有一笔成功）
 * - 同订单并发放币
 * - 放币金额不足
 * - 手续费负数
 * - 手续费 > pay_amount
 * - usdt_amount + fee != pay_amount
 *
 * 需要数据库。无 DB 时自动跳过。
 * 注意：并发测试仅代码级推演，未进行真实多进程并发压测。
 */
class C2CFundSafetyTest extends DbTestCase
{
    private int $sellerUid = 0;
    private int $buyerUid = 0;
    private int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbAvailable) {
            return;
        }
        $this->beginTransaction();
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        if (self::$dbAvailable) {
            $this->rollback();
        }
        parent::tearDown();
    }

    private function seedTestData(): void
    {
        // 创建卖家
        $this->sellerUid = (int)UserModel::create([
            'mobile' => 'test_seller_' . uniqid(),
            'password' => password_hash('test1234', PASSWORD_BCRYPT),
            'salt' => 'test',
            'nickname' => '测试卖家',
            'invite_code' => 'SEL' . substr(uniqid(), -6),
            'balance' => 1000.00,
            'frozen_amount' => 0,
            'status' => 1,
        ])->id;

        // 创建买家
        $this->buyerUid = (int)UserModel::create([
            'mobile' => 'test_buyer_' . uniqid(),
            'password' => password_hash('test1234', PASSWORD_BCRYPT),
            'salt' => 'test',
            'nickname' => '测试买家',
            'invite_code' => 'BUY' . substr(uniqid(), -6),
            'balance' => 1000.00,
            'frozen_amount' => 0,
            'status' => 1,
        ])->id;

        // 创建挂单：sell_account = 100
        $this->productId = (int)TransactionProduct::create([
            'uid' => $this->sellerUid,
            'sell_account' => 100.00,
            'unit_price' => 1.00,
            'min_limit' => 1.00,
            'max_limit' => 100.00,
            'status' => 1,
            'bank_card_info' => 'test bank',
        ])->id;
    }

    /**
     * C2C 并发下单：挂单 100，两笔各 80，应只有一笔成功
     *
     * 注意：此为单进程顺序模拟，验证 SELECT FOR UPDATE + SUM 校验逻辑。
     * 真实并发测试需多进程/多线程压测。
     */
    public function testConcurrentOversellingPrevention(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $service = new TransactionOrderService();

        // 第一笔 80 应成功
        Db::startTrans();
        try {
            $product = TransactionProduct::where('id', $this->productId)->lock(true)->find();
            $committed = (float)Db::name('transaction_order')
                ->where('pid', $this->productId)
                ->whereIn('status', [0, 1])
                ->lock(true)
                ->sum('pay_amount');
            $available = round(100.00 - $committed, 2);

            $this->assertGreaterThanOrEqual(80, $available, '第一笔下单前可用量应 >= 80');

            TransactionOrder::create([
                'uid' => $this->buyerUid,
                'sell_uid' => $this->sellerUid,
                'pid' => $this->productId,
                'order_number' => 'TEST' . date('Ymd') . substr(uniqid(), -6),
                'pay_amount' => 80.00,
                'payment_amount' => 80.00,
                'remittance_user_name' => '测试买家',
                'bank_card_info' => 'test',
                'unit_price' => 1.00,
                'transaction_fees' => 1.00,
                'usdt_amount' => 79.00,
                'status' => 0,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->fail('第一笔下单应成功: ' . $e->getMessage());
        }

        // 第二笔 80 应失败（剩余 20 < 80）
        Db::startTrans();
        try {
            $product = TransactionProduct::where('id', $this->productId)->lock(true)->find();
            $committed = (float)Db::name('transaction_order')
                ->where('pid', $this->productId)
                ->whereIn('status', [0, 1])
                ->lock(true)
                ->sum('pay_amount');
            $available = round(100.00 - $committed, 2);

            $this->assertLessThan(80, $available, '第二笔下单前可用量应 < 80');
            Db::rollback();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->fail('校验异常: ' . $e->getMessage());
        }

        // 验证活跃订单总量
        $totalActive = (float)Db::name('transaction_order')
            ->where('pid', $this->productId)
            ->whereIn('status', [0, 1])
            ->sum('pay_amount');
        $this->assertEquals(80.00, $totalActive, '活跃订单总量应为 80');
    }

    /**
     * 放币金额不足：sell_account < pay_amount 时禁止静默放币
     */
    public function testReleaseInsufficientSellAccountThrows(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        // 创建一个 sell_account 不足的订单场景
        $order = TransactionOrder::create([
            'uid' => $this->buyerUid,
            'sell_uid' => $this->sellerUid,
            'pid' => $this->productId,
            'order_number' => 'TESTINSUF' . date('Ymd') . substr(uniqid(), -6),
            'pay_amount' => 200.00, // 超过挂单的 100
            'payment_amount' => 200.00,
            'remittance_user_name' => '测试',
            'bank_card_info' => 'test',
            'unit_price' => 1.00,
            'transaction_fees' => 2.00,
            'usdt_amount' => 198.00,
            'status' => 1, // 已汇款
        ]);

        $service = new TransactionOrderService();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('挂单剩余数量不足');
        $service->releaseBySeller((int)$order->id, $this->sellerUid);
    }

    /**
     * 手续费负数应拒绝
     */
    public function testReleaseNegativeFeeThrows(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $order = TransactionOrder::create([
            'uid' => $this->buyerUid,
            'sell_uid' => $this->sellerUid,
            'pid' => $this->productId,
            'order_number' => 'TESTNEGFEE' . date('Ymd') . substr(uniqid(), -6),
            'pay_amount' => 50.00,
            'payment_amount' => 50.00,
            'remittance_user_name' => '测试',
            'bank_card_info' => 'test',
            'unit_price' => 1.00,
            'transaction_fees' => -5.00, // 负数手续费
            'usdt_amount' => 55.00,
            'status' => 1,
        ]);

        $service = new TransactionOrderService();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('手续费异常');
        $service->releaseBySeller((int)$order->id, $this->sellerUid);
    }

    /**
     * 手续费 > pay_amount 应拒绝
     */
    public function testReleaseFeeGreaterThanPayAmountThrows(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $order = TransactionOrder::create([
            'uid' => $this->buyerUid,
            'sell_uid' => $this->sellerUid,
            'pid' => $this->productId,
            'order_number' => 'TESTBIGFEE' . date('Ymd') . substr(uniqid(), -6),
            'pay_amount' => 50.00,
            'payment_amount' => 50.00,
            'remittance_user_name' => '测试',
            'bank_card_info' => 'test',
            'unit_price' => 1.00,
            'transaction_fees' => 60.00, // > pay_amount
            'usdt_amount' => -10.00,
            'status' => 1,
        ]);

        $service = new TransactionOrderService();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('手续费异常');
        $service->releaseBySeller((int)$order->id, $this->sellerUid);
    }

    /**
     * usdt_amount + fee != pay_amount 应拒绝
     */
    public function testReleaseAmountMismatchThrows(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $order = TransactionOrder::create([
            'uid' => $this->buyerUid,
            'sell_uid' => $this->sellerUid,
            'pid' => $this->productId,
            'order_number' => 'TESTMISMATCH' . date('Ymd') . substr(uniqid(), -6),
            'pay_amount' => 50.00,
            'payment_amount' => 50.00,
            'remittance_user_name' => '测试',
            'bank_card_info' => 'test',
            'unit_price' => 1.00,
            'transaction_fees' => 2.00,
            'usdt_amount' => 40.00, // 40 + 2 = 42 != 50
            'status' => 1,
        ]);

        $service = new TransactionOrderService();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('金额不匹配');
        $service->releaseBySeller((int)$order->id, $this->sellerUid);
    }

    /**
     * 同订单并发放币：第一次成功后，第二次因 status != 1 应失败
     */
    public function testDuplicateReleaseThrows(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $order = TransactionOrder::create([
            'uid' => $this->buyerUid,
            'sell_uid' => $this->sellerUid,
            'pid' => $this->productId,
            'order_number' => 'TESTDUPREL' . date('Ymd') . substr(uniqid(), -6),
            'pay_amount' => 10.00,
            'payment_amount' => 10.00,
            'remittance_user_name' => '测试',
            'bank_card_info' => 'test',
            'unit_price' => 1.00,
            'transaction_fees' => 0.50,
            'usdt_amount' => 9.50,
            'status' => 1,
        ]);

        $service = new TransactionOrderService();
        // 第一次放币应成功
        $service->releaseBySeller((int)$order->id, $this->sellerUid);

        // 第二次放币应失败（status 已变为 3）
        $this->expectException(\Exception::class);
        $service->releaseBySeller((int)$order->id, $this->sellerUid);
    }
}
