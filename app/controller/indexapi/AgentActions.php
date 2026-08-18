<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\RebateRecord;
use app\model\Substation;
use app\model\User as UserModel;
use app\service\UserFundLedgerService;
use think\facade\Db;

trait AgentActions
{
    protected function handleAgencyCenterPost(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'confirm_payment':
                try {
                    $result = Db::transaction(function () {
                        $user_info = UserModel::where('id', $this->user_info['id'])->lock(true)->find();
                        if (!$user_info) {
                            throw new \RuntimeException('用户不存在');
                        }

                        if ((int) ($user_info['agent_status'] ?? 0) === 1) {
                            return [
                                'already' => 1,
                                'message' => '已开通代理，无需重复提交',
                            ];
                        }

                        $substationStatus = (int)Substation::where('uid', (int)($user_info['id'] ?? 0))->value('status');
                        if (in_array($substationStatus, [1, 2, 3, 5], true)) {
                            $user_info->agent_status = 1;
                            if ($user_info->save() === false) {
                                throw new \RuntimeException('VIP状态同步失败');
                            }
                            return [
                                'already' => 1,
                                'message' => '已开通SVIP，自动包含VIP功能，无需重复开通',
                            ];
                        }

                        $agentMoney = (float) (getConfig('agent_money') ?? 0);
                        if ((float) ($user_info['balance'] ?? 0) < $agentMoney) {
                            throw new \RuntimeException('可用余额已不足');
                        }

                        $bizNo = 'agent_activate:' . (int)($user_info['id'] ?? 0) . ':' . date('YmdHis') . ':' . random_int(1000, 9999);

                        (new UserFundLedgerService())->changeLockedUserWallet(
                            $user_info,
                            UserFundLedgerService::WALLET_BALANCE,
                            -1 * round($agentMoney, 2),
                            [
                                'biz_type' => 'agent_activate',
                                'biz_id' => (int)($user_info['id'] ?? 0),
                                'biz_no' => $bizNo,
                                'order_number' => $bizNo,
                                'change_type' => 'agent_activate_deduct',
                                'operator_type' => 'user',
                                'operator_id' => (int)($user_info['id'] ?? 0),
                                'status' => 'done',
                                'request_no' => 'agent_activate_deduct:' . $bizNo,
                                'remark' => 'agent activate deduct',
                                'idempotent' => true,
                                'extra' => [
                                    'source' => 'handleAgencyCenterPost_confirm_payment',
                                    'agent_money' => round($agentMoney, 2),
                                ],
                            ]
                        );
                        $user_info->agent_status = 1;
                        if ($user_info->save() === false) {
                            throw new \RuntimeException('代理开通失败');
                        }

                        return [
                            'already' => 0,
                            'message' => '开通成功',
                        ];
                    });
                } catch (\Throwable $e) {
                    return show(500, 'error', $e->getMessage());
                }

                return show(200, 'success', (string)($result['message'] ?? '开通成功'));

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleApiInviteInfo()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) return show(500, 'error', '用户不存在');

        $inviteInfo = $this->directBuildInviteInfo($user);
        return show(200, 'success', '查询成功', [
            'invite_code' => (string)($inviteInfo['invite_code'] ?? ''),
            'invite_link' => (string)($inviteInfo['invite_link'] ?? ''),
            'invite_url' => (string)($inviteInfo['invite_link'] ?? ''),
            'invite_count' => (int)($inviteInfo['invite_count'] ?? 0),
            'poster' => (string)($inviteInfo['poster'] ?? ''),
            'qr_code' => (string)($inviteInfo['qr_code'] ?? ''),
        ]);
    }

    protected function handleApiAgentSummary()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) return show(500, 'error', '用户不存在');
        $configMap = $this->buildSiteConfigMap();
        $rebateToday = (float)RebateRecord::where('tid', $user['id'])->whereDay('create_time')->sum('amount');
        $rebateTotal = (float)RebateRecord::where('tid', $user['id'])->sum('amount');

        return show(200, 'success', '查询成功', [
            'nickname' => (string)($user['nickname'] ?: $user['surname'] ?: $user['mobile']),
            'avatar' => (string)($user['avatar'] ?? getConfig('user_avatar_image') ?? ''),
            'agent_status' => (int)($user['agent_status'] ?? 0),
            'agent_level' => 1,
            'rebate_jr' => $this->directMoney($rebateToday),
            'rebate_s' => $this->directMoney($rebateTotal),
            'agent_wallet' => $this->directMoney($user['agent_wallet'] ?? 0),
            'agent_intro' => $this->normalizeFrontendConfigText((string)($configMap['agent_jieshao'] ?? '')),
            'agent_money' => $this->directMoney($configMap['agent_money'] ?? 0),
        ]);
    }

    protected function handleApiAgentUsers()
    {
        $level = max(1, min(10, (int)$this->request->get('level', $this->request->get('type', 1))));
        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(50, (int)$this->request->get('pageSize', 20)));
        $all = $this->directAgentListByLevel((int)$this->user_info['id'], $level);
        $total = count($all);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($all, $offset, $pageSize);
        return show(200, 'success', '查询成功', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
            'level' => $level,
        ]);
    }

    protected function handleApiAgentActivate()
    {
        return $this->handleAgencyCenterPost('confirm_payment');
    }

    protected function handleApiAgentWalletTransfer()
    {
        $amount = $this->request->post('amount', '');
        if (!function_exists('agentWalletToBalance')) {
            require_once app_path() . '/common.php';
        }
        $amount = $amount === '' ? null : (float)$amount;
        $result = agentWalletToBalance((int)$this->user_info['id'], $amount);
        $ok = (int)($result['status'] ?? 0) === 1;
        return show($ok ? 200 : 500, $ok ? 'success' : 'error', (string)($result['msg'] ?? ($ok ? '操作成功' : '操作失败')), [
            'amount' => $amount,
        ], $ok ? 200 : 500);
    }
}
