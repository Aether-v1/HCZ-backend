<?php
declare (strict_types=1);

namespace app\controller;

use app\common\service\SubstationPriceService;
use app\common\service\SubstationService;
use app\common\service\SubstationSettlementService;
use app\middleware\UserAuth;
use app\model\Order;
use app\model\Product;
use app\model\Substation;
use app\model\SubstationIncomeLog;
use app\model\SubstationProfile;
use app\model\SubstationProfileAudit;
use app\model\User as UserModel;
use app\model\UserBalanceLog;
use app\service\ActionRateLimiter;
use app\service\UserFundLedgerService;
use think\App;
use think\Exception;
use think\facade\Db;
use think\facade\Log;
use think\Request;

class SubstationApi
{
    protected Request $request;
    protected App $app;
    protected mixed $user_info;
    protected array $middleware = [UserAuth::class];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->user_info = $this->request->session('user');
    }

    private function logApiException(string $scene, \Throwable $e, array $context = []): void
    {
        $admin = $this->request->session('admin');
        $adminId = is_array($admin) ? (int)($admin['id'] ?? 0) : 0;

        $logContext = array_merge([
            'scene' => $scene,
            'message' => $this->sanitizeExceptionLogValue($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $this->request->url(),
            'user_id' => (int)($this->user_info['id'] ?? 0) ?: null,
            'admin_id' => $adminId > 0 ? $adminId : null,
        ], $this->sanitizeExceptionLogContext($context));

        Log::error('substation api exception', $logContext);
    }

    private function sanitizeExceptionLogContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $sanitized[$key] = $this->sanitizeExceptionLogEntry((string)$key, $value);
        }

        return $sanitized;
    }

    private function sanitizeExceptionLogEntry(string $key, $value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $childLabel = is_int($childKey) ? $key . '_' . $childKey : (string)$childKey;
                $sanitized[$childKey] = $this->sanitizeExceptionLogEntry($childLabel, $childValue);
            }

            return $sanitized;
        }

        if (!is_scalar($value) && $value !== null) {
            return '[' . gettype($value) . ']';
        }

        return $this->sanitizeExceptionLogValue($value, $key);
    }

    private function sanitizeExceptionLogValue($value, string $key = ''): string
    {
        $string = trim((string)$value);
        if ($string === '') {
            return $string;
        }

        $lowerKey = strtolower($key);
        if ($lowerKey !== '' && preg_match('/token|secret|key|sign/', $lowerKey)) {
            return '[masked]';
        }

        if (preg_match('/^1\d{10}$/', $string)) {
            return substr($string, 0, 3) . '****' . substr($string, -4);
        }

        if (preg_match('/^(T|0x)[A-Za-z0-9]{12,}$/', $string)) {
            return substr($string, 0, 6) . '...' . substr($string, -4);
        }

        return $string;
    }

    private function resolveClientErrorMessage(\Throwable $e, string $fallback = '操作失败，请联系客服'): string
    {
        if ($e instanceof Exception) {
            $message = trim((string)$e->getMessage());
            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    public function apply()
    {
        $payload = $this->request->post();
        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $payload = $json;
            }
        }

        $uid = (int)($this->user_info['id'] ?? 0);

        try {
            $result = Db::transaction(function () use ($uid, $payload) {
                $user = UserModel::where('id', $uid)->lock(true)->find();
                if (!$user) {
                    throw new Exception('用户不存在');
                }

                $substation = SubstationService::getOrCreateByUid($uid);
                $substation = Substation::where('id', (int)$substation['id'])->lock(true)->find();
                if (!$substation) {
                    throw new Exception('分站不存在');
                }
                $substationStatus = (int)($substation['status'] ?? 0);
                if ($substationStatus === 2) {
                    throw new Exception('分站已开通，无需重复申请');
                }
                if (!in_array($substationStatus, [5, 3], true)) {
                    throw new Exception('请先支付开通SVIP');
                }

                $pending = SubstationProfileAudit::where('substation_id', (int)$substation['id'])->where('status', 0)->lock(true)->find();
                if ($pending) {
                    throw new Exception('当前已有待审核申请，请勿重复提交');
                }

                $subdomain = SubstationService::normalizeSubdomain((string)($payload['subdomain'] ?? ''));
                $siteName = trim((string)($payload['site_name'] ?? ''));
                if ($siteName === '') {
                    throw new Exception('网站名不能为空');
                }
                $fullDomain = SubstationService::buildFullDomain($subdomain, (string)($payload['base_domain'] ?? ''));
                SubstationService::assertDomainAvailable($fullDomain, (int)$substation['id']);

                $audit = SubstationProfileAudit::create([
                    'substation_id' => (int)$substation['id'],
                    'uid' => $uid,
                    'audit_type' => 1,
                    'subdomain' => $subdomain,
                    'full_domain' => $fullDomain,
                    'site_name' => $siteName,
                    'notice' => (string)($payload['notice'] ?? ''),
                    'logo' => (string)($payload['logo'] ?? ''),
                    'status' => 0,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                if (!$audit) {
                    throw new Exception('提交审核失败');
                }

                $substation->status = 1;
                $substation->reject_reason = null;
                $substation->update_time = date('Y-m-d H:i:s');
                if ($substation->save() === false) {
                    throw new Exception('分站状态更新失败');
                }

                return [
                    'audit_id' => (int)$audit['id'],
                ];
            });

            return show(200, 'success', '资料已提交，等待审核', $result);
        } catch (\Throwable $e) {
            $this->logApiException('substation_apply', $e, [
                'subdomain' => (string)($payload['subdomain'] ?? ''),
            ]);
            return show(500, 'error', $this->resolveClientErrorMessage($e));
        }
    }

    public function myStatus()
    {
        $substation = Substation::where('uid', (int)$this->user_info['id'])->find();
        $pending = null;
        $domainRejectReason = '';
        if ($substation) {
            $pending = SubstationProfileAudit::where('substation_id', (int)$substation['id'])->where('status', 0)->find();

            // 仅返回域名前缀审核链路的最近驳回原因，避免混用分站级 reject_reason。
            $latestRejectedDomainAudit = SubstationProfileAudit::where('substation_id', (int)$substation['id'])
                ->whereIn('audit_type', [1, 2])
                ->where('status', 2)
                ->order('id', 'desc')
                ->find();
            if ($latestRejectedDomainAudit) {
                $domainRejectReason = trim((string)($latestRejectedDomainAudit['reject_reason'] ?? ''));
            }
        }
        return show(200, 'success', '查询成功', [
            'substation_id' => (int)($substation['id'] ?? 0),
            'status' => (int)($substation['status'] ?? 0),
            'paid_open' => in_array((int)($substation['status'] ?? 0), [1, 2, 3, 5], true) ? 1 : 0,
            'reject_reason' => (string)($substation['reject_reason'] ?? ''),
            'domain_reject_reason' => $domainRejectReason,
            'has_pending_audit' => $pending ? 1 : 0,
            'wallet_balance' => round((float)($substation['wallet_balance'] ?? 0), 2),
            'wallet_total_income' => round((float)($substation['wallet_total_income'] ?? 0), 2),
            'wallet_total_transferred' => round((float)($substation['wallet_total_transferred'] ?? 0), 2),
            'open_price' => round((float)(getConfig('substation_open_price') ?? 0), 2),
            'open_intro' => (string)(getConfig('substation_open_intro') ?? ''),
            'base_domain' => SubstationService::getBaseDomain(),
        ]);
    }

    public function openPay()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        try {
            $result = Db::transaction(function () use ($uid) {
                $user = UserModel::where('id', $uid)->lock(true)->find();
                if (!$user) {
                    throw new Exception('用户不存在');
                }

                $substation = SubstationService::getOrCreateByUid($uid);
                $substation = Substation::where('id', (int)$substation['id'])->lock(true)->find();
                if (!$substation) {
                    throw new Exception('分站不存在');
                }

                $status = (int)($substation['status'] ?? 0);
                if ($status === 2) {
                    throw new Exception('分站已开通，无需重复支付');
                }
                if ($status === 4) {
                    throw new Exception('分站已被禁用，请联系客服');
                }
                if (in_array($status, [1, 3, 5], true)) {
                    if ((int)($user['agent_status'] ?? 0) !== 1) {
                        $user->agent_status = 1;
                        $user->save();
                    }
                    return [
                        'paid_open' => 1,
                        'status' => $status,
                        'open_price' => round((float)(getConfig('substation_open_price') ?? 0), 2),
                        'balance' => round((float)($user['balance'] ?? 0), 2),
                    ];
                }

                $openPrice = round((float)(getConfig('substation_open_price') ?? 0), 2);
                if ($openPrice < 0) {
                    $openPrice = 0;
                }

                if ($openPrice > 0 && (float)($user['balance'] ?? 0) < $openPrice) {
                    throw new Exception('余额不足，请先充值');
                }

                if ($openPrice > 0) {
                    $bizNo = 'substation_activate:' . (int)($substation['id'] ?? 0) . ':' . date('YmdHis') . ':' . random_int(1000, 9999);
                    (new UserFundLedgerService())->changeLockedUserWallet(
                        $user,
                        UserFundLedgerService::WALLET_BALANCE,
                        -1 * $openPrice,
                        [
                            'biz_type' => 'substation_activate',
                            'biz_id' => (int)($substation['id'] ?? 0),
                            'biz_no' => $bizNo,
                            'order_number' => $bizNo,
                            'change_type' => 'substation_activate_deduct',
                            'operator_type' => 'user',
                            'operator_id' => $uid,
                            'status' => 'done',
                            'request_no' => 'substation_activate_deduct:' . $bizNo,
                            'remark' => 'substation activate deduct',
                            'idempotent' => true,
                            'extra' => [
                                'source' => 'substation_open_pay',
                                'substation_open_price' => $openPrice,
                            ],
                        ]
                    );
                }

                $substation->status = 5;
                $substation->reject_reason = null;
                $substation->update_time = date('Y-m-d H:i:s');
                if ($substation->save() === false) {
                    throw new Exception('分站开通状态更新失败');
                }

                // 业务规则：SVIP 必然包含 VIP 功能。
                if ((int)($user['agent_status'] ?? 0) !== 1) {
                    $user->agent_status = 1;
                    if ($user->save() === false) {
                        throw new Exception('VIP状态同步失败');
                    }
                }

                $freshUser = UserModel::where('id', $uid)->find();
                return [
                    'paid_open' => 1,
                    'status' => 5,
                    'open_price' => $openPrice,
                    'balance' => round((float)($freshUser['balance'] ?? $user['balance'] ?? 0), 2),
                ];
            });

            return show(200, 'success', '支付开通成功，请提交分站资料', $result);
        } catch (\Throwable $e) {
            $this->logApiException('substation_open_pay', $e);
            return show(500, 'error', $e->getMessage() ?: '操作失败，请联系客服');
        }
    }

    public function myProfile()
    {
        $substation = Substation::where('uid', (int)$this->user_info['id'])->find();
        if (!$substation) {
            return show(200, 'success', '查询成功', null);
        }
        $profile = SubstationProfile::where('substation_id', (int)$substation['id'])->find();
        return show(200, 'success', '查询成功', $profile);
    }

    public function submitProfileAudit()
    {
        try {
            $payload = $this->request->post();
            $uid = (int)($this->user_info['id'] ?? 0);
            $result = Db::transaction(function () use ($uid, $payload) {
                $substation = Substation::where('uid', $uid)->lock(true)->find();
                if (!$substation || (int)($substation['id'] ?? 0) <= 0) {
                    throw new Exception('分站不存在');
                }
                if ((int)($substation['status'] ?? 0) !== 2) {
                    throw new Exception('分站未开通，暂不可修改资料');
                }

                $profile = SubstationProfile::where('substation_id', (int)$substation['id'])->lock(true)->find();
                if (!$profile) {
                    throw new Exception('分站资料不存在');
                }

                $siteName = trim((string)($payload['site_name'] ?? ''));
                if ($siteName === '') {
                    throw new Exception('网站名不能为空');
                }

                $currentSubdomain = SubstationService::normalizeSubdomain((string)($profile['subdomain'] ?? ''));
                $nextSubdomain = SubstationService::normalizeSubdomain((string)($payload['subdomain'] ?? $currentSubdomain));
                $subdomainChanged = $nextSubdomain !== $currentSubdomain;

                if ($subdomainChanged) {
                    $pending = SubstationProfileAudit::where('substation_id', (int)$substation['id'])->where('status', 0)->lock(true)->find();
                    if ($pending) {
                        throw new Exception('当前已有待审核申请，请勿重复提交');
                    }

                    $fullDomain = SubstationService::buildFullDomain($nextSubdomain, (string)($payload['base_domain'] ?? ''));
                    SubstationService::assertDomainAvailable($fullDomain, (int)$substation['id']);

                    $audit = SubstationProfileAudit::create([
                        'substation_id' => (int)$substation['id'],
                        'uid' => $uid,
                        'audit_type' => 2,
                        'subdomain' => $nextSubdomain,
                        'full_domain' => $fullDomain,
                        'site_name' => $siteName,
                        'notice' => (string)($payload['notice'] ?? ''),
                        'logo' => (string)($payload['logo'] ?? ''),
                        'status' => 0,
                        'create_time' => date('Y-m-d H:i:s'),
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                    if (!$audit) {
                        throw new Exception('提交审核失败');
                    }

                    return [
                        'mode' => 'audit',
                        'audit_id' => (int)$audit['id'],
                    ];
                }

                $profile->site_name = $siteName;
                $profile->notice = (string)($payload['notice'] ?? '');
                $profile->logo = (string)($payload['logo'] ?? '');
                $profile->update_time = date('Y-m-d H:i:s');
                if ($profile->save() === false) {
                    throw new Exception('保存失败');
                }

                return [
                    'mode' => 'direct',
                    'audit_id' => 0,
                ];
            });

            if (($result['mode'] ?? '') === 'audit') {
                return show(200, 'success', '二级域名前缀修改已提交审核', ['audit_id' => (int)($result['audit_id'] ?? 0)]);
            }
            return show(200, 'success', '资料已保存', ['audit_id' => 0]);
        } catch (\Throwable $e) {
			$this->logApiException('substation_submit_profile_audit', $e);
			return show(500, 'error', $this->resolveClientErrorMessage($e));
        }
    }

    public function productTierList()
    {
        $productId = 0;
        try {
            $productId = (int)$this->request->get('product_id', 0);
            $substation = SubstationService::getOrCreateByUid((int)$this->user_info['id']);
            $tiers = SubstationPriceService::listTiersForProduct((int)$this->user_info['id'], (int)$substation['id'], $productId);
            return show(200, 'success', '查询成功', ['product_id' => $productId, 'tiers' => $tiers]);
        } catch (\Throwable $e) {
			$this->logApiException('substation_product_tier_list', $e, [
				'product_id' => $productId,
			]);
			return show(500, 'error', '系统繁忙，请稍后再试');
        }
    }

    public function productCatalog()
    {
        try {
            $substation = SubstationService::getOrCreateByUid((int)$this->user_info['id']);
            $rows = Product::where('status', 1)->order('sort', 'asc')->order('id', 'desc')->select();
            $list = [];
            foreach ($rows as $product) {
                $tiers = SubstationPriceService::listTiersForProduct((int)$this->user_info['id'], (int)$substation['id'], (int)$product['id']);
                if (!$tiers) {
                    continue;
                }
                $platformDescribe = trim((string)($product['describe'] ?? ''));
                $list[] = [
                    'id' => (int)($product['id'] ?? 0),
                    'name' => (string)($product['name'] ?? ''),
                    'home_name' => (string)($product['home_name'] ?? $product['name'] ?? ''),
                    'describe' => $platformDescribe,
                    'substation_describe' => SubstationPriceService::resolveProductDescribeOverride((int)($product['id'] ?? 0), (int)$substation['id']),
                    'image' => (string)($product['image'] ?? ''),
                    'sort' => (int)($product['sort'] ?? 0),
                    'tiers' => $tiers,
                ];
            }
            return show(200, 'success', '查询成功', ['list' => $list]);
        } catch (\Throwable $e) {
			$this->logApiException('substation_product_catalog', $e);
			return show(500, 'error', '系统繁忙，请稍后再试');
        }
    }

    public function saveProductTierPrice()
    {
        try {
            $payload = $this->request->post();
            $productId = (int)($payload['product_id'] ?? 0);
            $productDescribe = trim((string)($payload['product_describe'] ?? ''));
            $tiers = $payload['tiers'] ?? [];
            if (is_string($tiers)) {
                $decoded = json_decode($tiers, true);
                $tiers = is_array($decoded) ? $decoded : [];
            }
            $substation = SubstationService::getOrCreateByUid((int)$this->user_info['id']);
            SubstationPriceService::saveTiers((int)$this->user_info['id'], (int)$substation['id'], $productId, $tiers, $productDescribe);
            return show(200, 'success', '保存成功');
        } catch (\Throwable $e) {
			$this->logApiException('substation_save_product_tier_price', $e, [
				'product_id' => $productId ?? 0,
			]);
			return show(500, 'error', '操作失败，请联系客服');
        }
    }

    public function incomeLog()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $substation = Substation::where('uid', (int)$this->user_info['id'])->find();
        if (!$substation) {
            return show(200, 'success', '查询成功', ['list' => [], 'total' => 0]);
        }

        $query = SubstationIncomeLog::where('substation_id', (int)$substation['id'])->where('scene', 'substation_wallet_income')->order('id desc');
        $total = $query->count();
        $rows = $query->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $item = $row->toArray();
            $order = null;
            if ((int)($item['order_id'] ?? 0) > 0) {
                $order = Order::where('id', (int)$item['order_id'])->find();
            }

            $productName = '--';
            $rechargeAmount = '--';
            if ($order) {
                $productInfo = $order['product_info'] ?? null;
                if (is_string($productInfo)) {
                    $decoded = json_decode($productInfo, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $productInfo = $decoded;
                    }
                }
                if (is_array($productInfo)) {
                    $productName = (string)($productInfo['home_name'] ?? $productInfo['name'] ?? '--');
                }
                if ($productName === '--' && (int)($order['product_id'] ?? 0) > 0) {
                    $product = Product::where('id', (int)$order['product_id'])->find();
                    if ($product) {
                        $productName = (string)($product['home_name'] ?? $product['name'] ?? '--');
                    }
                }
                $rechargeRaw = $order['amount_money'] ?? '';
                if ($rechargeRaw !== '' && $rechargeRaw !== null) {
                    $rechargeAmount = (string)$rechargeRaw;
                }
            }

            $amountUsdt = round((float)($item['amount'] ?? 0), 2);
            if (($item['scene'] ?? '') === 'substation_wallet_income' && $order) {
                try {
                    $amountUsdt = SubstationSettlementService::resolveOrderMarkupUsdt($order);
                } catch (\Throwable $e) {
                    $amountUsdt = round((float)($item['amount'] ?? 0), 2);
                }
            }

            $item['amount_usdt'] = $amountUsdt;
            $item['product_name'] = $productName;
            $item['recharge_amount'] = $rechargeAmount;
            $list[] = $item;
        }

        return show(200, 'success', '查询成功', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function walletTransfer()
    {
        $payload = $this->request->post();
        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $payload = $json;
            }
        }

        $amount = round((float)($payload['amount'] ?? 0), 2);
        $password = trim((string)($payload['password'] ?? ''));
        if ($amount <= 0) {
            return show(400, 'error', '划转金额必须大于0');
        }
        if ($password === '') {
            return show(400, 'error', '请输入登录密码');
        }

        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid > 0 && !ActionRateLimiter::check('substation_wallet_transfer:uid:' . $uid, 5, 60)) {
            return show(429, 'error', '划转操作过于频繁，请稍后再试', [], 429);
        }
        try {
            $result = Db::transaction(function () use ($uid, $amount, $password) {
                $substation = Substation::where('uid', $uid)->lock(true)->find();
                if (!$substation) {
                    throw new Exception('分站不存在');
                }
                $status = (int)($substation['status'] ?? 0);
                if ($status === 4) {
                    throw new Exception('分站已被冻结，暂不可划转');
                }
                if ($status !== 2) {
                    throw new Exception('分站未开通，暂不可划转');
                }

                $user = UserModel::where('id', $uid)->lock(true)->find();
                if (!$user) {
                    throw new Exception('用户不存在');
                }
                if (!password_verify(($password . $user->salt), $user->password)) {
                    throw new Exception('密码不正确');
                }

                $walletBefore = round((float)($substation['wallet_balance'] ?? 0), 2);
                if ($walletBefore + 0.000001 < $amount) {
                    throw new Exception('分站钱包余额不足');
                }
                $walletAfter = round($walletBefore - $amount, 2);
                $substation->wallet_balance = $walletAfter;
                $substation->wallet_total_transferred = round((float)($substation['wallet_total_transferred'] ?? 0) + $amount, 2);
                $substation->income_balance = $walletAfter;
                $substation->update_time = date('Y-m-d H:i:s');
                if ($substation->save() === false) {
                    throw new Exception('分站钱包扣减失败');
                }

                $bizNo = 'SWT' . date('YmdHis') . mt_rand(1000, 9999);
                $userBalanceBefore = round((float)($user['balance'] ?? 0), 2);
                $ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
                    $user,
                    UserFundLedgerService::WALLET_BALANCE,
                    $amount,
                    [
                        'biz_type' => 'substation_wallet',
                        'biz_id' => (int)($substation['id'] ?? 0),
                        'biz_no' => $bizNo,
                        'order_number' => $bizNo,
                        'change_type' => 'substation_wallet_to_balance',
                        'operator_type' => 'user',
                        'operator_id' => $uid,
                        'status' => 'done',
                        'request_no' => 'substation_wallet_to_balance:' . $bizNo,
                        'remark' => 'substation wallet to balance',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'substation_wallet_transfer',
                            'substation_id' => (int)($substation['id'] ?? 0),
                            'wallet_balance_before' => $walletBefore,
                            'wallet_balance_after' => $walletAfter,
                        ],
                    ]
                );
                $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                $userBalanceAfter = array_key_exists('balance', $walletSnapshot)
                    ? round((float)($walletSnapshot['balance'] ?? 0), 2)
                    : round((float)($user['balance'] ?? ($userBalanceBefore + $amount)), 2);

                $log = SubstationIncomeLog::create([
                    'substation_id' => (int)$substation['id'],
                    'uid' => $uid,
                    'order_id' => 0,
                    'order_number' => $bizNo,
                    'product_id' => 0,
                    'tier_key' => '',
                    'scene' => 'substation_wallet_transfer_out',
                    'change_type' => 2,
                    'amount' => $amount,
                    'balance_before' => $walletBefore,
                    'balance_after' => $walletAfter,
                    'remark' => '分站钱包划转到用户账户钱包',
                    'operator_id' => $uid,
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
                if (!$log) {
                    throw new Exception('分站钱包转出流水写入失败');
                }

                $balanceLog = UserBalanceLog::create([
                    'uid' => $uid,
                    'scene' => 'substation_wallet_transfer_in',
                    'change_type' => 1,
                    'currency' => 'USDT',
                    'amount' => $amount,
                    'balance_before' => $userBalanceBefore,
                    'balance_after' => $userBalanceAfter,
                    'biz_type' => 'substation_wallet',
                    'biz_id' => (int)$substation['id'],
                    'order_number' => $bizNo,
                    'remark' => '分站钱包转入账户钱包',
                    'operator_id' => $uid,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                if (!$balanceLog) {
                    throw new Exception('账户钱包流水写入失败');
                }

                return [
                    'wallet_balance' => $walletAfter,
                    'wallet_total_income' => round((float)($substation['wallet_total_income'] ?? 0), 2),
                    'wallet_total_transferred' => round((float)($substation['wallet_total_transferred'] ?? 0), 2),
                    'account_balance' => $userBalanceAfter,
                    'transfer_amount' => $amount,
                ];
            });

            return show(200, 'success', '划转成功', $result);
        } catch (\Throwable $e) {
            $this->logApiException('substation_wallet_transfer', $e, [
                'amount' => $amount,
            ]);
            return show(500, 'error', '操作失败，请联系客服');
        }
    }
}
