<?php
declare (strict_types=1);

namespace app\controller;
use app\common\SecurityKeyResolver;
use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\model\User as UserModel;
use app\model\Cache as CacheModel;
use app\model\Config as ConfigModel;
use app\model\Order;
use app\model\Product;
use app\model\Slide;
use app\model\Recharge;
use app\model\Withdrawal;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\RebateRecord;
use app\model\BankCard;
use app\model\Substation;
use app\model\UserMessage;
use app\model\UserBalanceLog;
use app\model\PointsRecord;
use app\service\AdminOperationLogService;
use app\service\ExportService;
use app\service\AuthorizationService;
use app\service\LoginRateLimiter;
use app\service\ProductOrderService;
use app\service\UploadService;
use app\service\UserFundLedgerService;
use app\service\UserMessageService;
use app\service\telegram\OrderTelegramNotifier;
use app\common\service\SubstationSettlementService;
use app\common\service\SubstationPriceService;

// 引入2FA相关类
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Alignment\LabelAlignmentCenter;
use Endroid\QrCode\Label\Font\NotoSans;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use PragmaRX\Google2FA\Google2FA;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

use Exception;
use JsonException;
use RobThree\Auth\TwoFactorAuth;
use think\App;
use think\db\exception\DbException;
use think\exception\ValidateException;
use think\facade\Session;
use think\facade\Validate;
use think\Request;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log; // 引入日志类
use Yurun\Util\HttpRequest;
use yzh52521\filesystem\facade\Filesystem;

class AdminApi extends \app\BaseController
{
    /**
     * Request实例
     * @var Request
     */
    protected Request $request;

    /**
     * 应用实例
     * @var App
     */
    protected App $app;

