<?php
declare(strict_types=1);

namespace tests\Support;

use app\model\Order;
use app\model\RebateRecord;
use app\model\User as UserModel;
use app\service\AuthorizationService;
use think\facade\Db;

/**
 * 统一测试数据工厂
 *
 * 为 D3-F4 Golden Behavior 测试提供可复用、可隔离的测试数据。
 * 所有方法在调用方事务内执行（由 DbTestCase setUp 开启事务）。
 * 不使用固定 ID（UID=1 / admin.id=1 / 固定 order_id）。
 */
class TestDataFactory
{
    private static bool $tablesEnsured = false;

    /**
     * 确保测试所需的动态表存在（生产代码 CREATE TABLE IF NOT EXISTS 的表）
     * 必须在事务外调用（CREATE TABLE 会隐式提交事务）。
     */
    public static function ensureTestTables(): void
    {
        if (self::$tablesEnsured) {
            return;
        }

        // cz_points_exchange_order（与 AdminApi.php 行 3501 生产 schema 一致）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_points_exchange_order` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `item_id` varchar(64) NOT NULL DEFAULT '',
            `item_type` varchar(16) NOT NULL DEFAULT 'coupon',
            `item_title` varchar(128) NOT NULL DEFAULT '',
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待处理 1=已发放 2=已拒绝',
            `remark` varchar(256) NOT NULL DEFAULT '',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`),
            KEY `idx_item_status` (`item_id`,`status`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // cz_points_record（与 PointsActions.php 行 578 生产插入字段一致）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_points_record` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `points` int(11) NOT NULL DEFAULT '0',
            `reason` varchar(255) NOT NULL DEFAULT '',
            `type` varchar(32) NOT NULL DEFAULT '',
            `create_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`),
            KEY `idx_type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // cz_rebate_record（与 common.php rebate() 生产插入字段一致）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_rebate_record` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `tid` int(11) unsigned NOT NULL DEFAULT '0',
            `order_number` varchar(64) NOT NULL DEFAULT '',
            `amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `level` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uid_tid_order_level` (`uid`,`tid`,`order_number`,`level`),
            KEY `idx_order_number` (`order_number`),
            KEY `idx_tid` (`tid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // cz_admin_operation_log（与 AdminOperationLogService 生产插入字段一致）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_admin_operation_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `admin_id` int(11) unsigned NOT NULL DEFAULT '0',
            `admin_username` varchar(100) NOT NULL DEFAULT '',
            `action` varchar(100) NOT NULL DEFAULT '',
            `module` varchar(100) NOT NULL DEFAULT '',
            `target_id` int(11) unsigned DEFAULT NULL,
            `target_type` varchar(100) DEFAULT NULL,
            `content` varchar(1000) NOT NULL DEFAULT '',
            `ip` varchar(64) NOT NULL DEFAULT '',
            `user_agent` varchar(255) NOT NULL DEFAULT '',
            `create_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_admin_id` (`admin_id`),
            KEY `idx_action` (`action`),
            KEY `idx_module` (`module`),
            KEY `idx_target` (`target_type`,`target_id`),
            KEY `idx_create_time` (`create_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::$tablesEnsured = true;
    }

    /**
     * 生成唯一测试标识前缀
     */
    public static function tag(string $prefix = 'TEST'): string
    {
        return $prefix . '_' . date('YmdHis') . '_' . substr(uniqid('', true), -8);
    }

    // ==================== User ====================

    /**
     * 创建测试用户
     */
    public static function createUser(array $overrides = []): UserModel
    {
        $data = array_merge([
            'mobile' => 'u_' . self::tag('M'),
            'password' => password_hash('test1234', PASSWORD_BCRYPT),
            'salt' => 'test',
            'nickname' => '测试用户',
            'invite_code' => 'U' . substr(uniqid(), -6),
            'balance' => 0.00,
            'frozen_amount' => 0.00,
            'points_balance' => 0,
            'month_used' => 0,
            'month_earned' => 0,
            'total_earned' => 0,
            'status' => 1,
        ], $overrides);

        return UserModel::create($data);
    }

    // ==================== Order ====================

