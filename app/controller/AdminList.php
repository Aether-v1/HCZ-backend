<?php
declare (strict_types=1);
namespace app\controller;
use app\middleware\AdminAuth;

use app\model\Admin as AdminModel;
use app\model\AdminOperationLog;
use app\model\User as UserModel;
use app\model\BankCard;
use app\model\Order;
use app\model\Product;
use app\model\Slide;
use app\model\Recharge;
use app\model\RebateRecord;
use app\model\Withdrawal;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\UserMessage;
use app\service\UserMessageService;

use think\App;
use think\facade\View;
use think\facade\Cache;
use think\facade\Log;
use think\facade\Db;
use think\Request;
use Yurun\Util\HttpRequest;

if (!function_exists(__NAMESPACE__ . '\e')) {
    function e($str): string
    {
        // 防存储型 XSS：统一对后台列表中的动态文本做 HTML 转义。
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

class AdminList
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

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 将当前登录管理员信息写入至私有属性
        $this->admin_info = $this->request->session('admin');
        $this->config = getConfig();
    }

    private function listPayload(): array
    {
        return $this->request->isPost()
            ? (array)$this->request->post()
            : (array)$this->request->get();
    }

    private function payloadValue(array $payload, array $path, mixed $default = null): mixed
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function payloadString(array $payload, array $path, string $default = ''): string
    {
        $value = $this->payloadValue($payload, $path, $default);
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    private function payloadInt(array $payload, array $path, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = $this->payloadValue($payload, $path, $default);
        $intValue = is_numeric($value) ? (int)$value : $default;

        if ($min !== null && $intValue < $min) {
            $intValue = $min;
        }
        if ($max !== null && $intValue > $max) {
            $intValue = $max;
        }

        return $intValue;
    }

    private function payloadDateRange(array $payload, array $path): array
    {
        $raw = $this->payloadString($payload, $path);
        if ($raw === '') {
            return [];
        }

        $dates = preg_split('/\s+至\s+/u', $raw);
        if (!is_array($dates) || count($dates) < 2) {
            return [];
        }

        $start = trim((string)($dates[0] ?? ''));
        $end = trim((string)($dates[1] ?? ''));
        if ($start === '' || $end === '') {
            return [];
        }

        return [$start, $end];
    }

    private function datatablesPagination(array $payload): array
    {
        $start = $this->payloadInt($payload, ['start'], 0, 0);
        $length = $this->payloadInt($payload, ['length'], 10, 1, 200);

        return [$start, $length];
    }

    private function datatablesSearch(array $payload): string
    {
        return $this->payloadString($payload, ['search', 'value']);
    }

    private function extractTrailingInt(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        if (preg_match('/(\d+)$/', $value, $matches)) {
            return (int)$matches[1];
        }

        return is_numeric($value) ? (int)$value : 0;
    }

    private function datatablesResponse(array $result, ?array $payload = null)
    {
        $payload = $payload ?? $this->listPayload();
        $result['draw'] = $this->payloadInt($payload, ['draw'], (int)($result['draw'] ?? 0), 0);
        $result['recordsTotal'] = (int)($result['recordsTotal'] ?? 0);
        $result['recordsFiltered'] = (int)($result['recordsFiltered'] ?? $result['recordsTotal'] ?? 0);
        if (!isset($result['data'])) {
            $result['data'] = [];
        } elseif (is_array($result['data'])) {
            $result['data'] = array_values($result['data']);
        } elseif (is_object($result['data']) && method_exists($result['data'], 'toArray')) {
            $result['data'] = array_values((array)$result['data']->toArray());
        } elseif ($result['data'] instanceof \Traversable) {
            $result['data'] = array_values(iterator_to_array($result['data']));
        } else {
            $result['data'] = [];
        }

        return json($result);
    }

    private function directHasAdminPermission(string $permission): bool
    {
        $adminId = (int)($this->admin_info['id'] ?? 0);
        if ($adminId === 1) {
            return true;
        }
        if ($adminId <= 0) {
            return false;
        }
        return power((string)($this->admin_info['power'] ?? ''), $permission) != 2;
    }

    private function directDenyAdminPermission(string $permission)
    {
        Log::warning('admin permission denied', [
            'admin_id' => (int)($this->admin_info['id'] ?? 0),
            'permission' => $permission,
            'ip' => (string)$this->request->ip(),
            'path' => (string)$this->request->pathinfo(),
        ]);
        return show(403, 'error', '权限不足');
    }

    private function resolveOrder(array $payload, string $default = 'id', array $allowedColumns = []): array
    {
        $column = $default;
        $dir = 'desc';
        $allowedColumns = $this->normalizeAllowedOrderColumns($default, $allowedColumns);

        $orderColumnIndex = $this->payloadValue($payload, ['order', 0, 'column']);
        if (is_numeric($orderColumnIndex)) {
            $requestedColumn = $this->payloadString($payload, ['columns', (int)$orderColumnIndex, 'data']);
            $requestedDir = strtolower($this->payloadString($payload, ['order', 0, 'dir'], 'desc'));

            if ($requestedColumn !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $requestedColumn) === 1) {
                if (in_array($requestedColumn, $allowedColumns, true)) {
                    $column = $requestedColumn;
                }
            }

            $dir = $requestedDir === 'asc' ? 'asc' : 'desc';
        }

        return [$column, $dir];
    }

    private function normalizeAllowedOrderColumns(string $default, array $allowedColumns = []): array
    {
        $normalized = [];
        foreach ($allowedColumns as $allowedColumn) {
            $allowedColumn = trim((string)$allowedColumn);
            if ($allowedColumn !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $allowedColumn) === 1) {
                $normalized[] = $allowedColumn;
            }
        }

        if ($normalized === []) {
            // 防非法排序字段触发 SQL 异常：未显式传白名单时，只允许通用安全排序字段，其他情况回退默认字段。
            $normalized = [$default, 'id', 'create_time', 'update_time', 'addtime', 'submit_time', 'cancel_time', 'sort', 'status'];
        } else {
            $normalized[] = $default;
        }

        return array_values(array_unique($normalized));
    }

    private function safeOrderInfoImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // 防存储型 XSS：图片地址只允许 http/https，阻断危险协议注入到 img src。
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }
    
    

    public function user_list()
    {
        // F13：用户列表为 PII 敏感接口，必须细粒度权限码（超管 id=1 直通）
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $search = $this->datatablesSearch($payload);
        $par[] = ['id|mobile|nickname', 'like', '%' . $search . '%'];
        $data = UserModel::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
            $vo['t_user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['tid_1']??'');
        }
        $totalRecords = (int)UserModel::count();
        $totalDisplay = (int)UserModel::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }
    

    public function product_list()
    {
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $type = $this->payloadInt($payload, ['type'], 0, 0);
        // F13：产品列表按业务类型区分权限码（type 1=充值业务 / 2=查询业务）
        $productPermission = $type === 1 ? '充值业务 - 产品列表' : ($type === 2 ? '查询业务 - 产品列表' : '');
        if ($productPermission === '' || !$this->directHasAdminPermission($productPermission)) {
            return $this->directDenyAdminPermission($productPermission !== '' ? $productPermission : '充值业务 - 产品列表');
        }
        $search = $this->datatablesSearch($payload);

        $par[] = ['type', '=', $type];
        $par[] = ['name|describe', 'like', '%' . $search . '%'];
        $data = Product::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
    
        }
        $totalRecords = (int)Product::where('type', $type)->count();
        $totalDisplay = (int)Product::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }
    

    public function slide_list()
    {
        // F13
        if (!$this->directHasAdminPermission('首页轮播图')) {
            return $this->directDenyAdminPermission('首页轮播图');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $search = $this->datatablesSearch($payload);
        $par[] = ['id', 'like', '%' . $search . '%'];
        $data = Slide::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
    
        }
        $totalRecords = (int)Slide::count();
        $totalDisplay = (int)Slide::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }
    

    public function recharge_list()
    {
        // F13
        if (!$this->directHasAdminPermission('充值订单记录')) {
            return $this->directDenyAdminPermission('充值订单记录');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $basePar = [];
        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $par = $basePar;
        $par[] = ['order_number|wallet_address', 'like', '%' . $search . '%'];
        $data = Recharge::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
            $vo['user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['uid']);
            $vo['image'] = !empty($vo['image']) ? '/api/proof/recharge/' . rawurlencode((string)($vo['order_number'] ?? '')) . '/view' : '';

        }
        $totalRecords = (int)Recharge::where($basePar)->count();
        $totalDisplay = (int)Recharge::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }
    

    public function withdrawal_list()
    {
        // F13
        if (!$this->directHasAdminPermission('提现订单记录')) {
            return $this->directDenyAdminPermission('提现订单记录');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $basePar = [];
        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $par = $basePar;
        $par[] = ['order_number|wallet_address', 'like', '%' . $search . '%'];
        $data = Withdrawal::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
            $vo['user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['uid']);
        }
        $totalRecords = (int)Withdrawal::where($basePar)->count();
        $totalDisplay = (int)Withdrawal::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }
    

    public function order_cz_list()
    {
        // F13：充值业务订单列表
        if (!$this->directHasAdminPermission('充值业务 - 订单列表')) {
            return $this->directDenyAdminPermission('充值业务 - 订单列表');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $productFilter = $this->extractTrailingInt($this->payloadString($payload, ['columns', 3, 'search', 'value']));
        $statusFilter = $this->extractTrailingInt($this->payloadString($payload, ['columns', 12, 'search', 'value']));
        $dates = $this->payloadDateRange($payload, ['columns', 15, 'search', 'value']);
        $basePar = [];

        if ($productFilter > 0) {
            $par[] = ['product_id', '=', $productFilter];
        }
        if ($statusFilter > 0) {
            $par[] = ['status', '=', $statusFilter];
        }

        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $basePar[] = ['type', '=', 1];
        $par = array_merge($basePar, $par ?? []);
        $par[] = ['order_number|product_info|order_info', 'like', '%' . $search . '%'];
        $data = Order::where($par)->where(function ($query) {
            $query->whereNull('substation_id')->whereOr('substation_id', 0);
        })->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        // 判断时间
        if (!empty($dates)) {
            if (!empty($dates[0]) && !empty($dates[1])) {
                $data = Order::where($par)->where(function ($query) {
                    $query->whereNull('substation_id')->whereOr('substation_id', 0);
                })->whereTime('create_time', 'between', [$dates[0], $dates[1]])->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
            }
        }
        foreach($data as $key => $vo) {
            $vo['user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['uid']);

            $order_info = '';
            foreach ($vo['order_info'] as $item) {
                if (preg_match('/\[(.*?)\](.*)/', $item, $matches)) {
                    $allowedExtensions = array('jpg', 'jpeg', 'png', 'gif');
                    $orderInfoLabel = e($matches[1] ?? '');
                    $orderInfoValue = (string)($matches[2] ?? '');
                    $safeImageUrl = $this->safeOrderInfoImageUrl($orderInfoValue);

                    if (in_array(strtolower(pathinfo($orderInfoValue, PATHINFO_EXTENSION)), $allowedExtensions, true) && $safeImageUrl !== '') {
                        // 防存储型 XSS：使用 data-* 传递已校验图片地址，避免把原始内容直接拼进 onclick 和 img src。
                        $order_info .= $orderInfoLabel . '：
                        <div class="symbol symbol-50px" data-order-info-image="' . e($safeImageUrl) . '" onclick="picture_views_modal(this.dataset.orderInfoImage)">
                            <img src="' . e($safeImageUrl) . '" alt="">
                        </div><br>';
                    } else {
                        $order_info .= $orderInfoLabel . '：' . e($orderInfoValue) . '<br>';
                        if (phone_info($orderInfoValue)) {
                            // 防存储型 XSS：附加展示的运营商和余额文本同样走输出转义。
                            $order_info .= '运营商：' . e((string)phone_info($orderInfoValue, 1)) . '<br>';
                            $order_info .= '下单前余额：' . e((string)($vo['phone_yue_a'] ?? '')) . '<br>';
                        }
                    }

                    // $result = checkIfImageExists(url('/')->domain(true) . $matches[2]);
                    // if ($result == 1) {
                    //     $order_info .= $matches[1] . '：
                    //     <div class="symbol symbol-50px" onclick="picture_views_modal(`'.$matches[2].'`)">
                    //         <img src="' . $matches[2] . '" alt="">
                    //     </div><br>';
                    // } else {
                    //     $order_info .= $matches[1] . '：' . $matches[2] . '<br>';
                    //     if(getTelecomOperator($matches[2]) != '未知'){
                    //         $order_info .= '运营商：' . getTelecomOperator($matches[2], 1) . '<br>';
                            
                    //         $order_info .= '下单前余额：' . $vo['phone_yue_a'] . '<br>';
                            
                    //     }
                    // }
                }
            }
            
            $vo['order_info'] = $order_info;

        }
        $totalRecords = (int)Order::where($basePar)->where(function ($query) {
            $query->whereNull('substation_id')->whereOr('substation_id', 0);
        })->count();
        $totalDisplay = (int)Order::where($par)->where(function ($query) {
            $query->whereNull('substation_id')->whereOr('substation_id', 0);
        })->count();
        // 判断时间
        if (!empty($dates)) {
            if (!empty($dates[0]) && !empty($dates[1])) {
                $totalDisplay = (int)Order::where($par)->where(function ($query) {
                    $query->whereNull('substation_id')->whereOr('substation_id', 0);
                })->whereTime('create_time', 'between', [$dates[0], $dates[1]])->count();
            }
        }
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function order_cx_list()
    {
        // F13：查询业务订单列表
        if (!$this->directHasAdminPermission('查询业务 - 订单列表')) {
            return $this->directDenyAdminPermission('查询业务 - 订单列表');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $productFilter = $this->extractTrailingInt($this->payloadString($payload, ['columns', 3, 'search', 'value']));
        $statusFilter = $this->extractTrailingInt($this->payloadString($payload, ['columns', 8, 'search', 'value']));
        $dates = $this->payloadDateRange($payload, ['columns', 10, 'search', 'value']);
        $basePar = [];
        if ($productFilter > 0) {
            $par[] = ['product_id', '=', $productFilter];
        }
        if ($statusFilter > 0) {
            $par[] = ['status', '=', $statusFilter];
        }
        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $basePar[] = ['type', '=', 2];
        $par = array_merge($basePar, $par ?? []);
        $par[] = ['order_number|product_info|order_info', 'like', '%' . $search . '%'];
        $data = Order::where($par)->where(function ($query) {
            $query->whereNull('substation_id')->whereOr('substation_id', 0);
        })->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        // 判断时间
        if (!empty($dates)) {
            if (!empty($dates[0]) && !empty($dates[1])) {
                $data = Order::where($par)->where(function ($query) {
                    $query->whereNull('substation_id')->whereOr('substation_id', 0);
                })->whereTime('create_time', 'between', [$dates[0], $dates[1]])->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
            }
        }
        foreach($data as $key => $vo) {
            $vo['user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['uid']);

            $order_info = '';
            foreach ($vo['order_info'] as $item) {
                if (preg_match('/\[(.*?)\](.*)/', $item, $matches)) {
                    if($matches[1] == "理财信息"){
                        // 防存储型 XSS：order_info 派生到 HTML 文本列时统一做输出转义。
                        $vo['clue'] = e($matches[2] ?? '');
                    }
                    if($matches[1] == "上传图片"){
                        // 防存储型 XSS：图片列仅保留 http/https 地址，阻断危险协议进入 img src。
                        $vo['image'] = $this->safeOrderInfoImageUrl((string)($matches[2] ?? ''));
                    }
                }
            }
        }
        $totalRecords = (int)Order::where($basePar)->where(function ($query) {
            $query->whereNull('substation_id')->whereOr('substation_id', 0);
        })->count();
        $totalDisplay = (int)Order::where($par)->where(function ($query) {
            $query->whereNull('substation_id')->whereOr('substation_id', 0);
        })->count();
        // 判断时间
        if (!empty($dates)) {
            if (!empty($dates[0]) && !empty($dates[1])) {
                $totalDisplay = (int)Order::where($par)->where(function ($query) {
                    $query->whereNull('substation_id')->whereOr('substation_id', 0);
                })->whereTime('create_time', 'between', [$dates[0], $dates[1]])->count();
            }
        }
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function transaction_product_list()
    {
        // F13
        if (!$this->directHasAdminPermission('交易挂单数据')) {
            return $this->directDenyAdminPermission('交易挂单数据');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $search = $this->datatablesSearch($payload);
        $par[] = ['id', 'like', '%' . $search . '%'];
        $data = TransactionProduct::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();

        // 批量预加载用户信息（限制字段，避免敏感数据泄露；消除 N+1）
        $uids = array_unique(array_filter(array_map('intval', array_column($data->toArray(), 'uid'))));
        $userMap = [];
        if (!empty($uids)) {
            $users = UserModel::whereIn('id', $uids)->field('id,avatar,nickname,mobile')->select();
            foreach ($users as $u) {
                $userMap[(int)$u['id']] = $u;
            }
        }
        foreach($data as $key => $vo) {
            $vo['user_info'] = $userMap[(int)$vo['uid']] ?? null;
        }
        $totalRecords = (int)TransactionProduct::count();
        $totalDisplay = (int)TransactionProduct::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function transaction_order_list()
    {
        // F13
        if (!$this->directHasAdminPermission('交易订单数据')) {
            return $this->directDenyAdminPermission('交易订单数据');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $basePar = [];
        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $par = $basePar;
        $par[] = ['order_number', 'like', '%' . $search . '%'];
        $data = TransactionOrder::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();

        // 批量预加载买家+卖家用户信息（限制字段，避免敏感数据泄露；消除 2N 查询）
        $allUids = [];
        foreach ($data as $vo) {
            $buyerUid = (int)($vo['uid'] ?? 0);
            $sellerUid = (int)($vo['sell_uid'] ?? 0);
            if ($buyerUid > 0) { $allUids[] = $buyerUid; }
            if ($sellerUid > 0) { $allUids[] = $sellerUid; }
        }
        $allUids = array_unique($allUids);
        $userMap = [];
        if (!empty($allUids)) {
            $users = UserModel::whereIn('id', $allUids)->field('id,avatar,nickname,mobile')->select();
            foreach ($users as $u) {
                $userMap[(int)$u['id']] = $u;
            }
        }
        foreach($data as $key => $vo) {
            $vo['user_info'] = $userMap[(int)($vo['uid'] ?? 0)] ?? null;
            $vo['sell_uid_info'] = $userMap[(int)($vo['sell_uid'] ?? 0)] ?? null;
            $vo['voucher_image'] = !empty($vo['voucher_image']) ? '/api/proof/trade/' . rawurlencode((string)($vo['order_number'] ?? '')) . '/view' : '';
        }
        $totalRecords = (int)TransactionOrder::where($basePar)->count();
        $totalDisplay = (int)TransactionOrder::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function bank_card_list()
    {
        // F13：银行卡为支付/PII 敏感数据，映射「支付管理」权限码
        if (!$this->directHasAdminPermission('支付管理')) {
            return $this->directDenyAdminPermission('支付管理');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $basePar = [];
        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $par = $basePar;
        $par[] = ['name|mobile|wx_account|zfb_account', 'like', '%' . $search . '%'];
        $data = BankCard::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
            $vo['user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['uid']);

        }
        $totalRecords = (int)BankCard::where($basePar)->count();
        $totalDisplay = (int)BankCard::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function user_t_list()
    {
        // F13：团队/下级用户列表含用户 PII，映射「用户列表」权限码
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $basePar[] = ['tid_1|tid_2|tid_3|tid_4|tid_5|tid_6|tid_7|tid_8|tid_9|tid_10', 'like', $uid];
        $par = $basePar;
        $par[] = ['id', 'like', '%' . $search . '%'];
        $data = UserModel::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
            if($vo['tid_1'] == $uid){
                $vo['type'] = '一级';
            }
            if($vo['tid_2'] == $uid){
                $vo['type'] = '二级';
            }
            if($vo['tid_3'] == $uid){
                $vo['type'] = '三级';
            }
            if($vo['tid_4'] == $uid){
                $vo['type'] = '四级';
            }
            if($vo['tid_5'] == $uid){
                $vo['type'] = '五级';
            }
            if($vo['tid_6'] == $uid){
                $vo['type'] = '六级';
            }
            if($vo['tid_7'] == $uid){
                $vo['type'] = '七级';
            }
            if($vo['tid_8'] == $uid){
                $vo['type'] = '八级';
            }
            if($vo['tid_9'] == $uid){
                $vo['type'] = '九级';
            }
            if($vo['tid_10'] == $uid){
                $vo['type'] = '十级';
            }
        }
        $totalRecords = (int)UserModel::where($basePar)->count();
        $totalDisplay = (int)UserModel::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function rebate_record_list()
    {
        // F13
        if (!$this->directHasAdminPermission('返佣记录')) {
            return $this->directDenyAdminPermission('返佣记录');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        $search = $this->datatablesSearch($payload);
        $basePar = [];
        if ($uid > 0) {
            $basePar[] = ['uid', '=', $uid];
        }
        $par = $basePar;
        $par[] = ['order_number', 'like', '%' . $search . '%'];
        $data = RebateRecord::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->limit($start, $length)->select();
        foreach($data as $key => $vo) {
            $vo['user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['uid']);
        }
        foreach($data as $key => $vo) {
            $vo['t_user_info'] = UserModel::field('id,avatar,nickname,mobile')->find($vo['tid']);
        }
        $totalRecords = (int)RebateRecord::where($basePar)->count();
        $totalDisplay = (int)RebateRecord::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }


    public function message_list()
    {
        // F13：站内消息含用户维度数据，映射「用户列表」权限码（不新增权限字符串，避免破坏现有权限模型）
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        $payload = $this->listPayload();
        $column = 'id';
        $dir = 'desc';
        $allowedColumns = ['id', 'user_id', 'title', 'message_type', 'is_read', 'created_at', 'is_pinned'];

        $orderColumnIndex = $this->payloadValue($payload, ['order', 0, 'column']);
        if (is_numeric($orderColumnIndex)) {
            $requestedColumn = $this->payloadString($payload, ['columns', (int)$orderColumnIndex, 'data'], 'id');
            $requestedDir = strtolower($this->payloadString($payload, ['order', 0, 'dir'], 'desc'));
            $column = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';
            $dir = $requestedDir === 'asc' ? 'asc' : 'desc';
        }

        $search = $this->datatablesSearch($payload);
        $uid = $this->payloadInt($payload, ['uid'], 0, 0);
        [$start, $length] = $this->datatablesPagination($payload);

        $baseQuery = function () use ($uid) {
            $query = Db::name('user_message')
                ->where('is_deleted', 0)
                ->whereRaw("title is not null and title <> ''")
                ->whereRaw("content is not null and content <> ''");

            if ($uid > 0) {
                $query->where('user_id', $uid);
            }

            return $query;
        };

        $filteredQuery = function () use ($baseQuery, $search) {
            $query = $baseQuery();

            if ($search !== '') {
                $userIds = [];
                if (ctype_digit($search)) {
                    $userIds[] = (int)$search;
                }

                $matchedUsers = UserModel::where('id|mobile|nickname|surname', 'like', '%' . $search . '%')->column('id');
                $userIds = array_values(array_unique(array_merge($userIds, array_map('intval', $matchedUsers))));

                $query->where(function ($subQuery) use ($search, $userIds) {
                    $subQuery->where('title|summary|content', 'like', '%' . $search . '%');
                    if (!empty($userIds)) {
                        $subQuery->whereOr('user_id', 'in', $userIds);
                    }
                });
            }

            return $query;
        };

        $rows = $filteredQuery()
            ->order('is_pinned', 'desc')
            ->order($column, $dir)
            ->order('id', 'desc')
            ->limit($start, $length)
            ->select()
            ->toArray();

        $data = [];
        foreach ($rows as $vo) {
            if (empty($vo['id']) || trim((string)($vo['title'] ?? '')) === '') {
                continue;
            }
            $user = UserModel::field('id,mobile,nickname,surname')->find((int)($vo['user_id'] ?? 0));
            $sender = AdminModel::field('id,name,account')->find((int)($vo['sender_admin_id'] ?? 0));
            $data[] = [
                'id' => (int)($vo['id'] ?? 0),
                'user_id' => (int)($vo['user_id'] ?? 0),
                'title' => (string)($vo['title'] ?? ''),
                'summary' => UserMessageService::buildSummary((string)($vo['summary'] ?? ''), (string)($vo['content'] ?? '')),
                'content' => (string)($vo['content'] ?? ''),
                'source_type' => (string)($vo['source_type'] ?? 'admin'),
                'message_type' => (string)($vo['message_type'] ?? 'official'),
                'action_type' => (string)($vo['action_type'] ?? 'none'),
                'action_value' => (string)($vo['action_value'] ?? ''),
                'is_pinned' => (int)($vo['is_pinned'] ?? 0),
                'is_read' => (int)($vo['is_read'] ?? 0),
                'sender_admin_id' => (int)($vo['sender_admin_id'] ?? 0),
                'created_at' => (string)($vo['created_at'] ?? ''),
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

        $totalRecords = (int)$baseQuery()->count();
        $totalDisplay = (int)$filteredQuery()->count();
        $result = [
            'draw'            => $this->payloadInt($payload, ['draw'], 0, 0),
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function admin_list()
    {

        // P2-002: 管理员列表权限校验
        if (!$this->directHasAdminPermission('管理员列表')) {
            return $this->directDenyAdminPermission('管理员列表');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'id');
        [$start, $length] = $this->datatablesPagination($payload);
        $search = $this->datatablesSearch($payload);
        $basePar[] = ['id', '<>', 1];
        $par = $basePar;
        $par[] = ['name|account', 'like', '%' . $search . '%'];
        $data = AdminModel::where($par)->order($column??'id', $dir??'desc')->order('id', 'asc')->field('id,name,account,avatar,power,create_time')->limit($start, $length)->select();
        foreach($data as $key => $vo) {

        }
        $totalRecords = (int)AdminModel::where($basePar)->count();
        $totalDisplay = (int)AdminModel::where($par)->count();
        $result = [
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data'            => $data,
        ];
        
        return $this->datatablesResponse($result, $payload);
    }

    public function operation_log_list()
    {
        // F13
        if (!$this->directHasAdminPermission('操作记录')) {
            return $this->directDenyAdminPermission('操作记录');
        }
        $payload = $this->listPayload();
        [$column, $dir] = $this->resolveOrder($payload, 'create_time', ['id', 'create_time', 'admin_username', 'module', 'action', 'ip']);
        $search = $this->datatablesSearch($payload);
        $adminUsername = $this->payloadString($payload, ['admin_username']);
        $module = $this->payloadString($payload, ['module']);
        $action = $this->payloadString($payload, ['action']);
        $timeRange = $this->payloadString($payload, ['time_range']);
        [$start, $length] = $this->datatablesPagination($payload);

        $buildQuery = function () use ($search, $adminUsername, $module, $action, $timeRange) {
            $query = AdminOperationLog::where([]);

            if ($adminUsername !== '') {
                $query->where('admin_username', 'like', '%' . $adminUsername . '%');
            }
            if ($module !== '') {
                $query->where('module', $module);
            }
            if ($action !== '') {
                $query->where('action', $action);
            }
            if ($search !== '') {
                $query->where('admin_username|module|action|content|ip', 'like', '%' . $search . '%');
            }
            if ($timeRange !== '') {
                $dates = preg_split('/\s+(?:to|至)\s+/u', $timeRange);
                if (is_array($dates) && count($dates) >= 2) {
                    $startTime = trim((string)($dates[0] ?? ''));
                    $endTime = trim((string)($dates[1] ?? ''));
                    if ($startTime !== '' && $endTime !== '') {
                        $query->whereTime('create_time', 'between', [$startTime, $endTime]);
                    }
                }
            }

            return $query;
        };

        $data = $buildQuery()->order($column ?? 'create_time', $dir ?? 'desc')->order('id', 'desc')->limit($start, $length)->select();
        $totalRecords = (int)AdminOperationLog::where([])->count();
        $totalDisplay = (int)$buildQuery()->count();
        $result = [
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalDisplay,
            'data' => $data,
        ];

        return $this->datatablesResponse($result, $payload);
    }
}