    protected mixed $admin_info;
    protected string|array|bool $config = [];
    protected array $middleware = [AdminAuth::class];
    protected AdminOperationLogService $adminOperationLogService;
    protected string $encryptionKey = '';
    protected bool $encryptionKeyLoaded = false;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->app = $app;
        $this->request = $this->app->request;
        // 将当前登录管理员信息写入至私有属性
        $this->admin_info = $this->request->session('admin');
        $this->config = getConfig();
        $this->adminOperationLogService = new AdminOperationLogService($this->request);
    }

    private function directWriteAdminOperationLog(string $action, string $module, string $content, array $options = []): void
    {
        $this->adminOperationLogService->record($action, $module, $content, array_merge([
            'admin' => is_array($this->admin_info) ? $this->admin_info : [],
        ], $options));
    }

    private function directConfigFieldLabels(): array
    {
        return [
            'name' => '网站名称',
            'backstage_entrance' => '后台入口',
            'rate' => '最新费率',
            'mailing_address' => 'TG客服',
            'notice' => '首页公告',
            'a_recommend_id' => '首页推荐一产品',
            'b_recommend_id' => '首页推荐二产品',
            'a_recommend_image' => '首页推荐一图片',
            'b_recommend_image' => '首页推荐二图片',
            'contact_service_url' => '在线客服地址',
            'contact_service_image' => '在线客服图片',
            'chatwoot_enabled' => 'Chatwoot启用状态',
            'chatwoot_base_url' => 'Chatwoot客服域名',
            'chatwoot_token' => 'Chatwoot网站令牌',
            'transaction_mini_quantity' => '最低挂单数量',
            'transaction_fees' => '交易手续费',
            'platform_account_uid' => '平台账户UID(手续费记账)',
            'user_avatar_image' => '默认注册头像',
            'agent_money' => '代理开通价格',
            'agent_jieshao' => '代理开通介绍',
            'substation_open_price' => '分站开通价格',
            'substation_open_intro' => '分站开通介绍',
            'substation_base_domain' => '分站基础域名',
            'agreement' => '用户协议',
            'privacy_policy' => '隐私政策',
            'mini_recharge_amount' => '最低充值金额',
            'mini_withdrawal_amount' => '最低提现金额',
            'withdrawal_fee' => '提现手续费',
            'payment_address' => '收款地址',
            'bepusdt_base_url' => 'Bepusdt接口地址',
            'bepusdt_api_token' => 'Bepusdt秘钥',
            'epay_url' => '易支付接口地址',
            'epay_id' => '易支付ID',
            'epay_key' => '易支付秘钥',
            'epay_alipay_enabled' => '支付宝充值开关',
            'epay_wechat_enabled' => '微信支付充值开关',
            'telegram_bot_status' => 'Telegram机器人状态',
            'telegram_bot_token' => 'Telegram机器人Token',
            'telegram_bot_username' => 'Telegram机器人用户名',
            'telegram_webhook_url' => 'Telegram Webhook地址',
            'telegram_welcome_message' => 'Telegram欢迎语',
            'substation_blocked_subdomains' => '分站保留前缀',
        ];
    }

    private function directBuildChangedFields(array $before, array $after, array $keys): array
    {
        $changed = [];
        foreach ($keys as $key) {
            $oldValue = (string)($before[$key] ?? '');
            $newValue = (string)($after[$key] ?? '');
            if ($oldValue === $newValue) {
                continue;
            }
            $changed[$key] = [
                'before' => $before[$key] ?? '',
                'after' => $after[$key] ?? '',
            ];
        }
        return $changed;
    }

    private function safeExcel($value): string
    {
        // B05-C: 委托 ExportService（行为等价，供红区 order_post 兼容调用）。
        return app(ExportService::class)->safeExcel($value);
    }

    private function createPrivateExportDownload(Spreadsheet $spreadsheet, string $scene = 'order_export'): string
    {
        // B05-C: 委托 ExportService（行为等价；admin_id 从当前登录上下文传入）。
        return app(ExportService::class)->createPrivateExportDownload($spreadsheet, $scene, (int)($this->admin_info['id'] ?? 0));
    }

    public function export_download()
    {
        // B05-C: 业务实现已迁移至 admin\Export 控制器（承载 ExportService）；本入口反向薄转发保持旧路由兼容。
        return app(\app\controller\admin\Export::class)->export_download();
    }

    private function directAllowedConfigKeys(): array
    {
        return array_keys($this->directConfigFieldLabels());
    }
    
    private function directTextareaConfigKeys(): array
    {
        return ['notice', 'agent_jieshao', 'substation_open_intro', 'agreement', 'privacy_policy', 'telegram_welcome_message'];
    }
    
    private function directUrlConfigKeys(): array
    {
        return ['contact_service_url', 'chatwoot_base_url', 'epay_url', 'bepusdt_base_url', 'telegram_webhook_url'];
    }
    
    private function directDangerousConfigFragments(): array
    {
        return ['<script', 'onerror=', 'onload=', 'javascript:', '<iframe', '<object', '<embed', '<svg', 'data:text/html'];
    }
    
    private function directContainsDangerousConfigFragment(string $value): bool
    {
        $normalized = strtolower($value);
        foreach ($this->directDangerousConfigFragments() as $fragment) {
            if ($fragment !== '' && str_contains($normalized, $fragment)) {
                return true;
            }
        }
        return false;
    }
    
    private function directValidateSafeConfigUrl(string $value): bool
    {
        if ($value === '' || $this->directContainsDangerousConfigFragment($value)) {
            return false;
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        $host = (string)parse_url($value, PHP_URL_HOST);
        $user = (string)parse_url($value, PHP_URL_USER);
        $pass = (string)parse_url($value, PHP_URL_PASS);
        return in_array($scheme, ['http', 'https'], true) && $host !== '' && $user === '' && $pass === '';
    }
    
    private function directNormalizeDecimalString(string $value, int $scale = 2): string
    {
        $normalized = number_format((float)$value, max(0, $scale), '.', '');
        $normalized = rtrim(rtrim($normalized, '0'), '.');
        return $normalized === '' ? '0' : $normalized;
    }


    private function directAllowedConfigMetaKeys(): array
    {
        return array_values(array_unique([
            '__token__',
            $this->directCsrfTokenName(),
        ]));
    }

    private function directAllowedSensitiveAuthKeys(): array
    {
        return ['admin_password', 'twofa_code', 'verify_code'];
    }



    private function directIsAllowedConfigReferer(string $referer): bool
    {
        if ($referer === '') {
            return false;
        }

        $refererHost = (string)parse_url($referer, PHP_URL_HOST);
        $requestHost = (string)$this->request->host();
        if ($refererHost !== '' && $requestHost !== '' && strcasecmp($refererHost, $requestHost) !== 0) {
            return false;
        }

        $refererPath = (string)parse_url($referer, PHP_URL_PATH);
        $refererPath = trim(str_replace('\\', '/', $refererPath), '/');
        foreach (['setting', 'substation_apply', 'substation_profile_audit'] as $allowedPath) {
            $allowedPath = strtolower(trim($allowedPath, '/'));
            $normalizedRefererPath = strtolower($refererPath);
            if (
                $normalizedRefererPath === $allowedPath
                || str_ends_with($normalizedRefererPath, '/' . $allowedPath)
                || str_contains($normalizedRefererPath, '/' . $allowedPath . '/')
            ) {
                return true;
            }
        }

        return false;
    }



    private function directValidateSensitiveOperation(array $postInfo, string $scene): array
    {
        return app(\app\service\AdminSensitiveOperationGuard::class)->verifySensitiveOperation($postInfo, $scene);
    }

    private function directMaskSensitiveLogValue(string $key, mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '[complex data]';
        }

        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return '';
        }

        if (!in_array($key, ['bepusdt_api_token', 'epay_key', 'telegram_bot_token', 'chatwoot_token', 'payment_address'], true)) {
            return $stringValue;
        }

        $length = mb_strlen($stringValue, 'UTF-8');
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return mb_substr($stringValue, 0, 4, 'UTF-8')
            . str_repeat('*', max(4, $length - 8))
            . mb_substr($stringValue, $length - 4, 4, 'UTF-8');
    }

    private function directBuildSecurityLogPayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            $sanitized[(string)$key] = $this->directMaskSensitiveLogValue((string)$key, $value);
        }

        return $sanitized;
    }

    private function directLogConfigWriteAttempt(string $message, array $payload, string $level = 'info'): void
    {
        $context = [
            'admin_id' => (int)($this->admin_info['id'] ?? 0),
            'ip' => (string)$this->request->ip(),
            'path' => $this->directCurrentRequestPath(),
            'referer' => (string)$this->request->header('referer', ''),
            'post_keys' => array_keys($payload),
            'post_data' => $this->directBuildSecurityLogPayload($payload),
        ];

        if ($level === 'warning') {
            Log::warning($message, $context);
            return;
        }

        Log::info($message, $context);
    }

    private function directOrderStatusText(int $status): string
    {
        return [
            0 => '待充值',
            1 => '处理中',
            2 => '已完成',
            3 => '已取消',
        ][$status] ?? '未知状态';
    }

    private function directOrderConfirmStatusText(int $status): string
    {
        return [
            0 => '未完成',
            1 => '待确认',
            2 => '已确认',
            3 => '未收到',
        ][$status] ?? '未知状态';
    }

    private function directLockUser(int $uid)
    {
        return app(\app\service\UserService::class)->lockById($uid);
    }

    private function directAdminAdjustBalanceWithLedger($user, float $amount, int $bizId, string $bizNo, string $changeType, string $remark): array
    {
        $delta = $changeType === 'admin_balance_subtract' ? -1 * round($amount, 2) : round($amount, 2);

        return (new UserFundLedgerService())->changeLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_BALANCE,
            $delta,
            [
                'biz_type' => 'admin_balance_adjust',
                'biz_id' => $bizId,
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'change_type' => $changeType,
                'operator_type' => 'admin',
                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                'status' => 'done',
                'request_no' => 'admin_balance_adjust:' . $bizNo,
                'remark' => $remark,
                'idempotent' => true,
                'extra' => [
                    'source' => 'admin_user_post_balance',
                    'adjust_mode' => $changeType === 'admin_balance_subtract' ? 'subtract' : 'add',
                ],
            ]
        );
    }
    

    private function directRefundProductOrderCancelToBalance($user, $order, float $refundUsdt): array
    {
        $balanceBefore = round((float)($user['balance'] ?? 0), 2);
        $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_FROZEN,
            UserFundLedgerService::WALLET_BALANCE,
            round($refundUsdt, 2),
            [
                'biz_type' => 'product_order',
                'biz_id' => (int)($order['id'] ?? 0),
                'biz_no' => (string)($order['order_number'] ?? ''),
                'order_number' => (string)($order['order_number'] ?? ''),
                'out_change_type' => 'product_order_cancel_refund',
                'in_change_type' => 'product_order_cancel_refund',
                'operator_type' => 'admin',
                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                'status' => 'done',
                'request_no' => 'product_order_cancel_refund:' . (string)($order['order_number'] ?? ''),
                'remark' => 'product order cancel refund',
                'idempotent' => true,
                'extra' => [
                    'source' => 'admin_product_order_cancel_refund',
                    'refund_scene' => 'order_cancel_refund',
                    'order_status_before' => (int)($order['status'] ?? 0),
                    'confirm_status_before' => (int)($order['confirm_status'] ?? 0),
                ],
            ]
        );
        $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
        $balanceAfter = array_key_exists('balance', $walletSnapshot)
            ? round((float)($walletSnapshot['balance'] ?? 0), 2)
            : round((float)($user['balance'] ?? ($balanceBefore + $refundUsdt)), 2);

        $this->directWriteBalanceLog([
            'uid' => (int)($order['uid'] ?? 0),
            'scene' => 'order_cancel_refund',
            'amount' => $refundUsdt,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'biz_id' => (int)($order['id'] ?? 0),
            'order_number' => (string)($order['order_number'] ?? ''),
            'remark' => '订单取消退款',
            'operator_id' => (int)($this->admin_info['id'] ?? 0),
        ]);

        return [
            'ledger_result' => $ledgerResult,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
        ];
    }

    private function directMatchSettlementDiscount(int $productId, float $amountReceived): array
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new Exception('商品不存在');
        }
        $discountList = $product['discount'] ?? [];
        if (!is_array($discountList) || empty($discountList)) {
            throw new Exception('商品折扣配置异常');
        }
        usort($discountList, function ($a, $b) {
            return (float)($a['mini_amount'] ?? 0) <=> (float)($b['mini_amount'] ?? 0);
        });
        $minimumTier = $discountList[0];
        $fallbackTier = $minimumTier;
        $matchedTier = null;
        foreach ($discountList as $item) {
            $miniAmount = round((float)($item['mini_amount'] ?? 0), 2);
            $maxiAmount = round((float)($item['maxi_amount'] ?? 0), 2);
            if ($amountReceived >= $miniAmount) {
                $fallbackTier = $item;
            }
            if ($amountReceived >= $miniAmount && $amountReceived <= $maxiAmount) {
                $matchedTier = $item;
                break;
            }
        }
        if ($matchedTier === null) {
            $matchedTier = $amountReceived < (float)($minimumTier['mini_amount'] ?? 0) ? $minimumTier : $fallbackTier;
        }
        return [
            'mini_amount' => round((float)($matchedTier['mini_amount'] ?? 0), 2),
            'discount' => round((float)($matchedTier['discount'] ?? 0), 2),
        ];
    }

    private function directBuildOrderSettlement($order, float $rate): array
    {
        $amountMoney = round((float)($order['amount_money'] ?? 0), 2);
        $amountReceived = round((float)($order['amount_received'] ?? 0), 2);
        $originalPayCny = order_original_pay_cny($order);
        $operatorId = (int)($this->admin_info['id'] ?? 0);
        $settlement = [
            'settlement_match_amount' => null,
            'settlement_match_discount' => null,
            'settlement_final_cny_amount' => $originalPayCny,
            'settlement_refund_cny_amount' => 0.00,
            'settlement_refund_rate' => null,
            'settlement_refund_usdt_amount' => 0.00,
            'settlement_refund_time' => null,
            'settlement_operator_id' => $operatorId,
        ];
        if ($amountReceived <= 0) {
            return $settlement;
        }
        if ($amountReceived > $amountMoney) {
            throw new Exception('实际到账金额不能大于订单充值金额');
        }
        if (abs($amountReceived - $amountMoney) < 0.000001) {
            return $settlement;
        }

        $matched = $this->directMatchSettlementDiscount((int)($order['product_id'] ?? 0), $amountReceived);
        $effectiveDiscount = (float)($matched['discount'] ?? 0);
        $finalPayCny = round($amountReceived * ($effectiveDiscount / 10), 2);

        $substationId = (int)($order['substation_id'] ?? 0);
        if ($substationId > 0) {
            $priceInfo = SubstationPriceService::resolveDiscountPreview((int)($order['product_id'] ?? 0), $amountReceived, $substationId);
            $effectiveDiscount = round((float)($priceInfo['discount'] ?? $effectiveDiscount), 2);
            $finalPayCny = round((float)($priceInfo['paymentAmount'] ?? $finalPayCny), 2);
            $matched['mini_amount'] = round((float)($amountReceived), 2);
        }

        $refundCny = round($originalPayCny - $finalPayCny, 2);
        if ($refundCny < 0) {
            throw new Exception('应退人民币金额不能小于0');
        }
        $refundUsdt = round($refundCny / $rate, 2);
        $settlement['settlement_match_amount'] = $matched['mini_amount'];
        $settlement['settlement_match_discount'] = $effectiveDiscount;
        $settlement['settlement_final_cny_amount'] = $finalPayCny;
        $settlement['settlement_refund_cny_amount'] = $refundCny;
        $settlement['settlement_refund_rate'] = round($rate, 6);
        $settlement['settlement_refund_usdt_amount'] = $refundUsdt;
        $settlement['settlement_refund_time'] = $refundUsdt > 0 ? date('Y-m-d H:i:s') : null;
        return $settlement;
    }

    private function directWriteBalanceLog(array $data): void
    {
        $scene = (string)($data['scene'] ?? '');
        $orderNumber = (string)($data['order_number'] ?? '');
        if ($scene === '' || $orderNumber === '') {
            throw new Exception('余额流水参数异常');
        }
        if (UserBalanceLog::where('scene', $scene)->where('order_number', $orderNumber)->find()) {
            return;
        }
        UserBalanceLog::create([
            'uid' => (int)($data['uid'] ?? 0),
            'scene' => $scene,
            'change_type' => 1,
            'currency' => 'USDT',
            'amount' => round((float)($data['amount'] ?? 0), 2),
            'balance_before' => round((float)($data['balance_before'] ?? 0), 2),
            'balance_after' => round((float)($data['balance_after'] ?? 0), 2),
            'biz_type' => 'cz_order',
            'biz_id' => (int)($data['biz_id'] ?? 0),
            'order_number' => $orderNumber,
            'remark' => (string)($data['remark'] ?? ''),
            'operator_id' => (int)($data['operator_id'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function directCompleteRechargeOrder(int $orderId): void
    {
        $order = null;
        $orderSnapshot = [];
        $originalStatus = 0;
        $originalConfirmStatus = 0;
        $refundUsdt = 0.0;
        Db::startTrans();
        try {
            $order = Order::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new Exception('订单不存在');
            }
            $originalStatus = (int)($order['status'] ?? 0);
            $originalConfirmStatus = (int)($order['confirm_status'] ?? 0);
            if (!in_array((int)$order['status'], [0, 1], true)) {
                throw new Exception('审核异常');
            }
            $user = $this->directLockUser((int)($order['uid'] ?? 0));
            if (!$user) {
                throw new Exception('用户不存在');
            }
            $rate = (float)(getConfig('rate') ?? 0);
            if ($rate <= 0) {
                throw new Exception('后台汇率未配置');
            }
            $settlement = $this->directBuildOrderSettlement($order, $rate);
            $refundUsdt = round((float)($settlement['settlement_refund_usdt_amount'] ?? 0), 2);
            if ($refundUsdt > 0) {
                $balanceBefore = round((float)($user['balance'] ?? 0), 2);
                $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
                    $user,
                    UserFundLedgerService::WALLET_FROZEN,
                    UserFundLedgerService::WALLET_BALANCE,
                    $refundUsdt,
                    [
                        'biz_type' => 'product_order',
                        'biz_id' => (int)$order['id'],
                        'biz_no' => (string)$order['order_number'],
                        'order_number' => (string)$order['order_number'],
                        // Settlement refund should use a distinct ledger scene from cancel refund.
                        'out_change_type' => 'product_order_partial_refund',
                        'in_change_type' => 'product_order_partial_refund',
                        'operator_type' => 'admin',
                        'operator_id' => (int)($this->admin_info['id'] ?? 0),
                        'status' => 'done',
                        'request_no' => 'refund:product_order_partial:' . (string)$order['order_number'],
                        'remark' => 'product order partial refund',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'directCompleteRechargeOrder',
                            'refund_scene' => 'order_complete_partial_refund',
                            'settlement_match_amount' => round((float)($settlement['settlement_match_amount'] ?? 0), 2),
                            'settlement_final_cny_amount' => round((float)($settlement['settlement_final_cny_amount'] ?? 0), 2),
                            'settlement_refund_usdt_amount' => $refundUsdt,
                        ],
                    ]
                );
                $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                $balanceAfter = array_key_exists('balance', $walletSnapshot)
                    ? round((float)$walletSnapshot['balance'], 2)
                    : round((float)($user['balance'] ?? ($balanceBefore + $refundUsdt)), 2);
                $this->directWriteBalanceLog([
                    'uid' => (int)$order['uid'],
                    'scene' => 'order_complete_partial_refund',
                    'amount' => $refundUsdt,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'biz_id' => (int)$order['id'],
                    'order_number' => (string)$order['order_number'],
                    'remark' => '订单完成部分退款',
                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                ]);
            }
            $order->settlement_match_amount = $settlement['settlement_match_amount'];
            $order->settlement_match_discount = $settlement['settlement_match_discount'];
            $order->settlement_final_cny_amount = $settlement['settlement_final_cny_amount'];
            $order->settlement_refund_cny_amount = $settlement['settlement_refund_cny_amount'];
            $order->settlement_refund_rate = $settlement['settlement_refund_rate'];
            $order->settlement_refund_usdt_amount = $settlement['settlement_refund_usdt_amount'];
            $order->settlement_refund_time = $settlement['settlement_refund_time'];
            $order->settlement_operator_id = $settlement['settlement_operator_id'];
            $order->operator_id = (int)($this->admin_info['id'] ?? 0);
            $order->status = \app\model\Order::STATUS_COMPLETED;
            $order->confirm_status = 1;
            $order->complete_time = date('Y-m-d H:i:s');
            $order->save();
            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        if ($order) {
            try {
                (new OrderTelegramNotifier())->notifyProductOrderCompleted($orderSnapshot);
            } catch (\Throwable $notifyException) {
                Log::error('product order notify failed', [
                    'order_id' => (int)($orderSnapshot['id'] ?? 0),
                    'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                    'uid' => (int)($orderSnapshot['uid'] ?? 0),
                    'action' => 'product_order_completed_notify',
                    'error_message' => $notifyException->getMessage(),
                ]);
            }
            try {
                rebate((string)($order['order_number'] ?? ''));
            } catch (\Throwable $e) {
                Log::error('order complete rebate failed', [
                    'order_id' => (int)($order['id'] ?? $orderId),
                    'order_number' => (string)($order['order_number'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
            try {
                SubstationSettlementService::settleCompletedOrder((int)$order['id'], (int)($this->admin_info['id'] ?? 0));
            } catch (\Throwable $e) {
                Log::error('order complete settlement failed', [
                    'order_id' => (int)($order['id'] ?? $orderId),
                    'order_number' => (string)($order['order_number'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
            $this->directWriteAdminOperationLog('审核订单', '订单管理', '订单号：' . (string)($order['order_number'] ?? '') . '，UID：' . (int)($order['uid'] ?? 0) . '，订单状态：' . $this->directOrderStatusText($originalStatus) . ' -> ' . $this->directOrderStatusText((int)($order['status'] ?? 0)) . '，确认状态：' . $this->directOrderConfirmStatusText($originalConfirmStatus) . ' -> ' . $this->directOrderConfirmStatusText((int)($order['confirm_status'] ?? 0)) . '，实际到账：' . (string)($order['amount_received'] ?? '未设置') . '，退款：' . number_format($refundUsdt, 2) . ' USDT', [
                'target_id' => (int)($order['id'] ?? 0),
                'target_type' => 'order',
            ]);
        }
    }

    private function directRefundRemainingUsdt(int $orderId): void
    {
        $orderSnapshot = [];
        $orderNumber = '';
        $uid = 0;
        $refundUsdt = 0.0;
        Db::startTrans();
        try {
            $order = Order::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new Exception('订单不存在');
            }
            $orderNumber = (string)($order['order_number'] ?? '');
            $uid = (int)($order['uid'] ?? 0);
            $status = (int)($order['status'] ?? 0);
            $confirmStatus = (int)($order['confirm_status'] ?? 0);
            if (!(in_array($status, [0, 1], true) || ($status === 2 && $confirmStatus === 3))) {
                throw new Exception('审核异常');
            }
            $user = $this->directLockUser((int)($order['uid'] ?? 0));
            if (!$user) {
                throw new Exception('用户不存在');
            }
            $refundUsdt = order_refundable_usdt($order);
            if ($refundUsdt > 0) {
                $this->directRefundProductOrderCancelToBalance($user, $order, $refundUsdt);
            }
            $order->status = \app\model\Order::STATUS_CANCELLED;
            $order->operator_id = (int)($this->admin_info['id'] ?? 0);
            $order->save();
            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        try {
            (new OrderTelegramNotifier())->notifyProductOrderCancelled($orderSnapshot, '后台退款取消');
        } catch (\Throwable $notifyException) {
            Log::error('product order notify failed', [
                'order_id' => (int)($orderSnapshot['id'] ?? 0),
                'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                'uid' => (int)($orderSnapshot['uid'] ?? 0),
                'action' => 'product_order_cancelled_notify',
                'error_message' => $notifyException->getMessage(),
            ]);
        }
        $this->directWriteAdminOperationLog('手动退款', '订单管理', '订单号：' . $orderNumber . '，UID：' . $uid . '，退款金额：' . number_format($refundUsdt, 2) . ' USDT', [
            'target_id' => $orderId,
            'target_type' => 'order',
        ]);
    }





    private function handleBalance(array $post_info)
    {
        // D3-F1: 余额调整权限按 add/subtract 拆分，admin.user.view 不再能调余额
        $balanceAction = trim((string)($post_info['add_minus'] ?? ''));
        $balancePerm = $balanceAction === 'add' ? 'admin.user.balance.add' : ($balanceAction === 'minus' ? 'admin.user.balance.subtract' : '');
        if ($balancePerm === '' || !$this->authorize($balancePerm)) {
            return $this->directDenyAdminPermission($balancePerm ?: '余额调整');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post balance invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '余额请求校验失败');
        }
        $configFieldKeys = array_values(array_intersect(array_keys((array)$post_info), $this->directAllowedConfigKeys()));
        if (!empty($configFieldKeys)) {
            Log::warning('admin user_post balance polluted payload blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
                'blocked_keys' => $configFieldKeys,
            ]);
            return show(403, 'error', '余额请求包含非法配置字段');
        }
        if (!$this->directRequestPathMatches('user_post/balance')) {
            Log::warning('admin user_post balance invalid path blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '请求路径错误');
        }
        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'user_balance');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }
        $post_info = array_intersect_key((array)$post_info, array_flip(['uid', 'balance_cz', 'add_minus']));
        try {
            Db::startTrans();
            $user_info = $this->directLockUser((int)$post_info['uid']);
            if (!$user_info) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            $amount = (float)($post_info['balance_cz'] ?? 0);
            if ($amount <= 0) {
                Db::rollback();
                return show(500, 'error', '金额有误');
            }
            if ($post_info['add_minus'] === 'add') {
                $beforeBalance = (float)($user_info['balance'] ?? 0);
                $bizNo = date("Ymd") . randomkeys(6, 'number');
                $recharge = Recharge::create([
                    'uid' => $user_info['id'],
                    'amount' => $amount,
                    'wallet_address' => '后台充值加款',
                    'status' => 3,
                    'order_number' => $bizNo,
                ]);
                if (!$recharge) {
                    throw new Exception('调账记录创建失败');
                }
                $ledgerResult = $this->directAdminAdjustBalanceWithLedger(
                    $user_info,
                    $amount,
                    (int)($recharge['id'] ?? 0),
                    $bizNo,
                    'admin_balance_add',
                    '后台人工加款'
                );
                $balanceAfter = round((float)($ledgerResult['after_amount'] ?? ($beforeBalance + $amount)), 2);
                Db::commit();
                $this->directWriteAdminOperationLog('修改用户余额', '用户管理', '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，变更：增加 ' . number_format($amount, 2) . ' USDT，余额：' . number_format($beforeBalance, 2) . ' -> ' . number_format($balanceAfter, 2), [
                    'target_id' => (int)$user_info['id'],
                    'target_type' => 'user',
                ]);
                return show(200, 'success', '加款成功');
            }else if ($post_info['add_minus'] === 'minus') {
                $beforeBalance = (float)($user_info['balance'] ?? 0);
                $bizNo = date("Ymd") . randomkeys(6, 'number');
                $recharge = Recharge::create([
                    'uid' => $user_info['id'],
                    'amount' => $amount,
                    'wallet_address' => '后台充值扣款',
                    'status' => 3,
                    'operate_type' => 1,
                    'order_number' => $bizNo,
                ]);
                if (!$recharge) {
                    throw new Exception('调账记录创建失败');
                }
                $ledgerResult = $this->directAdminAdjustBalanceWithLedger(
                    $user_info,
                    $amount,
                    (int)($recharge['id'] ?? 0),
                    $bizNo,
                    'admin_balance_subtract',
                    '后台人工扣款'
                );
                $balanceAfter = round((float)($ledgerResult['after_amount'] ?? ($beforeBalance - $amount)), 2);
                Db::commit();
                $this->directWriteAdminOperationLog('修改用户余额', '用户管理', '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，变更：减少 ' . number_format($amount, 2) . ' USDT，余额：' . number_format($beforeBalance, 2) . ' -> ' . number_format($balanceAfter, 2), [
                    'target_id' => (int)$user_info['id'],
                    'target_type' => 'user',
                ]);
                return show(200, 'success', '扣款成功');
            }
            Db::rollback();
            return show(500, 'error', '操作类型错误');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post balance error: ' . $e->getMessage(), ['uid' => (int)($post_info['uid'] ?? 0)]);
            return show(500, 'error', '操作失败');
        }
    }

    private function handleSetting(array $rawPostData)
    {
        if (!$this->authorize('admin.setting.manage')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $allowedConfigKeys = $this->directAllowedConfigKeys();
        $allowedMetaKeys = $this->directAllowedConfigMetaKeys();
        if (!$this->directRequestPathMatches('setting_post/setting')) {
            $this->directLogConfigWriteAttempt('admin setting_post invalid path blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求路径错误');
        }
        $referer = trim((string)$this->request->header('referer', ''));
        if ($referer !== '' && !$this->directIsAllowedConfigReferer($referer)) {
            $this->directLogConfigWriteAttempt('admin setting_post invalid source blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求来源错误');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            $this->directLogConfigWriteAttempt('admin setting_post invalid csrf blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求校验失败');
        }
        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$rawPostData, 'system_setting');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }
        $unknownKeys = array_values(array_diff(array_keys($rawPostData), array_merge($allowedConfigKeys, $allowedMetaKeys, $this->directAllowedSensitiveAuthKeys())));
        if (!empty($unknownKeys)) {
            $this->directLogConfigWriteAttempt('admin setting_post mixed payload blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求包含非法字段：' . implode(',', $unknownKeys));
        }

        $postData = array_intersect_key($rawPostData, array_flip($allowedConfigKeys));
        if (empty($postData)) {
            return show(500, 'error', '未提交有效配置项');
        }
        foreach ($postData as $k => $v) {
            if (is_string($v)) {
                $postData[$k] = trim($v);
            }
        }
        $fieldLabels = $this->directConfigFieldLabels();
        foreach ($postData as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (in_array($key, $this->directTextareaConfigKeys(), true) && $this->directContainsDangerousConfigFragment($value)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '包含危险内容，请移除脚本或事件代码');
            }
            if (in_array($key, $this->directUrlConfigKeys(), true) && !$this->directValidateSafeConfigUrl($value)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '仅支持安全的 HTTP/HTTPS 地址');
            }
        }
        $lengthRules = [
            'notice' => 1000,
            'agent_jieshao' => 5000,
            'substation_open_intro' => 5000,
            'agreement' => 20000,
            'privacy_policy' => 20000,
            'telegram_welcome_message' => 1000,
            'payment_address' => 255,
            'contact_service_url' => 500,
            'chatwoot_base_url' => 500,
            'chatwoot_token' => 255,
            'epay_url' => 500,
            'bepusdt_base_url' => 500,
            'telegram_webhook_url' => 500,
        ];
        foreach ($lengthRules as $key => $maxLength) {
            if (!array_key_exists($key, $postData)) {
                continue;
            }
            if (mb_strlen((string)$postData[$key], 'UTF-8') > $maxLength) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '长度不能超过' . $maxLength . '个字符');
            }
        }
        $numericRules = [
            'rate' => ['pattern' => '/^\d+(?:\.\d{1,6})?$/', 'scale' => 6, 'min' => 0],
            'mini_recharge_amount' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'mini_withdrawal_amount' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'withdrawal_fee' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'substation_open_price' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'agent_money' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'transaction_mini_quantity' => ['pattern' => '/^\d+(?:\.\d{1,6})?$/', 'scale' => 6, 'min' => 0],
            'transaction_fees' => ['pattern' => '/^\d+(?:\.\d{1,6})?$/', 'scale' => 6, 'min' => 0],
            'platform_account_uid' => ['pattern' => '/^\d+$/', 'scale' => 0, 'min' => 0],
        ];
        foreach ($numericRules as $key => $rule) {
            if (!array_key_exists($key, $postData)) {
                continue;
            }
            $value = (string)$postData[$key];
            if ($value === '' || !preg_match((string)$rule['pattern'], $value)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '格式不正确');
            }
            if ((float)$value < (float)($rule['min'] ?? 0)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '不能小于0');
            }
            $postData[$key] = $this->directNormalizeDecimalString($value, (int)($rule['scale'] ?? 2));
        }
        foreach (['chatwoot_enabled', 'epay_alipay_enabled', 'epay_wechat_enabled'] as $switchKey) {
            if (array_key_exists($switchKey, $postData) && !in_array((string)$postData[$switchKey], ['0', '1'], true)) {
                return show(500, 'error', ($fieldLabels[$switchKey] ?? $switchKey) . '格式不正确');
            }
        }
        if (array_key_exists('payment_address', $postData) && preg_match('/[\r\n\x00-\x1F\x7F]/', (string)$postData['payment_address'])) {
            return show(500, 'error', '收款地址包含非法字符');
        }
        $beforeConfig = is_array($this->config) ? $this->config : [];
        foreach ($postData as $k => $v) {
            $configModel = ConfigModel::where('k', $k)->find();
            if ($configModel) {
                $configModel->v = $v;
                $configModel->save();
                continue;
            }

            ConfigModel::create([
                'k' => $k,
                'v' => $v,
            ]);
        }
        CacheModel::destroy('config');
        $this->directLogConfigWriteAttempt('admin setting_post config updated', $postData);

        $walletKeys = ['payment_address'];
        $paymentKeys = ['mini_recharge_amount', 'mini_withdrawal_amount', 'withdrawal_fee', 'bepusdt_base_url', 'bepusdt_api_token', 'epay_url', 'epay_id', 'epay_key', 'epay_alipay_enabled', 'epay_wechat_enabled'];
        $allKeys = array_keys($postData);
        $systemKeys = array_values(array_diff($allKeys, $walletKeys, $paymentKeys));
        $labels = $fieldLabels;

        $systemChanged = $this->directBuildChangedFields($beforeConfig, $postData, $systemKeys);
        $paymentChanged = $this->directBuildChangedFields($beforeConfig, $postData, $paymentKeys);
        $walletChanged = $this->directBuildChangedFields($beforeConfig, $postData, $walletKeys);

        if (!empty($systemChanged)) {
            $this->directWriteAdminOperationLog('修改系统配置', '系统配置', $this->adminOperationLogService->summarizeChanges($systemChanged, $labels));
        }
        if (!empty($paymentChanged)) {
            $this->directWriteAdminOperationLog('修改支付配置', '系统配置', $this->adminOperationLogService->summarizeChanges($paymentChanged, $labels));
        }
        if (!empty($walletChanged)) {
            $this->directWriteAdminOperationLog('修改钱包地址', '系统配置', $this->adminOperationLogService->summarizeChanges($walletChanged, $labels));
        }

        return show(200, 'success', '修改成功');
    }

    private function handleSettingUpload()
    {
        $fileBag = (array)$this->request->file();
        $keyname = array_key_first($fileBag);
        $file = $keyname !== null ? ($fileBag[$keyname] ?? null) : null;
        if (!is_object($file)) {
            return show(404, 'error', '请选择图片');
        }

        try {
            $stored = (new UploadService())->storeImageUpload($file, [
                'directory' => 'storage/images',
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif'],
            ]);
            return json([
                'default' => $stored['public_path'],
                'data' => $stored['public_path'],
            ]);
        } catch (\Throwable $e) {
            return show(404, 'error', $e->getMessage());
        }
    }
    

    public function transaction_product_post(string $action)
    {
        return app(\app\controller\admin\TransactionProduct::class)->transaction_product_post($action);
    }

public function order_post(string $action)
{
    $post_info = $this->request->post();

    // P2-004 P1-001: 按订单实际 type 动态权限检查（防止低权限管理员混入不同 type 订单）
    $orderIds = [];
    if (!empty($post_info['ids'])) {
        $idsRaw = is_array($post_info['ids']) ? $post_info['ids'] : explode(',', (string)$post_info['ids']);
        $orderIds = array_filter(array_unique(array_map('intval', $idsRaw)));
    } elseif (!empty($post_info['id'])) {
        $orderIds = [(int)$post_info['id']];
    }
    if (!empty($orderIds)) {
        $ordersForPerm = Order::where('id', 'in', $orderIds)->field('id,type')->select();
        foreach ($ordersForPerm as $orderForPerm) {
            $otype = (int)($orderForPerm['type'] ?? 0);
            $viewPerm = $otype === 1 ? 'admin.order.recharge.view' : ($otype === 2 ? 'admin.order.query.view' : '');
            if ($viewPerm === '' || !$this->authorize($viewPerm)) {
                return $this->directDenyAdminPermission($viewPerm ?: '订单管理');
            }
        }
    }

    // D3-F1: order_post 方法级 CSRF 校验
    if (!$this->directValidateRequiredCsrfToken()) {
        return show(403, 'error', '订单请求校验失败');
    }

    switch ($action) {
        case 'audit_s':
            // D3-F1: 批量审核按 complete/cancel 拆分权限
            $auditSStatus = (int)($post_info['status'] ?? 0);
            if ($auditSStatus === 2 && !$this->authorize('admin.order.recharge.complete')) {
                return $this->directDenyAdminPermission('admin.order.recharge.complete');
            }
            if ($auditSStatus === 3 && !$this->authorize('admin.order.recharge.cancel')) {
                return $this->directDenyAdminPermission('admin.order.recharge.cancel');
            }
            if ((int)$post_info['status'] === 2 || (int)$post_info['status'] === 3) {
                $data = Order::where('id', 'in', $post_info['ids'])->select();
                $failedIds = [];
                foreach ($data as $vo) {
                    try {
                        if ((int)$post_info['status'] === 2) {
                            $this->directCompleteRechargeOrder((int)$vo['id']);
                        } else {
                            $this->directRefundRemainingUsdt((int)$vo['id']);
                        }
                    } catch (\Throwable $e) {
                        $failedIds[] = (int)$vo['id'];
                    }
                }
                if (!empty($failedIds)) {
                    return show(500, 'error', '部分订单失败：' . implode(',', $failedIds));
                }
                $this->directWriteAdminOperationLog('审核订单', '订单管理', '批量审核订单成功，状态：' . $this->directOrderStatusText((int)$post_info['status']) . '，订单ID：' . trim((string)($post_info['ids'] ?? ''), ','), [
                    'target_type' => 'order',
                ]);
                return show(200, 'success', '处理成功');
            }
            $data = Order::where('id', 'in', $post_info['ids'])->select();
            $failedIds = [];
            foreach ($data as $vo) {
                $vo->status = $post_info['status'];
                if($vo['status'] == 2){
                    $vo->confirm_status = 1;
                    $vo->complete_time = date("Y-m-d H:i:s");
                    // 返佣操作
                    rebate($vo['order_number']);
                }
                $vo->save();
                if ((int)$post_info['status'] === 1) {
                    try {
                        (new OrderTelegramNotifier())->notifyProductOrderProcessing($vo->toArray());
                    } catch (\Throwable $notifyException) {
                        Log::error('product order notify failed', [
                            'order_id' => (int)($vo['id'] ?? 0),
                            'order_no' => (string)($vo['order_number'] ?? ''),
                            'uid' => (int)($vo['uid'] ?? 0),
                            'action' => 'product_order_processing_notify',
                            'error_message' => $notifyException->getMessage(),
                        ]);
                    }
                }
            }
            $this->directWriteAdminOperationLog('审核订单', '订单管理', '批量更新订单状态成功，状态：' . $this->directOrderStatusText((int)$post_info['status']) . '，订单ID：' . trim((string)($post_info['ids'] ?? ''), ','), [
                'target_type' => 'order',
            ]);
            return show(200, 'success', '处理成功');
            
        case 'audit_dz':
            // 验证必要参数
            if (empty($post_info['ids']) && empty($post_info['id'])) {
                return show(500, 'error', '请提供订单ID');
            }
            
            // 验证金额参数
            if (!isset($post_info['dz_number']) || !is_numeric($post_info['dz_number']) || $post_info['dz_number'] < 0) {
                return show(500, 'error', '请输入有效的到账金额');
            }
            if ($post_info['dz_number'] > 99999999.99) {
                return show(500, 'error', '到账金额超出合理范围');
            }
            
            // 处理单个或批量订单
            $ids = $post_info['ids'] ?? $post_info['id'];
            $idArray = is_array($ids) ? $ids : explode(',', $ids);
            $idArray = array_filter(array_unique($idArray, SORT_NUMERIC), 'is_numeric');
            
            if (empty($idArray)) {
                return show(500, 'error', '订单ID格式不正确');
            }
            
            try {
                // 开启数据库事务
                Db::startTrans();
                
                // 批量更新订单
                $orderCount = 0;
                $failedIds = [];
                
                foreach ($idArray as $id) {
                    // D3-F3: 事务内行锁读取最新订单，防止并发覆盖 amount_received
                    $order_info = Order::where('id', $id)->lock(true)->find();
                    if ($order_info) {
                        // 记录原始值用于日志
                        $oldValue = $order_info->amount_received;
                        
                        // 更新到账金额
                        $order_info->amount_received = $post_info['dz_number'];
                        // 增加最后更新时间和操作人记录
                        $order_info->update_time = date("Y-m-d H:i:s");
                        $order_info->operator_id = $this->admin_info['id'] ?? 0;
                        
                        // 检查保存是否成功
                        if ($order_info->save() !== false) {
                            $orderCount++;
                            // 记录操作日志
                            trace("订单ID:{$id} 到账金额从 {$oldValue} 更新为 {$post_info['dz_number']}", 'info');
                        } else {
                            $failedIds[] = $id;
                            trace("订单ID:{$id} 更新到账金额失败", 'error');
                        }
                    } else {
                        $failedIds[] = $id;
                    }
                }
                
                // 提交事务
                Db::commit();
                
                // 清除相关缓存（如果有缓存机制）
                if (class_exists('Cache')) {
                    Cache::rm('order_list_' . implode('_', $idArray));
                    Cache::rm('order_stats');
                }
                
                if ($orderCount > 0) {
                    $message = "成功更新{$orderCount}个订单的到账金额";
                    if (!empty($failedIds)) {
                        $message .= "，以下订单更新失败：" . implode(',', $failedIds);
                    }
                    $this->directWriteAdminOperationLog('手动补单', '订单管理', '批量设置实际到账金额：' . (string)$post_info['dz_number'] . '，成功订单数：' . $orderCount . '，订单ID：' . implode(',', $idArray), [
                        'target_type' => 'order',
                    ]);
                    return show(200, 'success', $message);
                } else {
                    return show(500, 'error', '未找到可更新的订单或更新失败');
                }
            } catch (\Throwable $e) {
                // 回滚事务
                Db::rollback();
                trace("更新到账金额异常：" . $e->getMessage(), 'error');
                return show(500, 'error', '操作失败：' . $e->getMessage());
            }

        case 'audit':
            // D3-F1: 订单审核按 complete/cancel 拆分权限
            $auditOrderStatus = (int)($post_info['status'] ?? 0);
            if ($auditOrderStatus === 2 && !$this->authorize('admin.order.recharge.complete')) {
                return $this->directDenyAdminPermission('admin.order.recharge.complete');
            }
            if ($auditOrderStatus === 3 && !$this->authorize('admin.order.recharge.cancel')) {
                return $this->directDenyAdminPermission('admin.order.recharge.cancel');
            }
            if($post_info['type'] == 'status'){
                if((int)$post_info['status'] === 2){
                    try {
                        $this->directCompleteRechargeOrder((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                if((int)$post_info['status'] === 3){
                    try {
                        $this->directRefundRemainingUsdt((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                try {
                    Db::startTrans();
                    $order_info = Order::where('id', $post_info['id'])->lock(true)->find();
                    if (!$order_info) {
                        Db::rollback();
                        return show(404, 'error', '订单不存在');
                    }
                    if (!($order_info['status'] == \app\model\Order::STATUS_PENDING || $order_info['status'] == \app\model\Order::STATUS_PROCESSING)) {
                        Db::rollback();
                        return show(500, 'error', '审核异常');
                    }
                    $oldStatus = (int)($order_info['status'] ?? 0);
                    $oldConfirmStatus = (int)($order_info['confirm_status'] ?? 0);
                    $order_info->status = $post_info['status'];
                    if($order_info['status'] == \app\model\Order::STATUS_COMPLETED){
                        $order_info->confirm_status = 1;
                        $order_info->complete_time = date("Y-m-d H:i:s");
                        // 返佣操作
                        rebate($order_info['order_number']);
                    }
                    $order_info->save();
                    Db::commit();
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin order audit status update failed', [
                        'id' => (int)($post_info['id'] ?? 0),
                        'error' => $e->getMessage(),
                    ]);
                    return show(500, 'error', $e->getMessage());
                }
                $this->directWriteAdminOperationLog('审核订单', '订单管理', '订单号：' . (string)($order_info['order_number'] ?? '') . '，状态：' . $this->directOrderStatusText($oldStatus) . ' -> ' . $this->directOrderStatusText((int)$order_info['status']) . '，确认状态：' . $this->directOrderConfirmStatusText($oldConfirmStatus) . ' -> ' . $this->directOrderConfirmStatusText((int)($order_info['confirm_status'] ?? 0)), [
                        'target_id' => (int)($order_info['id'] ?? 0),
                        'target_type' => 'order',
                    ]);
                    if ((int)$post_info['status'] === 1) {
                        try {
                            (new OrderTelegramNotifier())->notifyProductOrderProcessing($order_info->toArray());
                        } catch (\Throwable $notifyException) {
                            Log::error('product order notify failed', [
                                'order_id' => (int)($order_info['id'] ?? 0),
                                'order_no' => (string)($order_info['order_number'] ?? ''),
                                'uid' => (int)($order_info['uid'] ?? 0),
                                'action' => 'product_order_processing_notify',
                                'error_message' => $notifyException->getMessage(),
                            ]);
                        }
                    }
                    return show(200, 'success', '处理成功');
            }else{
                $order_info = Order::find($post_info['id']);
                if($order_info['status'] == \app\model\Order::STATUS_COMPLETED && $order_info['confirm_status'] == 3){
                    if($post_info['status'] == 2){
                        try {
                            $updatedOrderSnapshot = (new ProductOrderService())->confirmReceipt(
                                (int)$post_info['id'],
                                2,
                                [
                                    'source' => 'admin_audit_confirm',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                ]
                            );
                            $this->directWriteAdminOperationLog('瀹℃牳璁㈠崟', '璁㈠崟绠＄悊', '璁㈠崟鍙凤細' . (string)($updatedOrderSnapshot['order_number'] ?? '') . '锛岀‘璁ょ姸鎬侊細' . $this->directOrderConfirmStatusText(3) . ' -> ' . $this->directOrderConfirmStatusText(2), [
                                'target_id' => (int)($updatedOrderSnapshot['id'] ?? 0),
                                'target_type' => 'order',
                            ]);
                            return show(200, 'success', '澶勭悊鎴愬姛');
                        } catch (\Throwable $e) {
                            return show(500, 'error', $e->getMessage());
                        }
                    }
                    if($post_info['status'] == 3){
                        try {
                            $this->directRefundRemainingUsdt((int)$post_info['id']);
                            return show(200, 'success', '处理成功');
                        } catch (\Throwable $e) {
                            return show(500, 'error', $e->getMessage());
                        }
                    }
                    if($post_info['status'] == 2){
                        $order_info->confirm_status = 2;
                        $order_info->save();
                    }

                    if($post_info['status'] == 3){
                        $order_info->status = \app\model\Order::STATUS_CANCELLED;
                        $order_info->save();
                    }

                    return show(200, 'success', '处理成功');
                }
                return show(500, 'error', '审核异常');      
            }

        case 'query':
            $order_info = Order::find($post_info['id']);
            if($order_info['status'] == \app\model\Order::STATUS_PENDING || $order_info['status'] == \app\model\Order::STATUS_PROCESSING){
                if((int)$post_info['status'] === 2){
                    try {
                        $this->directCompleteRechargeOrder((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                if((int)$post_info['status'] === 3){
                    try {
                        $this->directRefundRemainingUsdt((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                try {
                    Db::startTrans();
                    $lockedOrder = Order::where('id', $post_info['id'])->lock(true)->find();
                    if (!$lockedOrder) {
                        Db::rollback();
                        return show(404, 'error', '订单不存在');
                    }
                    if (!((int)$lockedOrder['status'] === \app\model\Order::STATUS_PENDING || (int)$lockedOrder['status'] === \app\model\Order::STATUS_PROCESSING)) {
                        Db::rollback();
                        return show(500, 'error', '审核异常');
                    }
                    $lockedOrder->status = $post_info['status'];
                    if($lockedOrder['status'] == \app\model\Order::STATUS_COMPLETED){
                        $lockedOrder->confirm_status = 1;
                        $lockedOrder->complete_time = date("Y-m-d H:i:s");
                        
                        // 返佣操作
                        rebate($lockedOrder['order_number']);
                    }
                    $lockedOrder->save();
                    Db::commit();
                    $order_info = $lockedOrder;
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin order query status update failed', [
                        'id' => (int)($post_info['id'] ?? 0),
                        'error' => $e->getMessage(),
                    ]);
                    return show(500, 'error', $e->getMessage());
                }
                if ((int)$post_info['status'] === 1) {
                    try {
                        (new OrderTelegramNotifier())->notifyProductOrderProcessing($order_info->toArray());
                    } catch (\Throwable $notifyException) {
                        Log::error('product order notify failed', [
                            'order_id' => (int)($order_info['id'] ?? 0),
                            'order_no' => (string)($order_info['order_number'] ?? ''),
                            'uid' => (int)($order_info['uid'] ?? 0),
                            'action' => 'product_order_processing_notify',
                            'error_message' => $notifyException->getMessage(),
                        ]);
                    }
                }
                if($post_info['status'] == 3){
                    try {
                        $this->directRefundRemainingUsdt((int)$order_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        Log::error('admin order_post audit refund error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                        return show(500, 'error', '处理失败：' . $e->getMessage());
                    }
                }
                $this->directWriteAdminOperationLog('审核订单', '订单管理', '订单号：' . (string)($order_info['order_number'] ?? '') . '，查询订单状态更新为：' . $this->directOrderStatusText((int)$order_info['status']), [
                    'target_id' => (int)($order_info['id'] ?? 0),
                    'target_type' => 'order',
                ]);
                return show(200, 'success', '处理成功');
            }
            return show(500, 'error', '审核异常');

        case 'example_a':
            if(empty($post_info['ids'])){
                if($post_info['product']){
                    $par[] = ['product_id', '=', substr($post_info['product'], 8)];
                }
                $par[] = ['type', '=', 1];
                $data = Order::where($par)->select();
            }else{
                $data = Order::where('id', 'in', $post_info['ids'])->where('type', 1)->select();
            }
            $customFieldNames = [
                'order_number' => '订单号',
                'product_info' => '产品信息',
                'order_info' => '充值信息',
                'amount_money' => '充值金额',
                'discount_amount' => '折扣金额',
                'discount' => '折扣比例',
                'rate' => '当前费率',
                'cny_amount' => '支付金额',
                'status' => '订单状态',
                'confirm_status' => '确认状态',	
                'create_time' => '创建时间',
            ];
            // 创建PHPExcel对象
            $spreadsheet = new Spreadsheet();
            // 设置自定义字段名为第一行
            $spreadsheet->getActiveSheet()->fromArray(array_map([$this, 'safeExcel'], $customFieldNames), NULL, 'A1');
            // 填充数据
            $rowData = [];
            foreach ($data as $row) {
                $order_info = Order::where('id', $row['id'])->find();
                $order_info->export_status = 1;
                $order_info->save();
                
                if($order_info['status'] == \app\model\Order::STATUS_PENDING){
                    $status = '待充值';
                }if($order_info['status'] == \app\model\Order::STATUS_PROCESSING){
                    $status = '充值中';
                }if($order_info['status'] == \app\model\Order::STATUS_COMPLETED){
                    $status = '已完成';
                }if($order_info['status'] == \app\model\Order::STATUS_CANCELLED){
                    $status = '已取消';
                }
                if($order_info['confirm_status'] == 0){
                    $confirm_status = '未完成';
                }if($order_info['confirm_status'] == 1){
                    $confirm_status = '待确认';
                }if($order_info['confirm_status'] == 2){
                    $confirm_status = '已确认';
                }if($order_info['confirm_status'] == 3){
                    $confirm_status = '未收到';
                }

                $info = '';
                $orderDetails = is_array($order_info['order_info']) ? $order_info['order_info'] : [];
                foreach ($orderDetails as $item) {
                    if (!preg_match('/\[(.*?)\](.*)/', $item, $matches) || count($matches) < 3) {
                        continue;
                    }

                    $fieldValue = trim((string)$matches[2]);
                    $result = checkIfImageExists($fieldValue);
                    if ($result == 1) {
                        $info .= $matches[1] . '：' . url('/')->domain(true) . $fieldValue . '    ';
                    } else {
                        $info .= $matches[1] . '：' . $fieldValue . '    ';
                    }

                    if (phone_info($fieldValue)) {
                        $info .= '运营商：' . phone_info($fieldValue) . '    ';
                        $info .= '话费余额：' . $row['phone_yue_a'] . '    ';
                    }
                }

                $rowData[] = [
                    'order_number' => $this->safeExcel($order_info['order_number']),
                    'product_info' => $this->safeExcel($order_info['product_info']['name']),
                    'order_info' => $this->safeExcel($info),
                    'amount_money' => $this->safeExcel($order_info['amount_money']),
                    'discount_amount' => $this->safeExcel($order_info['discount_amount']),
                    'discount' => $this->safeExcel($order_info['discount']),
                    'rate' => $this->safeExcel($order_info['rate']),
                    'cny_amount' => $this->safeExcel($order_info['cny_amount']),
                    'status' => $this->safeExcel($status),
                    'confirm_status' => $this->safeExcel($confirm_status),	
                    'create_time' => $this->safeExcel($order_info['create_time']),
                ];
            }
            $spreadsheet->getActiveSheet()->fromArray($rowData, NULL, 'A2');
            $downloadUrl = $this->createPrivateExportDownload($spreadsheet, 'order_example_a');
            return show(200, 'success', '执行成功', $downloadUrl);
                
        case 'example_b':
            if(empty($post_info['ids'])){
                if($post_info['product']){
                    $par[] = ['product_id', '=', substr($post_info['product'], 8)];
                }
                $par[] = ['type', '=', 2];
                $data = Order::where($par)->select();
            }else{
                $data = Order::where('id', 'in', $post_info['ids'])->where('type', 2)->select();
            }
            $customFieldNames = [
                'order_number' => '订单号',
                'product_info' => '产品信息',
                'order_info' => '充值信息',
                'rate' => '当前费率',
                'cny_amount' => '支付金额',
                'status' => '订单状态',
                'confirm_status' => '确认状态',	
                'create_time' => '创建时间',
            ];
            // 创建PHPExcel对象
            $spreadsheet = new Spreadsheet();
            // 设置自定义字段名为第一行
            $spreadsheet->getActiveSheet()->fromArray(array_map([$this, 'safeExcel'], $customFieldNames), NULL, 'A1');
            // 填充数据
            $rowData = [];
            foreach ($data as $row) {
                $order_info = Order::where('id', $row['id'])->find();
                $order_info->export_status = 1;
                $order_info->save();
                if($order_info['status'] == \app\model\Order::STATUS_PENDING){
                    $status = '待充值';
                }if($order_info['status'] == \app\model\Order::STATUS_PROCESSING){
                    $status = '充值中';
                }if($order_info['status'] == \app\model\Order::STATUS_COMPLETED){
                    $status = '已完成';
                }if($order_info['status'] == \app\model\Order::STATUS_CANCELLED){
                    $status = '已取消';
                }
                if($order_info['confirm_status'] == 0){
                    $confirm_status = '未完成';
                }if($order_info['confirm_status'] == 1){
                    $confirm_status = '待确认';
                }if($order_info['confirm_status'] == 2){
                    $confirm_status = '已确认';
                }if($order_info['confirm_status'] == 3){
                    $confirm_status = '未收到';
                }

                $info = '';
                $orderDetails = is_array($order_info['order_info']) ? $order_info['order_info'] : [];
                foreach ($orderDetails as $item) {
                    if (!preg_match('/\[(.*?)\](.*)/', $item, $matches) || count($matches) < 3) {
                        continue;
                    }

                    $fieldValue = trim((string)$matches[2]);
                    $result = checkIfImageExists($fieldValue);
                    if ($result == 1) {
                        $info .= $matches[1] . '：' . url('/')->domain(true) . $fieldValue . '    ';
                    } else {
                        $info .= $matches[1] . '：' . $fieldValue . '    ';
                    }

                    if (getTelecomOperator($fieldValue) != '未知') {
                        $info .= '运营商：' . getTelecomOperator($fieldValue) . '    ';
                        $info .= '话费余额：' . $row['phone_yue_a'] . '    ';
                    }
                }

                $rowData[] = [
                    'order_number' => $this->safeExcel($order_info['order_number']),
                    'product_info' => $this->safeExcel($order_info['product_info']['name']),
                    'order_info' => $this->safeExcel($info),
                    'rate' => $this->safeExcel($order_info['rate']),
                    'cny_amount' => $this->safeExcel($order_info['cny_amount']),
                    'status' => $this->safeExcel($status),
                    'confirm_status' => $this->safeExcel($confirm_status),	
                    'create_time' => $this->safeExcel($order_info['create_time']),
                ];
            }
            $spreadsheet->getActiveSheet()->fromArray($rowData, NULL, 'A2');
            $downloadUrl = $this->createPrivateExportDownload($spreadsheet, 'order_example_b');

            return show(200, 'success', '执行成功', $downloadUrl);

        case 'set_amount_received':
            // 验证必要参数
            if (empty($post_info['id']) || !isset($post_info['amount_received'])) {
                return show(500, 'error', '请提供订单ID和实际到账金额');
            }
            
            // 验证金额参数
            if (!is_numeric($post_info['amount_received']) || $post_info['amount_received'] < 0) {
                return show(500, 'error', '请输入有效的实际到账金额');
            }
            if ($post_info['amount_received'] > 99999999.99) {
                return show(500, 'error', '实际到账金额超出合理范围');
            }
            
            // D3-F3: 新增事务 + 行锁 + 操作日志，防止并发覆盖 amount_received
            try {
                Db::startTrans();
                $order_info = Order::where('id', $post_info['id'])->lock(true)->find();
                if (!$order_info) {
                    Db::rollback();
                    return show(500, 'error', '订单不存在');
                }
                $oldAmountReceived = (string)($order_info['amount_received'] ?? '');
                $orderNumber = (string)($order_info['order_number'] ?? '');
                $orderUid = (int)($order_info['uid'] ?? 0);
                // 更新实际到账金额（使用锁后最新订单对象）
                $order_info->amount_received = $post_info['amount_received'];
                $order_info->update_time = date("Y-m-d H:i:s");
                $order_info->operator_id = $this->admin_info['id'] ?? 0;
                if ($order_info->save() === false) {
                    Db::rollback();
                    return show(500, 'error', '更新失败');
                }
                Db::commit();
                // D3-F3: commit 成功后写操作日志
                $this->directWriteAdminOperationLog('设置实际到账金额', '订单管理', '订单号：' . $orderNumber . '，用户UID：' . $orderUid . '，实际到账：' . $oldAmountReceived . ' -> ' . (string)$post_info['amount_received'], [
                    'target_id' => (int)$post_info['id'],
                    'target_type' => 'order',
                ]);
                return show(200, 'success', '实际到账金额设置成功');
            } catch (\Throwable $e) {
                Db::rollback();
                Log::error('admin set_amount_received error: ' . $e->getMessage(), ['id' => ($post_info['id'] ?? 0)]);
                return show(500, 'error', '操作失败');
            }

        case 'batch_set_amount_received':
            // 验证必要参数
            if (empty($post_info['ids']) || !isset($post_info['amount_received'])) {
                return show(500, 'error', '请提供订单ID和实际到账金额');
            }
            
            // 验证金额参数
            if (!is_numeric($post_info['amount_received']) || $post_info['amount_received'] < 0) {
                return show(500, 'error', '请输入有效的实际到账金额');
            }
            if ($post_info['amount_received'] > 99999999.99) {
                return show(500, 'error', '实际到账金额超出合理范围');
            }
            
            // 处理订单ID
            $idArray = explode(',', $post_info['ids']);
            $idArray = array_filter(array_unique($idArray, SORT_NUMERIC), 'is_numeric');
            
            if (empty($idArray)) {
                return show(500, 'error', '订单ID格式不正确');
            }
            
            try {
                // 开启数据库事务
                Db::startTrans();
                
                // 批量更新订单
                $orderCount = 0;
                $failedIds = [];
                
                foreach ($idArray as $id) {
                    // D3-F3: 事务内行锁读取最新订单，防止并发覆盖 amount_received
                    $order_info = Order::where('id', $id)->lock(true)->find();
                    if ($order_info) {
                        // 更新实际到账金额
                        $order_info->amount_received = $post_info['amount_received'];
                        $order_info->update_time = date("Y-m-d H:i:s");
                        $order_info->operator_id = $this->admin_info['id'] ?? 0;
                        
                        // 检查保存是否成功
                        if ($order_info->save() !== false) {
                            $orderCount++;
                            // 记录操作日志
                            trace("订单ID:{$id} 实际到账金额更新为 {$post_info['amount_received']}", 'info');
                        } else {
                            $failedIds[] = $id;
                            trace("订单ID:{$id} 更新实际到账金额失败", 'error');
                        }
                    } else {
                        $failedIds[] = $id;
                    }
                }
                
                // 提交事务
                Db::commit();
                
                if ($orderCount > 0) {
                    $message = "成功更新{$orderCount}个订单的实际到账金额";
                    if (!empty($failedIds)) {
                        $message .= "，以下订单更新失败：" . implode(',', $failedIds);
                    }
                    $this->directWriteAdminOperationLog('手动补单', '订单管理', '批量设置实际到账金额：' . (string)$post_info['amount_received'] . '，成功订单数：' . $orderCount . '，订单ID：' . implode(',', $idArray), [
                        'target_type' => 'order',
                    ]);
                    return show(200, 'success', $message);
                } else {
                    return show(500, 'error', '未找到可更新的订单或更新失败');
                }
            } catch (\Throwable $e) {
                // 回滚事务
                Db::rollback();
                trace("批量更新实际到账金额异常：" . $e->getMessage(), 'error');
                return show(500, 'error', '操作失败：' . $e->getMessage());
            }

        case 'picture_upload':
            try {
                $stored = (new UploadService())->storeImageUpload(
                    (string)$this->request->post('result'),
                    [
                        'directory' => 'storage/picture',
                        'allowed_mimes' => ['image/jpeg', 'image/png'],
                        'empty_message' => '图片上传错误',
                    ]
                );

                $order_info = Order::find($post_info['order_id']);
                $order_info->results = $stored['public_path'];
                $order_info->save();
                return show(200, 'success', '上传保存成功');
            } catch (\Throwable $e) {
                return show(500, 'error', $e->getMessage());
            }
        case 'del':
            // D3-F2: 订单删除独立权限 + 事务 + 行锁 + 已完成禁止删除
            $delOrder = Order::find((int)($post_info['id'] ?? 0));
            if (!$delOrder) {
                return show(404, 'error', '订单不存在');
            }
            $delType = (int)($delOrder['type'] ?? 0);
            $delPerm = $delType === 1 ? 'admin.order.recharge.delete' : ($delType === 2 ? 'admin.order.query.delete' : '');
            if ($delPerm === '' || !$this->authorize($delPerm)) {
                return $this->directDenyAdminPermission($delPerm ?: '订单删除');
            }
            try {
                Db::startTrans();
                $lockedOrder = Order::where('id', (int)$delOrder['id'])->lock(true)->find();
                if (!$lockedOrder) {
                    Db::rollback();
                    return show(404, 'error', '订单不存在');
                }
                // D3-F2: status=2 已完成订单禁止删除（资金已结算/返佣/分站结算/ledger，删除造成审计链断裂）
                if ((int)$lockedOrder['status'] === \app\model\Order::STATUS_COMPLETED) {
                    Db::rollback();
                    return show(500, 'error', '已完成订单不可删除');
                }
                $delOrderNumber = (string)($lockedOrder['order_number'] ?? '');
                $delUid = (int)($lockedOrder['uid'] ?? 0);
                $delAmount = (float)($lockedOrder['amount_money'] ?? 0);
                $delTypeText = $delType === 1 ? '充值业务' : ($delType === 2 ? '查询业务' : '未知');
                Order::destroy((int)$lockedOrder['id']);
                Db::commit();
                $this->directWriteAdminOperationLog('删除订单', '订单管理', '订单号：' . $delOrderNumber . '，用户UID：' . $delUid . '，金额：' . number_format($delAmount, 2) . '，订单类型：' . $delTypeText);
                return show(200, 'success', '删除成功');
            } catch (\Throwable $e) {
                Db::rollback();
                Log::error('admin order_post del error: ' . $e->getMessage(), ['id' => ($post_info['id'] ?? 0)]);
                return show(500, 'error', $e->getMessage() ?: '删除失败');
            }

        case 'dels':
            // D3-F2: 订单批量删除独立权限 + 事务 + 行锁 + 已完成禁止 + 整批原子
            $idsRaw = $post_info['ids'] ?? [];
            $delIds = is_array($idsRaw) ? $idsRaw : explode(',', (string)$idsRaw);
            $delIds = array_filter(array_unique(array_map('intval', $delIds)));
            if (empty($delIds)) {
                return show(500, 'error', '请选择要删除的订单');
            }
            $delOrders = Order::where('id', 'in', $delIds)->field('id,type,order_number,uid,amount_money,status')->select();
            if (count($delOrders) !== count($delIds)) {
                return show(404, 'error', '部分订单不存在');
            }
            foreach ($delOrders as $do) {
                $dt = (int)($do['type'] ?? 0);
                $dp = $dt === 1 ? 'admin.order.recharge.delete' : ($dt === 2 ? 'admin.order.query.delete' : '');
                if ($dp === '' || !$this->authorize($dp)) {
                    return $this->directDenyAdminPermission($dp ?: '订单删除');
                }
            }
            try {
                Db::startTrans();
                $deletedOrderNumbers = [];
                $deletedCount = 0;
                foreach ($delOrders as $do) {
                    $locked = Order::where('id', (int)$do['id'])->lock(true)->find();
                    if (!$locked) {
                        throw new Exception('订单不存在: ' . (int)$do['id']);
                    }
                    if ((int)$locked['status'] === 2) {
                        throw new Exception('订单号 ' . (string)($locked['order_number'] ?? '') . ' 已完成，不可删除');
                    }
                    $deletedOrderNumbers[] = (string)($locked['order_number'] ?? '');
                    Order::destroy((int)$locked['id']);
                    $deletedCount++;
                }
                Db::commit();
                $this->directWriteAdminOperationLog('批量删除订单', '订单管理', '删除数量：' . $deletedCount . '，订单号：' . implode(',', $deletedOrderNumbers));
                return show(200, 'success', '批量删除成功');
            } catch (\Throwable $e) {
                Db::rollback();
                Log::error('admin order_post dels error: ' . $e->getMessage(), ['ids' => $delIds]);
                return show(500, 'error', $e->getMessage() ?: '批量删除失败');
            }
            
        default:
            return show(500, 'error', '你不对劲');
    }
}

    public function withdrawal_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'audit':
                // D3-F1: 提现审核权限按 approve/reject 拆分，view 不再能执行审核
                $auditStatus = (int)($post_info['status'] ?? 0);
                $withdrawPerm = $auditStatus === 1 ? 'admin.withdrawal.approve' : ($auditStatus === 2 ? 'admin.withdrawal.reject' : '');
                if ($withdrawPerm === '' || !$this->authorize($withdrawPerm)) {
                    return $this->directDenyAdminPermission($withdrawPerm ?: '提现审核');
                }
                if (!$this->directValidateRequiredCsrfToken()) {
                    return show(403, 'error', '提现请求校验失败');
                }
                $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'withdrawal_audit');
                if (empty($sensitiveValidation['ok'])) {
                    return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
                }
                if (!in_array($auditStatus, [1, 2], true)) {
                    return show(500, 'error', '审核异常');
                }
                try {
                    Db::startTrans();
                    $withdrawal_info = Withdrawal::where('id', $post_info['id'])->lock(true)->find();
                    if($withdrawal_info && (int)$withdrawal_info['status'] == \app\model\Withdrawal::STATUS_PENDING){
                        $amount = round((float)($withdrawal_info['amount'] ?? 0), 2);
                        if ($amount <= 0) {
                            throw new Exception('提现金额异常');
                        }

                        if($auditStatus === 1){
                            $user_info = $this->directLockUser((int)$withdrawal_info['uid']);
                            if (!$user_info) {
                                throw new Exception('User not found');
                            }
                            // 手续费校验：fee 必须 >= 0 且 <= amount
                            $withdrawalFee = round((float)($withdrawal_info['withdrawal_fee'] ?? 0), 2);
                            if ($withdrawalFee < -0.005) {
                                throw new Exception('提现手续费异常：负数');
                            }
                            if ($withdrawalFee > $amount + 0.005) {
                                throw new Exception('提现手续费异常：超过提现金额');
                            }
                            (new UserFundLedgerService())->changeLockedUserWallet(
                                $user_info,
                                UserFundLedgerService::WALLET_FROZEN,
                                -1 * $amount,
                                [
                                    'biz_type' => 'withdrawal',
                                    'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                    'biz_no' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'change_type' => 'withdraw_deduct',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'withdraw_deduct:' . (string)($withdrawal_info['order_number'] ?? ''),
                                    'remark' => 'withdrawal approved deduct frozen amount',
                                    'idempotent' => true,
                                    'extra' => [
                                        'source' => 'withdrawal_post_audit',
                                        'audit_status' => 1,
                                    ],
                                ]
                            );
                            // 平台手续费记账（纯流水，与审核同一事务，幂等 request_no）
                            if ($withdrawalFee > 0.005) {
                                (new UserFundLedgerService())->recordPlatformIncome($withdrawalFee, [
                                    'biz_type' => 'withdrawal',
                                    'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                    'biz_no' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'change_type' => 'withdrawal_fee_income',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'withdraw_fee:' . (string)($withdrawal_info['order_number'] ?? ''),
                                    'remark' => 'withdrawal platform fee',
                                    'extra' => [
                                        'source' => 'withdrawal_post_audit',
                                        'withdraw_amount' => $amount,
                                        'withdrawal_fee' => $withdrawalFee,
                                        'actual_payout' => round($amount - $withdrawalFee, 2),
                                    ],
                                ]);
                            }
                        }

                        $withdrawal_info->status = $auditStatus;
                        $withdrawal_info->save();

                        if($auditStatus === 2){
                            $user_info = $this->directLockUser((int)$withdrawal_info['uid']);
                            if (!$user_info) {
                                throw new Exception('用户不存在');
                            }
                            $balanceBefore = round((float)($user_info['balance'] ?? 0), 2);
                            $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
                                $user_info,
                                UserFundLedgerService::WALLET_FROZEN,
                                UserFundLedgerService::WALLET_BALANCE,
                                $amount,
                                [
                                    'biz_type' => 'withdrawal',
                                    'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                    'biz_no' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'out_change_type' => 'withdraw_reject_refund',
                                    'in_change_type' => 'withdraw_reject_refund',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'withdraw_reject_refund:' . (string)($withdrawal_info['order_number'] ?? ''),
                                    'remark' => 'withdrawal reject refund',
                                    'idempotent' => true,
                                    'extra' => [
                                        'source' => 'withdrawal_post_audit',
                                        'audit_status' => 2,
                                    ],
                                ]
                            );
                            $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                            $balanceAfter = array_key_exists('balance', $walletSnapshot)
                                ? round((float)($walletSnapshot['balance'] ?? 0), 2)
                                : round((float)($user_info['balance'] ?? ($balanceBefore + $amount)), 2);
                            $this->directWriteBalanceLog([
                                'uid' => (int)($user_info['id'] ?? 0),
                                'scene' => 'withdrawal_reject_refund',
                                'amount' => $amount,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $balanceAfter,
                                'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                'remark' => '后台审核拒绝提现，余额退回',
                                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                            ]);
                        }
                        $withdrawalSnapshot = $withdrawal_info->toArray();
                        Db::commit();
                        if ($auditStatus === 1) {
                            try {
                                (new OrderTelegramNotifier())->notifyWithdrawalSucceeded($withdrawalSnapshot);
                            } catch (\Throwable $notifyException) {
                                Log::error('withdrawal notify failed', [
                                    'withdrawal_id' => (int)($withdrawalSnapshot['id'] ?? 0),
                                    'order_no' => (string)($withdrawalSnapshot['order_number'] ?? ''),
                                    'uid' => (int)($withdrawalSnapshot['uid'] ?? 0),
                                    'action' => 'withdrawal_succeeded_notify',
                                    'error_message' => $notifyException->getMessage(),
                                ]);
                            }
                        }
                        $this->directWriteAdminOperationLog('审核订单', '财务管理', '提现单号：' . (string)($withdrawal_info['order_number'] ?? '') . '，状态更新为：' . ($auditStatus === 1 ? '提现成功' : '提现失败'), [
                            'target_id' => (int)($withdrawal_info['id'] ?? 0),
                            'target_type' => 'withdrawal',
                        ]);
                        return show(200, 'success', '审核成功');
                    }
                    Db::rollback();
                    return show(500, 'error', '审核异常');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin withdrawal_post audit error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                    return show(500, 'error', '审核异常');
                }

            case 'del':
                // D3-F1: 提现删除权限检查
                if (!$this->authorize('admin.withdrawal.delete')) {
                    return $this->directDenyAdminPermission('admin.withdrawal.delete');
                }
                try {
                    Db::startTrans();
                    $withdrawal_info = Withdrawal::where('id', $post_info['id'])->lock(true)->find();
                    if (!$withdrawal_info) {
                        Db::rollback();
                        return show(404, 'error', '提现记录不存在');
                    }
                    // status=0 待审核提现禁止删除：冻结资金尚未处理，删除会导致资金丢失
                    if ((int)$withdrawal_info['status'] === \app\model\Withdrawal::STATUS_PENDING) {
                        Db::rollback();
                        return show(500, 'error', '待审核提现不可删除，请先审核拒绝后再删除');
                    }
                    // status=1(已通过) / status=2(已拒绝) 资金已处理完毕，允许删除
                    $deletedOrderNumber = (string)($withdrawal_info['order_number'] ?? '');
                    $deletedUid = (int)($withdrawal_info['uid'] ?? 0);
                    $deletedAmount = (float)($withdrawal_info['amount'] ?? 0);
                    Withdrawal::destroy((int)$withdrawal_info['id']);
                    Db::commit();
                    $this->directWriteAdminOperationLog('删除提现记录', '财务管理', '提现单号：' . $deletedOrderNumber . '，用户UID：' . $deletedUid . '，金额：' . number_format($deletedAmount, 2));
                    return show(200, 'success', '删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin withdrawal_post del error: ' . $e->getMessage(), ['id' => ($post_info['id'] ?? 0)]);
                    return show(500, 'error', '删除失败');
                }

            case 'dels':
                // D3-F1: 提现批量删除权限检查
                if (!$this->authorize('admin.withdrawal.delete')) {
                    return $this->directDenyAdminPermission('admin.withdrawal.delete');
                }
                try {
                    Db::startTrans();
                    $ids = is_array($post_info['ids'] ?? null) ? $post_info['ids'] : [];
                    if (empty($ids)) {
                        Db::rollback();
                        return show(500, 'error', '请选择要删除的提现记录');
                    }
                    $deletedCount = 0;
                    $deletedOrderNumbers = [];
                    foreach ($ids as $id) {
                        $withdrawal_info = Withdrawal::where('id', (int)$id)->lock(true)->find();
                        if (!$withdrawal_info) {
                            throw new Exception('提现记录不存在: ' . (int)$id);
                        }
                        // 任何一条 status=0 则整批回滚，一条都不删
                        if ((int)$withdrawal_info['status'] === \app\model\Withdrawal::STATUS_PENDING) {
                            throw new Exception('提现单号 ' . (string)($withdrawal_info['order_number'] ?? '') . ' 待审核，不可删除');
                        }
                        $deletedOrderNumbers[] = (string)($withdrawal_info['order_number'] ?? '');
                        Withdrawal::destroy((int)$withdrawal_info['id']);
                        $deletedCount++;
                    }
                    Db::commit();
                    $this->directWriteAdminOperationLog('批量删除提现记录', '财务管理', '删除数量：' . $deletedCount . '，提现单号：' . implode(',', $deletedOrderNumbers));
                    return show(200, 'success', '批量删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin withdrawal_post dels error: ' . $e->getMessage(), ['ids' => ($post_info['ids'] ?? [])]);
                    return show(500, 'error', $e->getMessage() ?: '批量删除失败');
                }
            default:
                return show(500, 'error', '你不对劲');
        }
    }

    public function recharge_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'audit':
                // D3-F1: 充值审核权限按 approve/reject 拆分，view 不再能执行审核
                $auditStatus = (int)($post_info['status'] ?? 0);
                $rechargePerm = $auditStatus === 1 ? 'admin.recharge.approve' : ($auditStatus === 2 ? 'admin.recharge.reject' : '');
                if ($rechargePerm === '' || !$this->authorize($rechargePerm)) {
                    return $this->directDenyAdminPermission($rechargePerm ?: '充值审核');
                }
                if (!$this->directValidateRequiredCsrfToken()) {
                    return show(403, 'error', '充值请求校验失败');
                }
                $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'recharge_audit');
                if (empty($sensitiveValidation['ok'])) {
                    return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
                }
                if (!in_array($auditStatus, [1, 2], true)) {
                    return show(500, 'error', '审核异常');
                }
                try {
                    Db::startTrans();
                    $recharge_info = Recharge::where('id', $post_info['id'])->lock(true)->find();
                    if($recharge_info && (int)$recharge_info['status'] == 1){
                        $amount = round((float)($recharge_info['amount'] ?? 0), 2);
                        if ($amount <= 0) {
                            throw new Exception('充值金额异常');
                        }

                        if($auditStatus === 1){
                            $status = 3;
                        }elseif($auditStatus === 2){
                            $status = 2;
                        } else {
                            $status = (int)$recharge_info['status'];
                        }
                        $recharge_info->status = $status;
                        $recharge_info->save();

                        if($auditStatus === 1){
                            $user_info = $this->directLockUser((int)$recharge_info['uid']);
                            if (!$user_info) {
                                throw new Exception('用户不存在');
                            }
                            $balanceBefore = (float)($user_info['balance'] ?? 0);
                            $ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
                                $user_info,
                                UserFundLedgerService::WALLET_BALANCE,
                                $amount,
                                [
                                    'biz_type' => 'recharge',
                                    'biz_id' => (int)($recharge_info['id'] ?? 0),
                                    'biz_no' => (string)($recharge_info['order_number'] ?? ''),
                                    'order_number' => (string)($recharge_info['order_number'] ?? ''),
                                    'change_type' => 'recharge_manual_paid',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'recharge_manual_paid:' . (string)($recharge_info['order_number'] ?? ''),
                                    'remark' => '后台审核通过充值到账',
                                    'idempotent' => true,
                                    'extra' => [
                                        'source' => 'admin_recharge_audit_paid',
                                        'audit_status' => 1,
                                    ],
                                ]
                            );
                            $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                            $balanceAfter = array_key_exists('balance', $walletSnapshot)
                                ? round((float)($walletSnapshot['balance'] ?? 0), 2)
                                : round((float)($user_info['balance'] ?? ($balanceBefore + $amount)), 2);
                            $this->directWriteBalanceLog([
                                'uid' => (int)($user_info['id'] ?? 0),
                                'scene' => 'recharge_manual_audit_paid',
                                'amount' => $amount,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $balanceAfter,
                                'biz_id' => (int)($recharge_info['id'] ?? 0),
                                'order_number' => (string)($recharge_info['order_number'] ?? ''),
                                'remark' => '后台审核通过充值到账',
                                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                            ]);
                        }
                        $rechargeSnapshot = $recharge_info->toArray();
                        Db::commit();
                        if ($auditStatus === 1) {
                            try {
                                (new OrderTelegramNotifier())->notifyWalletRechargePaid($rechargeSnapshot);
                            } catch (\Throwable $notifyException) {
                                Log::error('wallet recharge notify failed', [
                                    'recharge_id' => (int)($rechargeSnapshot['id'] ?? 0),
                                    'order_no' => (string)($rechargeSnapshot['order_number'] ?? ''),
                                    'uid' => (int)($rechargeSnapshot['uid'] ?? 0),
                                    'action' => 'wallet_recharge_paid_notify',
                                    'error_message' => $notifyException->getMessage(),
                                ]);
                            }
                        }
                        $this->directWriteAdminOperationLog('审核订单', '财务管理', '充值单号：' . (string)($recharge_info['order_number'] ?? '') . '，状态更新为：' . ($auditStatus === 1 ? '充值成功' : '充值失败'), [
                            'target_id' => (int)($recharge_info['id'] ?? 0),
                            'target_type' => 'recharge',
                        ]);
                        return show(200, 'success', '审核成功');
                    }
                    Db::rollback();
                    return show(500, 'error', '审核异常');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin recharge_post audit error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                    return show(500, 'error', '审核异常');
                }

            case 'del':
                // D3-F1: 充值删除权限检查
                if (!$this->authorize('admin.recharge.delete')) {
                    return $this->directDenyAdminPermission('admin.recharge.delete');
                }
                // D3-F2: 新增事务 + 行锁
                try {
                    Db::startTrans();
                    $rechargeInfo = Recharge::where('id', (int)($post_info['id'] ?? 0))->lock(true)->find();
                    if (!$rechargeInfo) {
                        Db::rollback();
                        return show(404, 'error', '充值记录不存在');
                    }
                    // D3-F1: status=1 待审核充值禁止删除（用户可能已付款，删除会导致资金丢失）
                    if ((int)$rechargeInfo['status'] === 1) {
                        Db::rollback();
                        return show(500, 'error', '待审核充值不可删除，请先审核后再操作');
                    }
                    $deletedOrderNumber = (string)($rechargeInfo['order_number'] ?? '');
                    $deletedUid = (int)($rechargeInfo['uid'] ?? 0);
                    $deletedAmount = (float)($rechargeInfo['amount'] ?? 0);
                    Recharge::destroy((int)$rechargeInfo['id']);
                    Db::commit();
                    $this->directWriteAdminOperationLog('删除充值记录', '财务管理', '充值单号：' . $deletedOrderNumber . '，用户UID：' . $deletedUid . '，金额：' . number_format($deletedAmount, 2));
                    return show(200, 'success', '删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin recharge_post del error: ' . $e->getMessage(), ['id' => ($post_info['id'] ?? 0)]);
                    return show(500, 'error', $e->getMessage() ?: '删除失败');
                }

            case 'dels':
                // D3-F1: 充值批量删除权限检查
                if (!$this->authorize('admin.recharge.delete')) {
                    return $this->directDenyAdminPermission('admin.recharge.delete');
                }
                $idsRaw = $post_info['ids'] ?? [];
                $ids = is_array($idsRaw) ? $idsRaw : explode(',', (string)$idsRaw);
                $ids = array_filter(array_unique(array_map('intval', $ids)));
                if (empty($ids)) {
                    return show(500, 'error', '请选择要删除的充值记录');
                }
                // D3-F2: 新增事务 + 行锁 + 整批原子
                try {
                    Db::startTrans();
                    $data = Recharge::where('id', 'in', $ids)->lock(true)->select();
                    if (count($data) !== count($ids)) {
                        Db::rollback();
                        return show(404, 'error', '部分充值记录不存在');
                    }
                    $deletedCount = 0;
                    $deletedOrderNumbers = [];
                    foreach ($data as $vo) {
                        // D3-F1: 任何一条 status=1 待审核则整批回滚
                        if ((int)$vo['status'] === 1) {
                            throw new Exception('充值单号 ' . (string)($vo['order_number'] ?? '') . ' 待审核，不可删除');
                        }
                        $deletedOrderNumbers[] = (string)($vo['order_number'] ?? '');
                        Recharge::destroy((int)$vo['id']);
                        $deletedCount++;
                    }
                    Db::commit();
                    $this->directWriteAdminOperationLog('批量删除充值记录', '财务管理', '删除数量：' . $deletedCount . '，充值单号：' . implode(',', $deletedOrderNumbers));
                    return show(200, 'success', '删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin recharge_post dels error: ' . $e->getMessage(), ['ids' => $ids]);
                    return show(500, 'error', $e->getMessage() ?: '批量删除失败');
                }
                
            default:
                return show(500, 'error', '你不对劲');
        }
    }
    
    
    

    public function bank_card_post(string $action)
    {
        // B05-B: 业务实现已迁移至 admin\BankCard，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\BankCard::class)->bank_card_post($action);
    }

    public function points_post(string $action)
    {
        if (!$this->authorize('admin.points.manage')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        if (!$this->directValidateRequiredCsrfToken()) {
            return show(403, 'error', '请求校验失败');
        }

        $post = $this->request->post();
        switch ($action) {
            case 'config_save':
                return show(400, 'error', '积分配置已下线');

            case 'tasks_save':
                return show(400, 'error', '任务设置已下线');

            case 'exchange_save':
                $notice = trim((string)($post['points_exchange_notice'] ?? ''));
                $decoded = [];

                if (array_key_exists('points_exchange_items', $post)) {
                    $rawItems = trim((string)($post['points_exchange_items'] ?? '[]'));
                    if ($rawItems === '') {
                        $rawItems = '[]';
                    }

                    try {
                        $decoded = json_decode($rawItems, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        return show(400, 'error', '兑换项配置不是有效的 JSON');
                    }

                    if (!is_array($decoded)) {
                        return show(400, 'error', '兑换项配置格式错误，必须是数组');
                    }
                } else {
                    $ids = is_array($post['exchange_id'] ?? null) ? $post['exchange_id'] : [];
                    $types = is_array($post['exchange_type'] ?? null) ? $post['exchange_type'] : [];
                    $titles = is_array($post['exchange_title'] ?? null) ? $post['exchange_title'] : [];
                    $pointsList = is_array($post['exchange_points'] ?? null) ? $post['exchange_points'] : [];
                    $stockList = is_array($post['exchange_stock'] ?? null) ? $post['exchange_stock'] : [];
                    $couponAmounts = is_array($post['exchange_coupon_amount'] ?? null) ? $post['exchange_coupon_amount'] : [];
                    $skuList = is_array($post['exchange_sku'] ?? null) ? $post['exchange_sku'] : [];
                    $descriptionList = is_array($post['exchange_description'] ?? null) ? $post['exchange_description'] : [];
                    $imageList = is_array($post['exchange_image'] ?? null) ? $post['exchange_image'] : [];
                    $enabledList = is_array($post['exchange_enabled'] ?? null) ? $post['exchange_enabled'] : [];
                    $rowCount = max(
                        count($ids),
                        count($types),
                        count($titles),
                        count($pointsList),
                        count($stockList),
                        count($couponAmounts),
                        count($skuList),
                        count($descriptionList),
                        count($imageList),
                        count($enabledList)
                    );

                    for ($index = 0; $index < $rowCount; $index++) {
                        $decoded[] = [
                            'id' => $ids[$index] ?? '',
                            'type' => $types[$index] ?? 'coupon',
                            'title' => $titles[$index] ?? '',
                            'points' => $pointsList[$index] ?? 0,
                            'stock' => $stockList[$index] ?? 0,
                            'coupon_amount' => $couponAmounts[$index] ?? 0,
                            'sku' => $skuList[$index] ?? '',
                            'description' => $descriptionList[$index] ?? '',
                            'image' => $imageList[$index] ?? '',
                            'enabled' => $enabledList[$index] ?? 0,
                        ];
                    }
                }

                $normalizedItems = [];
                foreach ($decoded as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $type = strtolower(trim((string)($item['type'] ?? 'coupon')));
                    if (!in_array($type, ['coupon', 'physical'], true)) {
                        $type = 'coupon';
                    }

                    $title = trim((string)($item['title'] ?? ''));
                    if ($title === '') {
                        $title = ($type === 'physical' ? '实物商品' : '优惠券') . '#' . ((int)$index + 1);
                    }

                    $normalizedItems[] = [
                        'id' => trim((string)($item['id'] ?? ('item_' . ((int)$index + 1)))),
                        'type' => $type,
                        'title' => $title,
                        'points' => max(1, (int)($item['points'] ?? 1)),
                        'stock' => max(0, (int)($item['stock'] ?? 0)),
                        'coupon_amount' => max(0, (int)($item['coupon_amount'] ?? 0)),
                        'sku' => trim((string)($item['sku'] ?? '')),
                        'description' => trim((string)($item['description'] ?? '')),
                        'image' => trim((string)($item['image'] ?? '')),
                        'enabled' => !empty($item['enabled']) ? 1 : 0,
                    ];
                }

                $this->directSaveConfigValue('points_exchange_items', json_encode($normalizedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->directSaveConfigValue('points_exchange_notice', $notice);
                $this->directWriteAdminOperationLog('兑换设置', '积分管理', '更新积分兑换配置');
                return show(200, 'success', '保存成功');

            default:
                return show(500, 'error', '未知操作');
        }
    }

    public function points_exchange_orders_json()
    {
        // B10-21: points_exchange_orders_json 已迁移至 admin\PointsExchangeOrders，旧入口保持兼容转发
        return app(\app\controller\admin\PointsExchangeOrders::class)->points_exchange_orders_json();
    }

    public function points_exchange_order_post(string $action)
    {
        if (!$this->authorize('admin.points.manage')) {
            return $this->directDenyAdminPermission('admin.points.manage');
        }

        if (!$this->directValidateRequiredCsrfToken()) {
            return show(403, 'error', '请求校验失败');
        }

        $post = $this->request->post();
        $id   = max(1, (int)($post['id'] ?? 0));

        $order = Db::name('points_exchange_order')->where('id', $id)->find();
        if (!$order) {
            return show(404, 'error', '兑换订单不存在');
        }

        $now = date('Y-m-d H:i:s');

        switch ($action) {
            case 'fulfill':
                // D3-F3: P2 TOCTOU 修复 — 事务内行锁读取最新订单，重新校验状态
                Db::startTrans();
                try {
                    $lockedOrder = Db::name('points_exchange_order')->where('id', $id)->lock(true)->find();
                    if (!$lockedOrder) {
                        Db::rollback();
                        return show(404, 'error', '兑换订单不存在');
                    }
                    if ((int)$lockedOrder['status'] !== 0) {
                        Db::rollback();
                        return show(400, 'error', '该订单已处理');
                    }
                    // D3-F3: UPDATE 增加 status=0 条件，检查 affected rows
                    $affected = Db::name('points_exchange_order')->where('id', $id)->where('status', 0)->update([
                        'status'      => 1,
                        'remark'      => trim((string)($post['remark'] ?? '')),
                        'update_time' => $now,
                    ]);
                    if ($affected !== 1) {
                        Db::rollback();
                        return show(400, 'error', '该订单已处理');
                    }
                    Db::commit();
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('兑换发放失败: ' . $e->getMessage());
                    return show(500, 'error', '操作失败');
                }
                $this->directWriteAdminOperationLog('兑换发放', '积分管理', '发放兑换订单 #' . $id);
                return show(200, 'success', '已标记为已发放');

            case 'reject':
                $remark = trim((string)($post['remark'] ?? ''));

                // D3-F3: P0 双退修复 — 事务内行锁读取最新订单，禁止事务外状态判断
                Db::startTrans();
                try {
                    $lockedOrder = Db::name('points_exchange_order')->where('id', $id)->lock(true)->find();
                    if (!$lockedOrder) {
                        Db::rollback();
                        return show(404, 'error', '兑换订单不存在');
                    }
                    // D3-F3: 使用锁后最新数据重新校验状态
                    if ((int)$lockedOrder['status'] !== 0) {
                        Db::rollback();
                        return show(400, 'error', '该订单已处理');
                    }
                    // D3-F3: UPDATE 增加 status=0 条件，检查 affected rows 防止重复处理
                    $affected = Db::name('points_exchange_order')->where('id', $id)->where('status', 0)->update([
                        'status'      => 2,
                        'remark'      => $remark,
                        'update_time' => $now,
                    ]);
                    if ($affected !== 1) {
                        Db::rollback();
                        return show(400, 'error', '该订单已处理');
                    }

                    // 退还积分（使用锁后订单数据，保留原子增量防 Lost Update）
                    $refundPoints = max(0, (int)$lockedOrder['points']);
                    if ($refundPoints > 0) {
                        UserModel::where('id', (int)$lockedOrder['uid'])->update([
                            'points_balance' => Db::raw('points_balance + ' . $refundPoints),
                            'month_used'     => Db::raw('GREATEST(0, month_used - ' . $refundPoints . ')'),
                            'update_time'    => $now,
                        ]);

                        Db::name('points_record')->insert([
                            'uid'         => (int)$lockedOrder['uid'],
                            'points'      => $refundPoints,
                            'reason'      => '兑换拒绝退还：' . (string)$lockedOrder['item_title'],
                            'type'        => 'earned',
                            'create_time' => $now,
                        ]);
                    }

                    Db::commit();
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('兑换拒绝失败: ' . $e->getMessage());
                    return show(500, 'error', '操作失败');
                }

                $this->directWriteAdminOperationLog('兑换拒绝', '积分管理', '拒绝兑换订单 #' . $id . ' 并退还积分');
                return show(200, 'success', '已拒绝并退还积分');

            default:
                return show(500, 'error', '未知操作');
        }
    }

    public function points_records_json()
    {
        // B10-05: points_records_json 已迁移至 admin\PointsRecords，旧入口保持兼容转发
        return app(\app\controller\admin\PointsRecords::class)->points_records_json();
    }

    private function directSaveConfigValue(string $key, string $value): void
    {
        $config = ConfigModel::where('k', $key)->find();
        if ($config) {
            $config->v = $value;
            $config->save();
            CacheModel::destroy('config');
            return;
        }

        ConfigModel::create(['k' => $key, 'v' => $value]);
        CacheModel::destroy('config');
    }

    public function transaction_order_post(string $action)
    {
        return app(\app\controller\admin\TransactionOrder::class)->transaction_order_post($action);
    }
    public function rebate_record_post(string $action)
    {
        $post_info = $this->request->post();

        switch ($action) {
            case 'del':
                // D3-F2: 返佣删除独立权限 + 事务 + 行锁
                if (!$this->authorize('admin.rebate.delete')) {
                    return $this->directDenyAdminPermission('admin.rebate.delete');
                }
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                try {
                    Db::startTrans();
                    $record = RebateRecord::where('id', $id)->lock(true)->find();
                    if (!$record) {
                        Db::rollback();
                        return show(500, 'error', '记录不存在');
                    }
                    $uid = (int)($record['uid'] ?? 0);
                    $amount = (string)($record['amount'] ?? '');
                    $orderNumber = (string)($record['order_number'] ?? '');
                    RebateRecord::destroy($id);
                    Db::commit();
                    // 注意：删除返佣记录不回滚已发放的 WALLET_AGENT 资金（Preflight 结论）
                    $this->directWriteAdminOperationLog('删除返佣记录', '返佣记录', '记录ID：' . $id . '，用户UID：' . $uid . '，关联订单号：' . $orderNumber . '，金额：' . $amount);
                    return show(200, 'success', '删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin rebate_record_post del error: ' . $e->getMessage(), ['id' => $id]);
                    return show(500, 'error', '删除失败');
                }

            case 'dels':
                // D3-F2: 返佣批量删除独立权限 + 事务 + 行锁
                if (!$this->authorize('admin.rebate.delete')) {
                    return $this->directDenyAdminPermission('admin.rebate.delete');
                }
                $idsRaw = $post_info['ids'] ?? '';
                if (is_array($idsRaw)) {
                    $ids = array_filter(array_map('intval', $idsRaw), static fn($v) => $v > 0);
                } else {
                    $ids = array_filter(array_map('intval', explode(',', (string)$idsRaw)), static fn($v) => $v > 0);
                }
                if (empty($ids)) {
                    return show(500, 'error', '参数错误');
                }
                try {
                    Db::startTrans();
                    $records = RebateRecord::where('id', 'in', $ids)->lock(true)->select();
                    if (count($records) !== count($ids)) {
                        Db::rollback();
                        return show(500, 'error', '部分记录不存在');
                    }
                    $deletedCount = 0;
                    foreach ($records as $vo) {
                        RebateRecord::destroy((int)$vo['id']);
                        $deletedCount++;
                    }
                    Db::commit();
                    $this->directWriteAdminOperationLog('批量删除返佣记录', '返佣记录', '删除数量：' . $deletedCount . '，记录ID：' . implode(',', $ids));
                    return show(200, 'success', '删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin rebate_record_post dels error: ' . $e->getMessage(), ['ids' => $ids]);
                    return show(500, 'error', '批量删除失败');
                }

            default:
                return show(500, 'error', '你不对劲');
        }
    }
    
    

    public function slide_post(string $action)
    {
        // B05-B: 业务实现已迁移至 admin\Slide，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Slide::class)->slide_post($action);
    }
    
    public function product_post(string $action)
    {
        return app(\app\controller\admin\Product::class)->product_post($action);
    }
    public function user_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'balance':
                return $this->handleBalance($post_info);

            case 'password':
            case 'status_switch':
            case 'twofa_unbind':
            case 'rights':
            case 'dels':
            case 'del':
                return app(\app\controller\admin\User::class)->user_post($action);

            default:
                return show(500, 'error', '你不对劲');
        }
    }

    
    /**
     * 双因素认证相关操作
     */
    public function twofa_post(string $action)
    {
        // B10-32: Auth/Security 业务实现已迁移至 admin\Auth，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Auth::class)->twofa_post($action);
    }


    public function admin_post(string $action)
    {
        // B09: admin_post 的 add_modify / info / del 已迁移至 admin\Admin，旧入口保持兼容转发
        return app(\app\controller\admin\Admin::class)->admin_post($action);
    }


    public function account_post(string $action)
    {
        // B05-B: 业务实现已迁移至 admin\Account，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Account::class)->account_post($action);
    }

    /**
     * 修改登录方法，添加2FA验证
     */
    public function login_check()
    {
        // B10-32: Auth/Security 业务实现已迁移至 admin\Auth，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Auth::class)->login_check();
    }

    // 后台管理员退出登录
    public function logout()
    {
        // B10-32: Auth/Security 业务实现已迁移至 admin\Auth，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Auth::class)->logout();
    }


    // 图片上传（Logo & 二维码）
    public function upload_post()
    {
        // B05-B: 业务实现已迁移至 admin\Upload，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Upload::class)->upload_post();
    }

    public function message_send()
    {
        // B06: 业务实现已迁移至 admin\Message，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Message::class)->message_send();
    }

    public function message_detail()
    {
        // B06: 业务实现已迁移至 admin\Message，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Message::class)->message_detail();
    }

    public function message_pin()
    {
        // B06: 业务实现已迁移至 admin\Message，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Message::class)->message_pin();
    }

    public function message_delete()
    {
        // B06: 业务实现已迁移至 admin\Message，此处反向薄转发（行为等价，Delegation Only）。
        return app(\app\controller\admin\Message::class)->message_delete();
    }

    public function admin_footer(string $action)
    {
        // B05-A: 业务实现已迁移至 admin\Setting 控制器；本入口反向薄转发保持旧路由兼容。
        return app(\app\controller\admin\Setting::class)->admin_footer($action);
    }
    


public function setting_post(string $action)
    {
                if (!$this->authorize('admin.setting.manage')) {
            return $this->directDenyAdminPermission('admin.setting.manage');
        }

switch ($action) {
            case 'setting':
                return $this->handleSetting((array)$this->request->post());
                

            case 'upload':
                return $this->handleSettingUpload();


            default:
                return show(500, 'error', '你不对劲');
        }

    }
}