    /**
     * 创建测试订单
     */
    public static function createOrder(array $overrides = []): Order
    {
        $uid = $overrides['uid'] ?? self::createUser()->id;
        $data = array_merge([
            'uid' => $uid,
            'order_number' => self::tag('ORD'),
            'product_id' => 0,
            'product_info' => [],
            'order_info' => [],
            'amount' => 0.00,
            'status' => 0,
        ], $overrides);

        return Order::create($data);
    }

    // ==================== Recharge ====================

    /**
     * 创建测试充值记录
     */
    public static function createRecharge(array $overrides = []): array
    {
        $uid = $overrides['uid'] ?? self::createUser()->id;
        $data = array_merge([
            'uid' => $uid,
            'order_number' => self::tag('REC'),
            'amount' => 100.00,
            'status' => 0,
            'payment_method' => 'test',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ], $overrides);

        $id = Db::name('recharge')->insertGetId($data);
        return Db::name('recharge')->where('id', $id)->find();
    }

    // ==================== Rebate ====================

    /**
     * 创建已发放返佣记录（含 WALLET_AGENT 余额和 ledger）
     *
     * @return array{rebate: array, agentUser: UserModel, ledgerBefore: int}
     */
    public static function createIssuedRebate(array $overrides = []): array
    {
        // 代理用户（接收返佣）
        $agentUser = self::createUser([
            'nickname' => '测试代理',
            'balance' => 500.00,
        ]);

        // 下单用户
        $buyerUser = self::createUser([
            'nickname' => '测试买家',
        ]);

        $orderNumber = $overrides['order_number'] ?? self::tag('RBT');
        $amount = (float)($overrides['amount'] ?? 50.00);
        $level = (int)($overrides['level'] ?? 1);

        // 记录操作前 ledger 数量
        $ledgerBefore = (int)Db::name('user_fund_log')
            ->where('uid', $agentUser->id)
            ->count();

        // 创建返佣记录
        $rebateId = RebateRecord::insertGetId([
            'uid' => $buyerUser->id,
            'tid' => $agentUser->id,
            'order_number' => $orderNumber,
            'amount' => $amount,
            'level' => $level,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        // 模拟返佣已发放：增加 WALLET_AGENT 余额 + 写 ledger
        $bizNo = $orderNumber . ':L' . $level . ':T' . $agentUser->id;
        Db::name('user_fund_log')->insert([
            'uid' => $agentUser->id,
            'amount' => $amount,
            'before_amount' => 500.00,
            'after_amount' => 500.00 + $amount,
            'wallet_type' => 'agent',
            'direction' => 'in',
            'request_no' => 'rebate:' . $bizNo,
            'biz_type' => 'agent_rebate',
            'biz_id' => $rebateId,
            'biz_no' => $bizNo,
            'order_number' => $orderNumber,
            'change_type' => 'rebate',
            'operator_type' => 'system',
            'operator_id' => 0,
            'status' => 'done',
            'remark' => '测试返佣发放',
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // 更新代理用户的 agent 钱包（通过 user_fund_log 记录，实际余额在独立 wallet 表或字段）
        // 注意：cz_user 表没有 agent_wallet 字段，WALLET_AGENT 通过 user_fund_log 记账
        // 测试验证时查询 user_fund_log 而非 user.balance

        $rebate = RebateRecord::where('id', $rebateId)->find()->toArray();

        return [
            'rebate' => $rebate,
            'agentUser' => $agentUser,
            'buyerUser' => $buyerUser,
            'ledgerBefore' => $ledgerBefore,
        ];
    }

    // ==================== Points Exchange ====================

    /**
     * 创建待处理积分兑换订单
     */
    public static function createPendingPointsExchange(array $overrides = []): array
    {
        $uid = $overrides['uid'] ?? self::createUser([
            'points_balance' => $overrides['user_points'] ?? 1000,
        ])->id;

        $points = (int)($overrides['points'] ?? 100);
        $data = array_merge([
            'uid' => $uid,
            'item_id' => 'item_' . substr(uniqid(), -6),
            'item_type' => 'coupon',
            'item_title' => '测试兑换商品',
            'points' => $points,
            'status' => 0,
            'remark' => '',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ], $overrides);
        unset($data['user_points']);

        $id = Db::name('points_exchange_order')->insertGetId($data);
        return Db::name('points_exchange_order')->where('id', $id)->find();
    }

    // ==================== Admin / RBAC ====================

    /**
     * 创建测试管理员并绑定指定角色
     *
     * 注意：测试库无 cz_admin 表，管理员身份通过 admin_role 表直接关联。
     * 权限测试通过 AuthorizationService::can(adminId, permissionCode) 验证。
     */
    public static function createAdminWithPermissions(array $permissionCodes, int $adminId = null): array
    {
        $adminId = $adminId ?? (900000 + random_int(1, 99999));

        // 创建测试角色
        $roleId = Db::name('role')->insertGetId([
            'code' => 'test_role_' . substr(uniqid(), -8),
            'name' => '测试角色',
            'status' => 1,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        // 绑定权限到角色
        foreach ($permissionCodes as $code) {
            $perm = Db::name('permission')->where('code', $code)->where('status', 1)->find();
            if ($perm) {
                Db::name('role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $perm['id'],
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // 绑定管理员到角色
        Db::name('admin_role')->insert([
            'admin_id' => $adminId,
            'role_id' => $roleId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // R1.6d-b.1: 清除该 admin_id 的权限缓存。
        // createAdminWithPermissions 使用随机 ID (900001-999999)，
        // AuthorizationService 使用 file cache (TTL 300s) 跨测试运行持久化。
        // 随机 ID 碰撞 + 陈旧缓存会导致 can() 返回错误权限，造成 ~6% flaky。
        try {
            (new AuthorizationService())->invalidateAdminPermissions($adminId);
        } catch (\Throwable $e) {
            // 缓存不可用时忽略，降级到 DB 查询
        }

        return [
            'admin_id' => $adminId,
            'role_id' => $roleId,
            'permissions' => $permissionCodes,
        ];
    }

    /**
     * 创建 super_admin 身份的测试管理员
     */
    public static function createSuperAdmin(int $adminId = null): array
    {
        $adminId = $adminId ?? (900000 + random_int(1, 99999));
        $superRoleId = (int)Db::name('role')->where('code', 'super_admin')->value('id');

        Db::name('admin_role')->insert([
            'admin_id' => $adminId,
            'role_id' => $superRoleId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // R1.6d-b.1: 同样清除 super admin 的权限缓存（随机 ID 碰撞风险）
        try {
            (new AuthorizationService())->invalidateAdminPermissions($adminId);
        } catch (\Throwable $e) {
            // 缓存不可用时忽略
        }

        return ['admin_id' => $adminId, 'role_id' => $superRoleId];
    }

    // ==================== Query Helpers ====================

    /**
     * 查询指定用户的 ledger 数量
     */
    public static function countLedger(int $uid, string $walletType = null): int
    {
        $query = Db::name('user_fund_log')->where('uid', $uid);
        if ($walletType) {
            $query->where('wallet_type', $walletType);
        }
        return (int)$query->count();
    }

    /**
     * 查询指定用户的 points_record 数量
     */
    public static function countPointsRecord(int $uid, string $type = null): int
    {
        $query = Db::name('points_record')->where('uid', $uid);
        if ($type) {
            $query->where('type', $type);
        }
        return (int)$query->count();
    }

    /**
     * 查询 AdminOperationLog 数量
     */
    public static function countAdminOperationLog(int $adminId = null, string $action = null): int
    {
        $query = Db::name('admin_operation_log');
        if ($adminId !== null) {
            $query->where('admin_id', $adminId);
        }
        if ($action) {
            $query->where('action', $action);
        }
        return (int)$query->count();
    }

    /**
     * 查询用户最新余额
     */
    public static function getUserBalance(int $uid): float
    {
        return (float)UserModel::where('id', $uid)->value('balance');
    }

    /**
     * 查询用户最新冻结余额
     */
    public static function getUserFrozen(int $uid): float
    {
        return (float)UserModel::where('id', $uid)->value('frozen_amount');
    }

    /**
     * 查询用户最新积分
     */
    public static function getUserPoints(int $uid): int
    {
        return (int)UserModel::where('id', $uid)->value('points_balance');
    }

    /**
     * 查询用户当月已用积分（month_used）
     */
    public static function getUserMonthUsed(int $uid): int
    {
        return (int)UserModel::where('id', $uid)->value('month_used');
    }

    /**
     * 查询积分兑换订单（按 ID）
     *
     * @return array|null 订单数组，不存在返回 null
     */
    public static function getPointsExchangeOrder(int $id): ?array
    {
        $row = Db::name('points_exchange_order')->where('id', $id)->find();
        return $row ?: null;
    }
}
