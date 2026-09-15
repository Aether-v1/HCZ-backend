<?php
namespace app\service;

use app\model\User as UserModel;
use app\model\Recharge;
use app\model\Withdrawal;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\UserFundLog;
use app\model\Order;
use think\facade\Log;

class UserService
{
    /**
     * B08: 用户行级锁（SSOT）。OLD AdminApi::directLockUser 语义等价。
     * 唯一实现；AdminApi 旧 helper 仅作兼容薄委托，admin\User 直用本方法。
     *
     * @param int $uid 用户ID
     * @return UserModel|null 加锁后的用户模型，不存在返回 null
     */
    public function lockById(int $uid)
    {
        return UserModel::where('id', $uid)->lock(true)->find();
    }

    /**
     * B08: 删除前待处理业务检查（只读风险闸）。OLD AdminApi::directCheckUserPendingBusiness 语义等价，检查范围与顺序不变。
     * 纯只读 count/字段查询：无锁、无事务、无写、无副作用。
     *
     * 检查顺序（不得调整）：
     *   Recharge(status IN 0,1) → Withdrawal(status=0) → TransactionOrder 买家(status IN 0,1)
     *   → TransactionOrder 卖家(sell_uid, status IN 0,1) → TransactionProduct(status IN 1,2 且 sell_account>0)
     *   → User 钱包字段(balance/frozen_amount/agent_wallet > 0.005)
     *
     * @param int $uid 用户ID
     * @return array ['ok'=>bool, 'message'=>string]
     */
    public function assertNoPendingBusiness(int $uid): array
    {
        if ($uid <= 0) {
            return ['ok' => false, 'message' => '用户参数错误'];
        }
        // 充值订单：status=0 待支付（链上/网关）、status=1 待审核（手动充值）
        $pendingRecharge = Recharge::where('uid', $uid)->whereIn('status', [0, 1])->count();
        if ($pendingRecharge > 0) {
            return ['ok' => false, 'message' => '存在待处理充值订单（' . $pendingRecharge . '笔）'];
        }
        // 提现订单：status=0 待审核
        $pendingWithdrawal = Withdrawal::where('uid', $uid)->where('status', 0)->count();
        if ($pendingWithdrawal > 0) {
            return ['ok' => false, 'message' => '存在待审核提现订单（' . $pendingWithdrawal . '笔）'];
        }
        // C2C 订单：作为买家 status IN (0,1)
        $pendingBuyOrder = TransactionOrder::where('uid', $uid)->whereIn('status', [TransactionOrder::STATUS_PENDING, TransactionOrder::STATUS_REMITTED])->count();
        if ($pendingBuyOrder > 0) {
            return ['ok' => false, 'message' => '存在进行中的C2C买入订单（' . $pendingBuyOrder . '笔）'];
        }
        // C2C 订单：作为卖家 status IN (0,1)
        $pendingSellOrder = TransactionOrder::where('sell_uid', $uid)->whereIn('status', [TransactionOrder::STATUS_PENDING, TransactionOrder::STATUS_REMITTED])->count();
        if ($pendingSellOrder > 0) {
            return ['ok' => false, 'message' => '存在进行中的C2C卖出订单（' . $pendingSellOrder . '笔）'];
        }
        // 交易挂单：status IN (1,2) 且有剩余可售量（冻结资金未释放）
        $pendingListing = TransactionProduct::where('uid', $uid)->whereIn('status', [1, 2])->where('sell_account', '>', 0)->count();
        if ($pendingListing > 0) {
            return ['ok' => false, 'message' => '存在进行中的交易挂单（' . $pendingListing . '个）'];
        }
        // 钱包余额检查：余额/冻结/代理钱包有余额时禁止删除
        $user = UserModel::where('id', $uid)->field('id,balance,frozen_amount,agent_wallet,mobile')->find();
        if ($user) {
            $balance = round((float)($user['balance'] ?? 0), 2);
            $frozen = round((float)($user['frozen_amount'] ?? 0), 2);
            $agentWallet = round((float)($user['agent_wallet'] ?? 0), 2);
            if ($balance > 0.005 || $frozen > 0.005 || $agentWallet > 0.005) {
                return ['ok' => false, 'message' => '用户钱包存在余额（余额:' . $balance . ' 冻结:' . $frozen . ' 代理钱包:' . $agentWallet . '）'];
            }
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * R1.6e: 删除前财务历史检查（只读风险闸）。
     * 与 assertNoPendingBusiness 语义不同：pending guard 检查活跃业务，
     * 本方法检查任意财务/业务历史记录是否存在。
     * 只要以下任意表存在该用户记录，即禁止物理删除（防止审计链 orphan）：
     *   cz_user_fund_log（核心资金账本，最高优先级）
     *   cz_recharge（任意状态）
     *   cz_withdrawal（任意状态）
     *   cz_order（任意状态）
     *   cz_transaction_order（作为买家 uid 或卖家 sell_uid，任意状态）
     *
     * 纯只读 count 查询：无锁、无事务、无写、无副作用。
     * 应在事务+行锁内重新调用以消除 TOCTOU。
     *
     * @param int $uid 用户ID
     * @return array ['ok'=>bool, 'message'=>string]
     */
    public function assertNoFinancialHistory(int $uid): array
    {
        if ($uid <= 0) {
            return ['ok' => false, 'message' => '用户参数错误'];
        }
        // 核心资金账本：任意流水存在即禁止删除
        $fundLogCount = UserFundLog::where('uid', $uid)->count();
        if ($fundLogCount > 0) {
            return ['ok' => false, 'message' => '存在资金流水记录（' . $fundLogCount . '条），禁止物理删除，请改用禁用账户'];
        }
        // 充值历史：任意状态（pending/submitted/paid/expired）
        $rechargeCount = Recharge::where('uid', $uid)->count();
        if ($rechargeCount > 0) {
            return ['ok' => false, 'message' => '存在充值历史记录（' . $rechargeCount . '笔），禁止物理删除，请改用禁用账户'];
        }
        // 提现历史：任意状态（pending/approved/rejected）
        $withdrawalCount = Withdrawal::where('uid', $uid)->count();
        if ($withdrawalCount > 0) {
            return ['ok' => false, 'message' => '存在提现历史记录（' . $withdrawalCount . '笔），禁止物理删除，请改用禁用账户'];
        }
        // 业务订单：任意状态
        $orderCount = Order::where('uid', $uid)->count();
        if ($orderCount > 0) {
            return ['ok' => false, 'message' => '存在业务订单记录（' . $orderCount . '笔），禁止物理删除，请改用禁用账户'];
        }
        // C2C 交易：作为买家或卖家，任意状态
        $txOrderCount = TransactionOrder::where('uid', $uid)
            ->whereOr('sell_uid', $uid)
            ->count();
        if ($txOrderCount > 0) {
            return ['ok' => false, 'message' => '存在C2C交易记录（' . $txOrderCount . '笔），禁止物理删除，请改用禁用账户'];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * 获取用户余额信息
     * @param int $userId 用户ID
     * @return array|null 余额信息数组
     */
    public function getBalance($userId)
    {
        try {
            // 根据实际业务逻辑查询用户余额
            $user = UserModel::where('id', $userId)->find();
            
            if (!$user) {
                Log::error('用户不存在', ['user_id' => $userId]);
                return null;
            }
            
            // 假设用户表中有这些字段，根据实际表结构修改
            return [
                'available' => $user->balance ?? 0,        // 可用余额
                'frozen_amount' => $user->frozen_amount ?? 0,    // 冻结余额
                'points' => $user->points_balance ?? 0             // 积分
            ];
        } catch (\Exception $e) {
            Log::error('获取用户余额失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * 根据用户ID获取用户信息
     * @param int $userId 用户ID
     * @return array|null 用户信息
     */
    public function getUserInfo($userId)
    {
        try {
            $user = UserModel::where('id', $userId)->find();
            
            if (!$user) {
                Log::error('用户不存在', ['user_id' => $userId]);
                return null;
            }
            
            return $user->toArray();
        } catch (\Exception $e) {
            Log::error('获取用户信息失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
