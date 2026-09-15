<?php
declare (strict_types=1);

namespace app\service;

use app\model\TransactionProduct;
use app\service\UserFundLedgerService;
use Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * TransactionProduct 金融编排 Service
 *
 * 承载 AdminApi::transaction_product_post 的挂单操作编排（operate / del / dels）：
 *   - 事务边界（startTrans / commit / rollback）由本 Service 编排层管理
 *   - 锁顺序固定：seller（UserFundLedgerService::lockUser）→ product（lock true）→ transaction_order（lock sum）
 *   - 资金释放复用 UserFundLedgerService::transferLockedUserWallet（FROZEN → BALANCE，幂等，Ledger 双记录）
 *   - 本 Service 不复制 Wallet / Ledger / row-lock / idempotency 实现，全部委托 UserFundLedgerService
 * 金融主链仅一个真实实现：UserFundLedgerService。
 */
class TransactionProductService
{
    protected UserFundLedgerService $ufs;

    public function __construct()
    {
        $this->ufs = new UserFundLedgerService();
    }

    /**
     * operate：挂单状态操作（含 status=3 关闭退款）
     */
    public function operate(array $post_info, int $operatorId = 0)
    {
        // 预读（不加锁）获取卖家 uid，用于统一锁顺序 seller → product
        $preRead = TransactionProduct::find($post_info['id']);
        if (!$preRead || !in_array((int)$preRead['status'], [1, 2], true)) {
            return show(500, 'error', '操作异常');
        }
        try {
            Db::startTrans();
            // 统一锁顺序：先锁 seller，再锁 product
            // 避免与 releaseBySeller(order→seller→product) 形成 product↔seller 循环等待死锁
            $seller = $this->ufs->lockUser((int)$preRead['uid']);
            $TransactionProduct_info = TransactionProduct::where('id', $post_info['id'])->lock(true)->find();
            if (!$TransactionProduct_info || !in_array((int)$TransactionProduct_info['status'], [1, 2], true)) {
                Db::rollback();
                return show(500, 'error', '操作异常');
            }

            // 关闭挂单(status=3)前必须检查活跃订单：存在待汇款(0)或已汇款(1)订单时禁止关闭
            if ((int)$post_info['status'] === 3) {
                $activeCommitted = (float)Db::name('transaction_order')
                    ->where('pid', (int)$TransactionProduct_info['id'])
                    ->whereIn('status', [0, 1])
                    ->lock(true)
                    ->sum('pay_amount');
                if ($activeCommitted > 0.005) {
                    Db::rollback();
                    return show(500, 'error', '该挂单存在进行中的交易订单（占用 ' . $activeCommitted . ' USDT），无法关闭，请先处理订单');
                }
            }

            $TransactionProduct_info->status = $post_info['status'];
            $TransactionProduct_info->save();

            if ($post_info['status'] == 3) {
                $refundAmount = (float)($TransactionProduct_info['sell_account'] ?? 0);
                if ($refundAmount > 0) {
                    $this->releaseTransactionListingByAdmin($seller, $TransactionProduct_info, $refundAmount, 0.0, $operatorId);
                }
            }
            Db::commit();
            return show(200, 'success', '操作成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin transaction_product_post operate error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
            return show(500, 'error', '操作异常');
        }
    }

    /**
     * del：删除单个挂单（先退还剩余冻结资金）
     */
    public function del(array $post_info, int $operatorId = 0)
    {
        // 预读（不加锁）获取卖家 uid，用于统一锁顺序 seller → product
        $preRead = TransactionProduct::find($post_info['id']);
        if (!$preRead) {
            return show(404, 'error', '挂单不存在');
        }
        try {
            Db::startTrans();
            // 统一锁顺序：先锁 seller，再锁 product
            $seller = $this->ufs->lockUser((int)$preRead['uid']);
            $TransactionProduct_info = TransactionProduct::where('id', $post_info['id'])->lock(true)->find();
            if (!$TransactionProduct_info) {
                Db::rollback();
                return show(404, 'error', '挂单不存在');
            }

            // 检查活跃订单：存在待汇款(0)或已汇款(1)订单时禁止删除
            $activeCommitted = (float)Db::name('transaction_order')
                ->where('pid', (int)$TransactionProduct_info['id'])
                ->whereIn('status', [0, 1])
                ->lock(true)
                ->sum('pay_amount');
            if ($activeCommitted > 0.005) {
                Db::rollback();
                return show(500, 'error', '该挂单存在进行中的交易订单（占用 ' . $activeCommitted . ' USDT），无法删除');
            }

            // 退还剩余冻结资金（seller 已锁定）
            $refundAmount = (float)($TransactionProduct_info['sell_account'] ?? 0);
            if ($refundAmount > 0.005) {
                $this->releaseTransactionListingByAdmin($seller, $TransactionProduct_info, $refundAmount, 0.0, $operatorId);
            }

            TransactionProduct::destroy($post_info['id']);
            Db::commit();
            return show(200, 'success', '删除成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin transaction_product_post del error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
            return show(500, 'error', '删除失败');
        }
    }

    /**
     * dels：批量删除挂单（逐条退还剩余冻结资金）
     */
    public function dels(array $post_info, int $operatorId = 0)
    {
        try {
            Db::startTrans();
            $ids = is_array($post_info['ids'] ?? null) ? $post_info['ids'] : [];
            if (empty($ids)) {
                Db::rollback();
                return show(500, 'error', '请选择要删除的挂单');
            }

            // 预读所有挂单（不加锁）获取卖家 uid，用于统一锁顺序 seller → product
            $preReads = TransactionProduct::where('id', 'in', array_map('intval', $ids))->select();
            if (count($preReads) !== count($ids)) {
                throw new Exception('部分挂单不存在');
            }

            foreach ($preReads as $preRead) {
                // 统一锁顺序：先锁 seller，再锁 product
                $seller = $this->ufs->lockUser((int)$preRead['uid']);
                $product = TransactionProduct::where('id', (int)$preRead['id'])->lock(true)->find();
                if (!$product) {
                    throw new Exception('挂单不存在: ' . (int)$preRead['id']);
                }

                // 检查活跃订单
                $activeCommitted = (float)Db::name('transaction_order')
                    ->where('pid', (int)$product['id'])
                    ->whereIn('status', [0, 1])
                    ->lock(true)
                    ->sum('pay_amount');
                if ($activeCommitted > 0.005) {
                    throw new Exception('挂单 #' . (int)$product['id'] . ' 存在进行中的交易订单（占用 ' . $activeCommitted . ' USDT），无法删除');
                }

                // 退还剩余冻结资金（seller 已锁定）
                $refundAmount = (float)($product['sell_account'] ?? 0);
                if ($refundAmount > 0.005) {
                    $this->releaseTransactionListingByAdmin($seller, $product, $refundAmount, 0.0, $operatorId);
                }

                TransactionProduct::destroy((int)$product['id']);
            }

            Db::commit();
            return show(200, 'success', '批量删除成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin transaction_product_post batch_del error: ' . $e->getMessage(), ['ids' => $post_info['ids'] ?? []]);
            return show(500, 'error', $e->getMessage() ?: '批量删除失败');
        }
    }

    /**
     * 释放挂单剩余冻结资金（FROZEN → BALANCE，幂等，Ledger 双记录）
     * 金额唯一来源：DB 已锁定记录的 sell_account
     */
    private function releaseTransactionListingByAdmin($user, $listing, float $amount, ?float $targetSellAccount = 0.0, int $operatorId = 0): array
    {
        $bizNo = $this->transactionListingBizNo($listing);

        return $this->ufs->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_FROZEN,
            UserFundLedgerService::WALLET_BALANCE,
            round($amount, 2),
            [
                'biz_type' => 'transaction_listing',
                'biz_id' => (int)($listing['id'] ?? 0),
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'out_change_type' => 'transaction_listing_release',
                'in_change_type' => 'transaction_listing_release',
                'operator_type' => 'admin',
                'operator_id' => $operatorId,
                'status' => 'done',
                'request_no' => 'transaction_listing_release:' . $bizNo . ':target:' . number_format((float)$targetSellAccount, 2, '.', ''),
                'remark' => 'transaction listing release',
                'idempotent' => true,
                'extra' => [
                    'source' => 'admin_transaction_listing_release',
                    'target_sell_account' => $targetSellAccount,
                ],
            ]
        );
    }

    /**
     * 挂单业务单号（与原 AdminApi 实现一致，request_no 生成唯一 source of truth）
     */
    private function transactionListingBizNo($listing): string
    {
        return 'listing:' . (int)($listing['id'] ?? 0);
    }
}
