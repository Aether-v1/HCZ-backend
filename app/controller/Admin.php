<?php
declare (strict_types=1);
namespace app\controller;
use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\model\User as UserModel;
use app\model\Product;
use app\model\Recharge;
use app\model\RebateRecord;
use app\model\Withdrawal;
use app\model\Order;
use app\model\TransactionOrder;
use app\model\UserMessage;
use app\service\UserMessageService;

use think\App;
use think\facade\View;
use think\facade\Route;
use think\facade\Db;
use think\facade\Session;
use think\Request;

class Admin 
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

    protected mixed $admin_info = [];
    protected string|array|bool $config = [];
    protected array $middleware = [AdminAuth::class];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 将当前登录管理员信息写入至私有属性
        $admin_info = $this->request->session('admin');
        $this->admin_info = is_array($admin_info) ? $admin_info : [];
        $this->config = getConfig();
        $adminCsrfToken = (string)Session::get('_csrf_token', '');
        if ($adminCsrfToken === '') {
            try {
                $adminCsrfToken = bin2hex(random_bytes(32));
            } catch (\Throwable $e) {
                $bytes = openssl_random_pseudo_bytes(32);
                if ($bytes === false) {
                    throw new \RuntimeException('后台CSRF令牌生成失败');
                }
                $adminCsrfToken = bin2hex($bytes);
            }
            Session::set('_csrf_token', $adminCsrfToken);
        }
        View::assign('admin', $this->admin_info);
        View::assign('config', $this->config);
        View::assign('admin_csrf_token', $adminCsrfToken);
        if (isset($this->admin_info['id'])) {
            $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
            View::assign('admin_info', $admin_info);
        }
    }

    public function index()
    {  
        try {
            
            // 充值加款统计（仅统计：加款+已完成状态）
            $addWhere = [
                'status' => 3,          // 订单完成
                'operate_type' => 0     // 加款
            ];
            
            // 今日加款
            $todayAddAmount = Recharge::where($addWhere)
                ->whereDay('create_time')
                ->sum('amount') ?? 0;
            
            // 本月加款
            $monthAddAmount = Recharge::where($addWhere)
                ->whereTime('create_time', 'month')
                ->sum('amount') ?? 0;
            
            // 历史总加款
            $totalAddAmount = Recharge::where($addWhere)
                ->sum('amount') ?? 0;

            // 充值扣款统计（仅统计：扣款+已完成状态）
            $deductWhere = [
                'status' => 3,          // 订单完成
                'operate_type' => 1     // 扣款
            ];
            
            // 今日扣款
            $todayDeductAmount = Recharge::where($deductWhere)
                ->whereDay('create_time')
                ->sum('amount') ?? 0;
            
            // 本月扣款
            $monthDeductAmount = Recharge::where($deductWhere)
                ->whereTime('create_time', 'month')
                ->sum('amount') ?? 0;
            
            // 历史总扣款
            $totalDeductAmount = Recharge::where($deductWhere)
                ->sum('amount') ?? 0;

            // 充值相关变量赋值
            View::assign('balance_zc_jr', number_format($todayAddAmount, 2));       // 今日加款
            View::assign('balance_zc_by', number_format($monthAddAmount, 2));       // 本月加款
            View::assign('balance_zc_s', number_format($totalAddAmount, 2));        // 历史总加款
            View::assign('balance_dk_jr', number_format($todayDeductAmount, 2));    // 今日扣款
            View::assign('balance_dk_by', number_format($monthDeductAmount, 2));    // 本月扣款
            View::assign('balance_dk_s', number_format($totalDeductAmount, 2));     // 历史总扣款
            // 提现统计（包含后台扣款，状态1和2，根据实际业务调整）
            $todayWithdrawalAmount = Withdrawal::whereIn('status', [1, 2])
                ->whereDay('create_time')
                ->sum('amount') ?? 0;
            $monthWithdrawalAmount = Withdrawal::whereIn('status', [1, 2])
                ->whereTime('create_time', 'month')
                ->sum('amount') ?? 0;
            $totalWithdrawalAmount = Withdrawal::whereIn('status', [1, 2])
                ->sum('amount') ?? 0;
            View::assign('withdrawal_jr', number_format($todayWithdrawalAmount, 2));
            View::assign('withdrawal_by', number_format($monthWithdrawalAmount, 2));
            View::assign('withdrawal_s', number_format($totalWithdrawalAmount, 2));
            
            // 订单充值统计
            View::assign('order_jr', Order::whereDay('create_time')->count() ?? 0);
            View::assign('order_by', Order::whereTime('create_time', 'month')->count() ?? 0);
            View::assign('order_s', Order::count() ?? 0);
            
            // 交易订单数量统计
            View::assign('transaction_order_jr', TransactionOrder::whereDay('create_time')->count() ?? 0);
            View::assign('transaction_order_by', TransactionOrder::whereTime('create_time', 'month')->count() ?? 0);
            View::assign('transaction_order_s', TransactionOrder::count() ?? 0);
            
            // 交易订单金额统计
            View::assign('transaction_order_amount_jr', number_format(
                TransactionOrder::whereDay('create_time')->sum('pay_amount') ?? 0, 2
            ));
            View::assign('transaction_order_amount_by', number_format(
                TransactionOrder::whereTime('create_time', 'month')->sum('pay_amount') ?? 0, 2
            ));
            View::assign('transaction_order_amount_s', number_format(
                TransactionOrder::sum('pay_amount') ?? 0, 2
            ));
            
            // 返利统计
            View::assign('rebate_record_jr', number_format(
                RebateRecord::whereDay('create_time')->sum('amount') ?? 0, 2
            ));
            View::assign('rebate_record_by', number_format(
                RebateRecord::whereTime('create_time', 'month')->sum('amount') ?? 0, 2
            ));
            View::assign('rebate_record_s', number_format(
                RebateRecord::sum('amount') ?? 0, 2
            ));

            // 所有账户总余额统计
            $totalUserBalance = UserModel::sum('balance') ?? 0;
            View::assign('total_user_balance', number_format($totalUserBalance, 2));

            // ── 首页扩展统计（只读，不修改业务逻辑/数据库/权限）──
            // 用户总数
            $userTotal = (int)UserModel::count();
            View::assign('user_total', $userTotal);
            // 今日新增用户
            $userTodayNew = (int)UserModel::whereDay('create_time')->count();
            View::assign('user_today_new', $userTodayNew);
            // 待处理统计
            $pendingWithdrawal = (int)Withdrawal::where('status', 0)->count();
            $pendingRecharge = (int)Recharge::where('status', 1)->count();
            $pendingOrderCz = (int)Order::where('status', 0)->where('type', 1)->count();
            $pendingOrderCx = (int)Order::where('status', 0)->where('type', 2)->count();
            $pendingTotal = $pendingWithdrawal + $pendingRecharge + $pendingOrderCz + $pendingOrderCx;
            View::assign('pending_withdrawal', $pendingWithdrawal);
            View::assign('pending_recharge', $pendingRecharge);
            View::assign('pending_order_cz', $pendingOrderCz);
            View::assign('pending_order_cx', $pendingOrderCx);
            View::assign('pending_total', $pendingTotal);
            // 今日订单成功率（已完成/总数，status>0 视为已处理）
            $todayOrderTotal = (int)Order::whereDay('create_time')->count();
            $todayOrderPending = (int)Order::whereDay('create_time')->where('status', 0)->count();
            $todayOrderProcessed = $todayOrderTotal - $todayOrderPending;
            $paymentSuccessRate = $todayOrderTotal > 0 ? round(($todayOrderProcessed / $todayOrderTotal) * 100, 1) : 0;
            View::assign('today_order_total', $todayOrderTotal);
            View::assign('today_order_processed', $todayOrderProcessed);
            View::assign('payment_success_rate', $paymentSuccessRate);
            // 最近订单（最近 8 条，含用户信息）
            $recentOrders = Order::where(function ($query) {
                $query->whereNull('substation_id')->whereOr('substation_id', 0);
            })->order('id', 'desc')->limit(8)->select()->toArray();
            $recentOrdersFormatted = [];
            foreach ($recentOrders as $ro) {
                $roUser = UserModel::field('id,mobile,nickname,surname')->find((int)($ro['uid'] ?? 0));
                $statusMap = [0 => '待处理', 1 => '处理中', 2 => '已完成', 3 => '已取消'];
                $typeMap = [1 => '充值', 2 => '查询'];
                $recentOrdersFormatted[] = [
                    'order_number' => (string)($ro['order_number'] ?? ''),
                    'user_mobile' => $roUser ? ((string)($roUser['mobile'] ?? '') ?: (string)($roUser['nickname'] ?? '') ?: (string)($roUser['surname'] ?? '')) : '未知用户',
                    'user_id' => (int)($ro['uid'] ?? 0),
                    'amount' => isset($ro['cny_amount']) ? (float)$ro['cny_amount'] : (isset($ro['amount_money']) ? (float)$ro['amount_money'] : 0),
                    'type' => (int)($ro['type'] ?? 0),
                    'type_text' => $typeMap[(int)($ro['type'] ?? 0)] ?? '未知',
                    'status' => (int)($ro['status'] ?? 0),
                    'status_text' => $statusMap[(int)($ro['status'] ?? 0)] ?? '未知',
                    'create_time' => (string)($ro['create_time'] ?? ''),
                ];
            }
            View::assign('recent_orders_json', json_encode($recentOrdersFormatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
            // 数据更新时间
            View::assign('data_update_time', date('Y-m-d H:i:s'));
            // 平台净流入（今日加款 - 今日提现 - 今日扣款）
            $todayNetFlow = (float)$todayAddAmount - (float)$todayWithdrawalAmount - (float)$todayDeductAmount;
            View::assign('today_net_flow', number_format($todayNetFlow, 2));

            View::assign('overview_trend_json', $this->buildOverviewTrendJson($addWhere, $deductWhere));
            
        } catch (\Exception $e) {
            
            // 生产环境返回默认值
            View::assign('balance_zc_jr', '0.00');
            View::assign('balance_zc_by', '0.00');
            View::assign('balance_zc_s', '0.00');
            View::assign('balance_dk_jr', '0.00');
            View::assign('balance_dk_by', '0.00');
            View::assign('balance_dk_s', '0.00');
            View::assign('withdrawal_jr', '0.00');
            View::assign('withdrawal_by', '0.00');
            View::assign('withdrawal_s', '0.00');
            View::assign('order_jr', 0);
            View::assign('order_by', 0);
            View::assign('order_s', 0);
            View::assign('transaction_order_jr', 0);
            View::assign('transaction_order_by', 0);
            View::assign('transaction_order_s', 0);
            View::assign('transaction_order_amount_jr', '0.00');
            View::assign('transaction_order_amount_by', '0.00');
            View::assign('transaction_order_amount_s', '0.00');
            View::assign('rebate_record_jr', '0.00');
            View::assign('rebate_record_by', '0.00');
            View::assign('rebate_record_s', '0.00');
            View::assign('total_user_balance', '0.00');
            View::assign('user_total', 0);
            View::assign('user_today_new', 0);
            View::assign('pending_withdrawal', 0);
            View::assign('pending_recharge', 0);
            View::assign('pending_order_cz', 0);
            View::assign('pending_order_cx', 0);
            View::assign('pending_total', 0);
            View::assign('today_order_total', 0);
            View::assign('today_order_processed', 0);
            View::assign('payment_success_rate', 0);
            View::assign('recent_orders_json', '[]');
            View::assign('data_update_time', date('Y-m-d H:i:s'));
            View::assign('today_net_flow', '0.00');
            View::assign('overview_trend_json', '{}');
        }
        
        return View::fetch();
    }

    private function buildOverviewTrendJson(array $addWhere, array $deductWhere): string
    {
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-d');
        $yearStart = date('Y-01-01');
        $yearEnd = date('Y-m-d');

        $payload = [
            'month' => [
                'labels' => $this->buildDateLabels($monthStart, $monthEnd, 'm-d'),
                'fullLabels' => $this->buildDateLabels($monthStart, $monthEnd, 'Y-m-d'),
                'series' => [
                    'balanceRecharge' => $this->buildDailySeries(Recharge::class, 'sum', 'amount', $monthStart, $monthEnd, $addWhere),
                    'withdrawal' => $this->buildDailySeries(Withdrawal::class, 'sum', 'amount', $monthStart, $monthEnd, [], ['status' => [1, 2]]),
                    'balanceDeduct' => $this->buildDailySeries(Recharge::class, 'sum', 'amount', $monthStart, $monthEnd, $deductWhere),
                    'orderCount' => $this->buildDailySeries(Order::class, 'count', '*', $monthStart, $monthEnd),
                    'rebate' => $this->buildDailySeries(RebateRecord::class, 'sum', 'amount', $monthStart, $monthEnd),
                    'tradeCount' => $this->buildDailySeries(TransactionOrder::class, 'count', '*', $monthStart, $monthEnd),
                    'tradeAmount' => $this->buildDailySeries(TransactionOrder::class, 'sum', 'pay_amount', $monthStart, $monthEnd),
                ],
            ],
            'total' => [
                'labels' => $this->buildDateLabels($yearStart, $yearEnd, 'm-d'),
                'fullLabels' => $this->buildDateLabels($yearStart, $yearEnd, 'Y-m-d'),
                'series' => [
                    'balanceRecharge' => $this->buildDailySeries(Recharge::class, 'sum', 'amount', $yearStart, $yearEnd, $addWhere),
                    'withdrawal' => $this->buildDailySeries(Withdrawal::class, 'sum', 'amount', $yearStart, $yearEnd, [], ['status' => [1, 2]]),
                    'balanceDeduct' => $this->buildDailySeries(Recharge::class, 'sum', 'amount', $yearStart, $yearEnd, $deductWhere),
                    'orderCount' => $this->buildDailySeries(Order::class, 'count', '*', $yearStart, $yearEnd),
                    'rebate' => $this->buildDailySeries(RebateRecord::class, 'sum', 'amount', $yearStart, $yearEnd),
                    'tradeCount' => $this->buildDailySeries(TransactionOrder::class, 'count', '*', $yearStart, $yearEnd),
                    'tradeAmount' => $this->buildDailySeries(TransactionOrder::class, 'sum', 'pay_amount', $yearStart, $yearEnd),
                ],
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildDateLabels(string $startDate, string $endDate, string $format): array
    {
        $labels = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current !== false && $end !== false && $current <= $end) {
            $labels[] = date($format, $current);
            $current = strtotime('+1 day', $current);
        }
        return $labels;
    }

    private function buildDailySeries(string $modelClass, string $mode, string $field, string $startDate, string $endDate, array $where = [], array $whereIn = []): array
    {
        $labels = $this->buildDateLabels($startDate, $endDate, 'Y-m-d');
        $values = array_fill_keys($labels, 0.0);
        $query = $modelClass::where($where)
            ->whereBetweenTime('create_time', $startDate . ' 00:00:00', $endDate . ' 23:59:59');

        foreach ($whereIn as $key => $items) {
            $query->whereIn($key, $items);
        }

        $aggregate = $mode === 'count' ? 'COUNT(*)' : 'SUM(' . $field . ')';
        $rows = $query
            ->fieldRaw('DATE(create_time) as day, ' . $aggregate . ' as value')
            ->group('DATE(create_time)')
            ->select();

        foreach ($rows as $row) {
            $day = (string)($row['day'] ?? '');
            if ($day !== '' && array_key_exists($day, $values)) {
                $values[$day] = round((float)($row['value'] ?? 0), 2);
            }
        }

        return array_values($values);
    }

    public function user()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'用户列表') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function message()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'用户列表') == 2){
            abort(403, '权限不足');
        }

        $uid = (int)$this->request->get('uid', 0);
        $rows = Db::name('user_message')
            ->where('is_deleted', 0)
            ->whereRaw("title is not null and title <> ''")
            ->whereRaw("content is not null and content <> ''");

        if ($uid > 0) {
            $rows->where('user_id', $uid);
        }

        $rows = $rows->order('is_pinned', 'desc')->order('id', 'desc')->limit(500)->select()->toArray();

        $messageRows = [];
        foreach ($rows as $message) {
            $user = UserModel::field('id,mobile,nickname,surname')->find((int)($message['user_id'] ?? 0));
            $sender = AdminModel::field('id,name,account')->find((int)($message['sender_admin_id'] ?? 0));

            $messageRows[] = [
                'id' => (int)($message['id'] ?? 0),
                'user_id' => (int)($message['user_id'] ?? 0),
                'title' => (string)($message['title'] ?? ''),
                'summary' => UserMessageService::buildSummary((string)($message['summary'] ?? ''), (string)($message['content'] ?? '')),
                'content' => (string)($message['content'] ?? ''),
                'source_type' => (string)($message['source_type'] ?? 'admin'),
                'message_type' => (string)($message['message_type'] ?? 'official'),
                'action_type' => (string)($message['action_type'] ?? 'none'),
                'action_value' => (string)($message['action_value'] ?? ''),
                'is_pinned' => (int)($message['is_pinned'] ?? 0),
                'is_read' => (int)($message['is_read'] ?? 0),
                'sender_admin_id' => (int)($message['sender_admin_id'] ?? 0),
                'created_at' => (string)($message['created_at'] ?? ''),
                'user_info' => $user ? [
                    'id' => (int)($user['id'] ?? 0),
                    'mobile' => (string)($user['mobile'] ?? ''),
                    'nickname' => (string)($user['nickname'] ?? ''),
                    'surname' => (string)($user['surname'] ?? ''),
                    'account' => (string)($user['mobile'] ?? $user['nickname'] ?? $user['surname'] ?? ''),
                ] : null,
                'sender_admin_info' => $sender ? [
                    'id' => (int)($sender['id'] ?? 0),
                    'name' => (string)($sender['name'] ?? ''),
                    'account' => (string)($sender['account'] ?? ''),
                ] : null,
            ];
        }

        $messageRowsJson = json_encode(
            $messageRows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($messageRowsJson === false) {
            $messageRowsJson = '[]';
        }

        View::assign('message_rows_json', $messageRowsJson);
        return View::fetch();
    }
    
    public function slide()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'首页轮播图') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }
    
    public function product_cz()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'充值业务 - 产品列表') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function product_cx()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'查询业务 - 产品列表') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function order_cz()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'充值业务 - 订单列表') == 2){
            abort(403, '权限不足');
        }
      
        View::assign('product_list', Product::where('type', 1)->select());
     
        return View::fetch();
    }

    public function order_cx()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'查询业务 - 订单列表') == 2){
            abort(403, '权限不足');
        }
        View::assign('product_list', Product::where('type', 2)->select());
        return View::fetch();
    }

    public function recharge()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'充值订单记录') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function points_config()
    {
        $this->assertPointsAdminAccess();
        return redirect(getConfig('backstage_entrance') . '/points_exchange');
    }

    public function points_tasks()
    {
        $this->assertPointsAdminAccess();
        return redirect(getConfig('backstage_entrance') . '/points_exchange');
    }

    public function points_records()
    {
        $this->assertPointsAdminAccess();
        return View::fetch();
    }

    public function points_exchange()
    {
        $this->assertPointsAdminAccess();
        $rawItems = (string)(getConfig('points_exchange_items') ?: '[]');
        $decodedItems = json_decode($rawItems, true);
        if (!is_array($decodedItems)) {
            $decodedItems = [];
        }
        View::assign('points_exchange_items', $decodedItems);
        View::assign('points_exchange_notice', (string)(getConfig('points_exchange_notice') ?: '兑换申请提交后，客服会尽快处理。'));
        return View::fetch();
    }

    public function points_exchange_orders()
    {
        $this->assertPointsAdminAccess();
        return View::fetch();
    }

    private function assertPointsAdminAccess(): void
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if ((int)($admin_info['id'] ?? 0) !== 1 && power((string)($admin_info['power'] ?? ''), '积分管理') == 2) {
            abort(403, '权限不足');
        }
    }

    public function withdrawal()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'提现订单记录') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function transaction_product()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'交易挂单数据') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function transaction_order()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'交易订单数据') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function bank_card()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'支付管理') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function user_t()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'用户列表') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function rebate_record()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'返佣记录') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }
    
    
    public function setting()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'系统设置管理') == 2){
            abort(403, '权限不足');
        }
        View::assign('product_list', Product::where('type', 1)->select());
        return View::fetch();
    }
    
    public function account()
    {
        return View::fetch();
    }
    
    public function admin()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        if(power($admin_info['power'],'管理员列表') == 2){
            abort(403, '权限不足');
        }
        return View::fetch();
    }

    public function operation_log()
    {
        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
        $isRootAdmin = (int)($admin_info['id'] ?? 0) === 1;
        if (!$isRootAdmin && power($admin_info['power'], '操作记录') == 2) {
            abort(403, '权限不足');
        }
        return View::fetch('admin/operation_log');
    }
    
    public function login()
    {
        if (!empty($this->request->session('admin.login_ip')) && $this->request->session('admin.login_ip') === $this->request->ip()) {
            return redirect(getConfig('backstage_entrance'));
        }
        return View::fetch();
    }
    

    public function substation_apply()
    {
        return View::fetch('admin/substation_apply');
    }

    public function substation_profile_audit()
    {
        return View::fetch('admin/substation_profile_audit');
    }

    public function substation_manage()
    {
        return View::fetch('admin/substation_manage');
    }

    public function substation_order()
    {
        return View::fetch('admin/substation_order');
    }

    public function substation_income()
    {
        return View::fetch('admin/substation_income');
    }

}
