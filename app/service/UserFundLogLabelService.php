<?php
declare (strict_types=1);

namespace app\service;

class UserFundLogLabelService
{
    private const DEFAULT_LABEL = '资金变动';

    private const BASE_LABELS = [
        'recharge_paid' => '余额充值到账',
        'recharge_manual_paid' => '后台充值到账',
        'product_order_freeze' => '商品订单冻结',
        'product_order_cancel_refund' => '订单取消退款',
        'product_order_deduct' => '商品订单扣款',
        'product_order_partial_refund' => '订单部分退款',
        'withdraw_freeze_out' => '提现申请扣减余额',
        'withdraw_freeze_in' => '提现冻结',
        'withdraw_deduct' => '提现扣除',
        'withdraw_reject_refund' => '提现驳回退回余额',
        'transaction_listing_freeze' => '交易挂单冻结',
        'transaction_listing_release' => '交易挂单解冻',
        'transaction_deduct' => '交易挂单扣除',
        'transaction_buyer_income' => '交易买入到账',
        'agent_rebate_income' => '代理返佣到账',
        'agent_activate_deduct' => '代理开通扣费',
        'agent_wallet_transfer_in' => '佣金钱包转入余额',
        'agent_wallet_transfer_out' => '佣金钱包转出',
        'substation_open_fee' => '分站开通扣费',
        'substation_wallet_to_balance' => '分站钱包转入余额',
        'admin_balance_add' => '后台人工加款',
        'admin_balance_subtract' => '后台人工扣款',
    ];

    public function baseLabel(string $changeType): string
    {
        $changeType = trim($changeType);
        if ($changeType === '') {
            return self::DEFAULT_LABEL;
        }

        return self::BASE_LABELS[$changeType] ?? self::DEFAULT_LABEL;
    }

    public function displayLabel(string $changeType, string $walletType = ''): string
    {
        $changeType = trim($changeType);
        $walletType = trim($walletType);

        if ($walletType === UserFundLedgerService::WALLET_FROZEN) {
            switch ($changeType) {
                case 'product_order_cancel_refund':
                    return '订单取消解冻';
                case 'product_order_partial_refund':
                    return '订单部分退款解冻';
                case 'withdraw_reject_refund':
                    return '提现驳回解冻';
            }
        }

        return $this->baseLabel($changeType);
    }
}
