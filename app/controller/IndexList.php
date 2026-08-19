<?php
declare (strict_types=1);

namespace app\controller;
use app\middleware\UserAuth;
use app\model\User as UserModel;
use app\model\Batch;
use app\model\Order;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\CzProduct;

use app\model\UserPoints;
use app\model\PointsRecords;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;

use Exception;
use JsonException;
use think\App;
use think\db\exception\DbException;
use think\exception\ValidateException;
use think\facade\Session;
use think\facade\Validate;
use think\Request;
use Yurun\Util\HttpRequest;
use yzh52521\filesystem\facade\Filesystem;
use think\facade\Log;
class IndexList
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
    protected mixed $user_info;
    protected string|array|bool $config = [];
    protected array $middleware = [UserAuth::class];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        $this->user_info = $this->request->session('user');
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

    private function payloadStatus(array $payload, array $path, string $default = '4'): string
    {
        $value = $this->payloadValue($payload, $path, $default);
        return is_scalar($value) ? trim((string)$value) : $default;
    }
    
    public function order_cz_list()
    {
        $payload = $this->listPayload();
        $content = $this->payloadString($payload, ['content']);
        $productId = $this->payloadInt($payload, ['product_id'], 0, 0);
        $status = $this->payloadStatus($payload, ['status'], '4');
        if($content !== ''){
            $par[] = ['order_number|order_info', 'like', '%' . $content . '%'];
        }
        if($productId > 0){
            $par[] = ['product_id', '=', $productId];
        }
        if($status !== '4'){
            $par[] = ['status', '=', $status];
        }
        
        $par[] = ['product_type', '=', '0'];
        $par[] = ['type', '=', 1];
        $par[] = ['uid', '=', $this->user_info['id']];
        
        $data = Order::where($par)->order('id', 'desc')->select();
        return json([
            'code' => 0,
            'data' => ['list' => $data]
        ]);
    }
    
    public function order_cx_list()
    {
        $payload = $this->listPayload();
        $content = $this->payloadString($payload, ['content']);
        $status = $this->payloadStatus($payload, ['status'], '4');
        if($content !== ''){
            $par[] = ['order_number|order_info', 'like', '%' . $content . '%'];
        }
        if($status !== '4'){
            $par[] = ['status', '=', $status];
        }
        $par[] = ['type', '=', 2];
        $par[] = ['uid', '=', $this->user_info['id']];
        $data = Order::where($par)->order('id', 'desc')->select();
        return json([
            'code' => 0,
            'data' => ['list' => $data]
        ]);
    }

    // 完全模仿审核池逻辑的订单列表方法
    public function order_list()
    {
        $page = $this->request->param('page', 1, 'intval');
        $pageSize = $this->request->param('page_size', 10, 'intval');
        $payload = $this->listPayload();
        $content = $this->payloadString($payload, ['content']);
        $productType = $this->payloadString($payload, ['product_type']);
        
        // 构建查询条件（与审核池保持一致的查询方式）
        $par = [];
        if ($content !== '') {
            $par[] = ['order_number|order_info', 'like', '%' . $content . '%'];
        }
        if ($productType !== '') {
            $par[] = ['product_type', '=', $productType];
        }
        $status = $this->request->param('status', 4);
        if ($status != 4) {
            $par[] = ['status', '=', $status];
        }
        $par[] = ['uid', '=', $this->user_info['id']];
        
        // 直接查询订单，不提前处理字段（与审核池逻辑一致）
        try {
            // 分页逻辑与审核池保持一致
            $query = Order::where($par)->order('id', 'desc');
            $total = $query->count();
            $list = $query->page($page, $pageSize)->select();
        } catch (DbException $e) {
            return json(['code' => 500, 'msg' => '查询失败：' . $e->getMessage()]);
        }
        
        // 处理产品信息（完全模仿审核池前端可能的处理方式）
        foreach ($list as $item) {
            // 1. 优先使用订单表自带的product_info（审核池依赖此字段）
            $productInfo = $item['product_info'] ?? [];
            
            // 2. 兼容JSON字符串格式（确保与审核池解析方式一致）
            if (is_string($productInfo)) {
                $productInfo = json_decode($productInfo, true) ?: [];
            }
            
            // 3. 如果product_info为空，强制查询cz_product（确保与审核池数据来源一致）
            if (empty($productInfo)) {
                $product = CzProduct::find($item['product_id']);
                if ($product) {
                    $productInfo = $product->toArray();
                }
            }
            
            // 4. 赋值产品信息（字段名与审核池保持完全一致）
            $item['product_name'] = $productInfo['name'] ?? '未知产品';
            $item['product_image'] = $productInfo['image'] ?? '';
            
        }
        
        return json([
            'code' => 0,
            'data' => [
                'list'         => $list,
                'total'        => $total,
                'total_pages'  => ceil($total / $pageSize),
                'current_page' => $page,
                'page_size'    => $pageSize
            ]
        ]);
    }

    // 审核池列表接口（处理/out_order_list请求）
    public function out_order_list()
    {
        $payload = $this->listPayload();
        $productId = $this->payloadInt($payload, ['product_id'], 0, 0);
        $confirmStatus = $this->payloadString($payload, ['confirm_status']);
        if($productId > 0){
            $par[] = ['product_id', '=', $productId];
        }
        $par[] = ['type', '=', 1];
        $par[] = ['status', '=', 2];
        if($confirmStatus !== ''){
            $par[] = ['confirm_status', '=', $confirmStatus];
        }
        $par[] = ['uid', '=', $this->user_info['id']];
        $data = Order::where($par)->order('id', 'desc')->select();
        $result = [
            'code'    => 0,
            'data'    => [
                'list'    => $data,
            ],
        ];
        return json($result);
    }

    // 其他方法保持不变...
    public function agency_center_list()
    {
        $payload = $this->listPayload();
        $type = $this->payloadInt($payload, ['type'], 1, 1, 10);
        $par[] = ['tid_' . $type, '=', $this->user_info['id']];
        $data = UserModel::where($par)->order('id', 'desc')->select();
        return json([
            'code' => 0,
            'data' => ['list' => $data]
        ]);
    }

    public function batch_list()
    {
        $payload = $this->listPayload();
        $status = $this->payloadInt($payload, ['status'], 0, 0);
        $par[] = ['status', '=', $status];
        $par[] = ['uid', '=', $this->user_info['id']];
        $data = Batch::where($par)->order('id', 'desc')->select();
        return json([
            'code' => 0,
            'data' => [
                'list' => $data,
                'batch_ok_count' => Batch::where('uid', $this->user_info['id'])->where('status', 0)->count(),
                'batch_no_count' => Batch::where('uid', $this->user_info['id'])->where('status', 1)->count(),
            ]
        ]);
    }

    public function transaction_my_sale_list()
    {
        $payload = $this->listPayload();
        $status = $this->payloadString($payload, ['status']);
        if($status !== ''){
            $par[] = ['status', '=', $status];
        }
        $par[] = ['uid', '=', $this->user_info['id']];
        $data = TransactionProduct::where($par)->order('id', 'desc')->select();
        return json([
            'code' => 0,
            'data' => ['list' => $data]
        ]);
    }

    public function transaction_index_list()
    {
        $userId = (int)($this->user_info['id'] ?? 0);
        $payload = $this->listPayload();
        TransactionOrder::expirePendingOrders($userId);

        // 初始化查询参数数组
        $par = [];
        
        // 处理用户状态筛选
        if($this->payloadString($payload, ['user_status']) !== ''){
            // 确保用户信息存在
            if($userId > 0){
                $par[] = ['uid', '=', $userId];
            }
        }
        
        // 处理排序
        $order_name = 'unit_price';
        $order_asc = $this->payloadString($payload, ['upper_lower']) !== '' ? 'desc' : 'asc';
        
        // 仅查询状态为1的交易产品
        $par[] = ['status', '=', 1];
        
        // 查询交易产品列表
        $data = TransactionProduct::where($par)
            ->order($order_name, $order_asc)
            ->select();
        
        // 为每个产品补充额外信息
        foreach($data as $key => $vo) {
            // 获取用户信息
            $vo['user_info'] = UserModel::field('avatar,nickname')->find($vo['uid']) ?: [];
            
            // 获取成功交易的订单数量
            $vo['TransactionOrder_count'] = TransactionOrder::where('pid', $vo['id'])
                ->where('status', 3)
                ->count() ?: 0;
            
            // 获取成功交易的总金额
            $vo['pay_amount_s'] = TransactionOrder::where('pid', $vo['id'])
                ->where('status', 3)
                ->sum('pay_amount') ?: 0;
        }
        
        // 准备统计数据
        
        // 我的出售数量统计
        $transactionProductCount = TransactionProduct::where('uid', $userId)->count() ?: 0;
        
        // 交易订单数量统计（状态为0或1）
        $transactionOrderCount = TransactionOrder::where('uid', $userId)
            ->whereIn('status', [0, 1])
            ->count() ?: 0;
        
        // 返回JSON数据
        return json([
            'code' => 0,
            'data' => [
                'list' => $data,
                'TransactionProduct_count' => $transactionProductCount,
                'TransactionOrder_count' => $transactionOrderCount,
            ]
        ]);
    }
    
    

    public function transaction_order_list()
    {
        $userId = (int)($this->user_info['id'] ?? 0);
        $payload = $this->listPayload();
        TransactionOrder::expirePendingOrders($userId);

        $par = [];
        $status = $this->payloadStatus($payload, ['status'], 'null');
        if($status != 'null'){
            $par[] = ['status', '=', $status];
        }
        $par[] = ['uid|sell_uid', '=', $userId];
        $data = TransactionOrder::where($par)->order('id', 'desc')->select();

        // 批量预加载买家用户信息（消除 N+1 查询）
        $buyerUids = array_unique(array_filter(array_map('intval', array_column($data->toArray(), 'uid'))));
        $userMap = [];
        if (!empty($buyerUids)) {
            $users = UserModel::whereIn('id', $buyerUids)->field('id,avatar,nickname')->select();
            foreach ($users as $u) {
                $userMap[(int)$u['id']] = $u;
            }
        }
        foreach($data as $key => $vo) {
            $vo['user_info'] = $userMap[(int)($vo['uid'] ?? 0)] ?? null;
            $statusMeta = TransactionOrder::buildStatusMeta($vo);
            $isSeller = (int)($vo['sell_uid'] ?? 0) === $userId;
            $isBuyer = (int)($vo['uid'] ?? 0) === $userId;
            $vo['effective_status'] = $statusMeta['effective_status'];
            $vo['status_text'] = $statusMeta['status_text'];
            $vo['expired'] = $statusMeta['expired'];
            $vo['expire_time'] = $statusMeta['expire_time'];
            $vo['remaining_seconds'] = $statusMeta['remaining_seconds'] ?? 0;
            $vo['pending_timeout_seconds'] = TransactionOrder::pendingTimeoutSeconds();
            $vo['is_seller'] = $isSeller ? 1 : 0;
            $vo['is_buyer'] = $isBuyer ? 1 : 0;
            $vo['role'] = $isSeller ? 'seller' : ($isBuyer ? 'buyer' : '');
        }
        return json([
            'code' => 0,
            'data' => ['list' => $data]
        ]);
    }
    
    /**
     * 获取用户积分信息
     */
        public function getUserPointsInfo()
    {
        try {
            $userPoints = UserPoints::getUserPoints($this->user_info['id']);
            
            return json([
                'code' => 0,
                'data' => [
                    'points_info' => [
                        'balance' => $userPoints->balance,
                        'month_earned' => $userPoints->month_earned,
                        'month_used' => $userPoints->month_used,
                        'total_earned' => $userPoints->total_earned
                    ],
                    'checkin_info' => [
                        'is_checked_in' => $userPoints->isCheckedInToday(),
                        'continuous_days' => $userPoints->continuous_days
                    ]
                ]
            ]);
        } catch (Exception $e) {
            return json(['code' => 500, 'msg' => '获取积分信息失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 用户签到
     */
    public function checkin()
    {
        try {
            $userPoints = UserPoints::getUserPoints($this->user_info['id']);
            
            // 检查是否已签到
            if ($userPoints->isCheckedInToday()) {
                return json(['code' => 1, 'msg' => '今天已经签过到了']);
            }
            
            // 根据连续签到天数计算奖励积分
            $rewardPoints = $this->calculateCheckinReward($userPoints->continuous_days);
            
            // 开启事务
            $this->app->db->startTrans();
            
            // 更新连续签到天数
            $userPoints->updateContinuousDays();
            
            // 更新积分
            $userPoints->balance += $rewardPoints;
            $userPoints->month_earned += $rewardPoints;
            $userPoints->total_earned += $rewardPoints;
            $userPoints->save();
            
            // 添加积分记录
            PointsRecords::addRecord(
                $this->user_info['id'],
                $rewardPoints,
                '每日签到奖励',
                1 // 1表示获取积分
            );
            
            // 提交事务
            $this->app->db->commit();
            
            return json([
                'code' => 0,
                'msg' => '签到成功',
                'data' => [
                    'points' => $rewardPoints,
                    'new_balance' => $userPoints->balance,
                    'new_continuous_days' => $userPoints->continuous_days
                ]
            ]);
        } catch (Exception $e) {
            // 回滚事务
            $this->app->db->rollback();
            Log::error('签到失败：' . $e->getMessage());
            return json(['code' => 500, 'msg' => '签到失败，请稍后再试']);
        }
    }
    
    /**
     * 获取积分记录
     */
    public function getPointsRecords()
    {
        $type = $this->request->param('type', null); // 1:获取 2:使用
        $page = $this->request->param('page', 1, 'intval');
        $pageSize = $this->request->param('page_size', 10, 'intval');
        
        try {
            $list = PointsRecords::getUserRecords($this->user_info['id'], $type, $page, $pageSize);
            $total = PointsRecords::getRecordsCount($this->user_info['id'], $type);
            
            // 格式化记录
            $formattedList = [];
            foreach ($list as $record) {
                $formattedList[] = [
                    'id' => $record->id,
                    'points' => $record->points,
                    'reason' => $record->reason,
                    'time' => $record->create_time,
                    'type' => $record->type
                ];
            }
            
            return json([
                'code' => 0,
                'data' => [
                    'list' => $formattedList,
                    'total' => $total,
                    'total_pages' => ceil($total / $pageSize),
                    'current_page' => $page,
                    'page_size' => $pageSize
                ]
            ]);
        } catch (Exception $e) {
            return json(['code' => 500, 'msg' => '获取积分记录失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 根据连续签到天数计算奖励积分
     */
    private function calculateCheckinReward($continuousDays)
    {
        // 可以根据实际需求调整奖励规则
        $rewardRules = [
            0 => 10,   // 连续0天（首次签到）
            1 => 12,   // 连续1天
            2 => 15,   // 连续2天
            3 => 18,   // 连续3天
            4 => 20,   // 连续4天
            5 => 25,   // 连续5天
            6 => 30,   // 连续6天
            7 => 50    // 连续7天及以上
        ];
        
        return $rewardRules[min($continuousDays, 7)] ?? 10;
    }
}

