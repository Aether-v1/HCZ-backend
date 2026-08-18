<?php
namespace app\service;

use think\facade\Db;
use think\db\exception\DbException;
use think\facade\Log;

class OrderService
{
        // 订单状态常量定义（与getStatusText方法中的状态值对应）
        const STATUS_PENDING = 0;       // 待充值
        const STATUS_PROCESSING = 1;    // 充值中
        const STATUS_COMPLETED = 2;     // 已完成
        const STATUS_CANCELLED = 3;     // 已取消
        const STATUS_WAIT_QUERY = 4;    // 待查询
        const STATUS_QUERYING = 5;      // 查询中
        const STATUS_QUERY_SUCCESS = 6; // 查询成功
        const STATUS_QUERY_FAILED = 7;  // 查询失败
    /**
     * 根据用户ID获取最近订单（现有方法）
     * @param int $userId 用户ID（对应cz_order表的uid字段）
     * @param int $limit 订单数量限制
     * @return array 订单列表
     * @throws \Exception
     */
    public function getUserOrders($userId, $limit = 5)
    {
        try {
            if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
                Log::error('无效的用户ID参数', ['user_id' => $userId]);
                throw new \Exception('无效的用户ID: ' . $userId);
            }
            
            $limit = max(1, min(20, (int)$limit));
            
            // 使用正确的表名（根据前缀配置）
            $tableName = 'order';
            
            $query = Db::name($tableName)
                ->where('uid', (int)$userId)
                ->where('type', 1)
                ->order('create_time', 'desc')
                ->limit($limit);
            
            $orders = $query->select()->toArray();
            Log::info('订单查询完成', [
                'user_id' => $userId,
                'order_count' => count($orders)
            ]);
            
            // 格式化结果 - 使用order_number作为订单号，并添加order_info
            $result = [];
            foreach ($orders as $order) {
                // 处理可能过长的充值信息，限制显示长度
                $orderInfo = $order['order_info'] ?? '无充值信息';
                $shortOrderInfo = mb_strlen($orderInfo) > 100 
                    ? mb_substr($orderInfo, 0, 100) . '...' 
                    : $orderInfo;
                
                $result[] = [
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number'], // 订单编号
                    'amount' => $order['amount_money'] . ' 元',
                    'status' => $order['status'],
                    'status_text' => self::getStatusText($order['status']),
                    'create_time' => $order['create_time'],
                    'complete_time' => $order['complete_time'] ?? '未完成',
                    'product_info' => $order['product_info'] ?? '无',
                    'product_type' => $order['product_type'] ?? 0, // 新增产品类型
                    'order_info' => $order['order_info'] ?? '无充值信息' // 充值信息
                ];
            }
            return $result;
        } catch (DbException $e) {
            Log::error('订单查询数据库异常', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'module' => 'OrderService'
            ]);
            throw new \Exception('数据库查询失败: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('订单查询业务异常', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'module' => 'OrderService'
            ]);
            throw new \Exception('订单查询失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 新增：获取用户订单总数（带状态筛选）
     * @param int $userId 用户ID（对应uid字段）
     * @param array $statusFilter 状态筛选数组（如[0,1,2]）
     * @return int 符合条件的订单总数
     */
    public function getUserOrdersCount($userId, $statusFilter = [])
    {
        try {
            // 参数验证（与现有方法保持一致）
            if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
                Log::error('getUserOrdersCount 无效用户ID', ['user_id' => $userId]);
                return 0;
            }
            
            $tableName = 'order'; // 与现有方法保持一致的表名
            $query = Db::name($tableName)
                ->where('uid', (int)$userId)
                ->where('type', 1); // 只统计充值业务(类型1)
            
            // 状态筛选（如果有指定）
            if (!empty($statusFilter) && is_array($statusFilter)) {
                $query->where('status', 'in', $statusFilter);
            }
            
            return $query->count();
        } catch (DbException $e) {
            Log::error('订单计数数据库异常', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'module' => 'OrderService'
            ]);
            return 0;
        } catch (\Exception $e) {
            Log::error('订单计数业务异常', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'module' => 'OrderService'
            ]);
            return 0;
        }
    }
    
    /**
     * 新增：分页获取用户订单（带状态筛选）
     * @param int $userId 用户ID（对应uid字段）
     * @param int $page 页码（从1开始）
     * @param int $perPage 每页数量
     * @param array $statusFilter 状态筛选数组
     * @return array 分页订单列表
     */
    public function getUserOrdersByPage($userId, $page = 1, $perPage = 5, $statusFilter = [])
    {
        try {
            // 参数验证（与现有方法保持一致）
            if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
                Log::error('getUserOrdersByPage 无效用户ID', ['user_id' => $userId]);
                return [];
            }
            
            // 校正页码和每页数量
            $page = max(1, (int)$page);
            $perPage = max(1, min(20, (int)$perPage)); // 最大20条/页，与现有方法一致
            $offset = ($page - 1) * $perPage;
            
            $tableName = 'order'; // 与现有方法保持一致的表名
            $query = Db::name($tableName)
                ->where('uid', (int)$userId)
                ->where('type', 1) // 只统计充值业务
                ->order('create_time', 'desc') // 按创建时间倒序，与现有方法一致
                ->limit($offset, $perPage);
            
            // 状态筛选
            if (!empty($statusFilter) && is_array($statusFilter)) {
                $query->where('status', 'in', $statusFilter);
            }
            
            $orders = $query->select()->toArray();
            
            // 格式化结果（复用现有格式化逻辑）
            $result = [];
            foreach ($orders as $order) {
                $orderInfo = $order['order_info'] ?? '无充值信息';
                $shortOrderInfo = mb_strlen($orderInfo) > 100 
                    ? mb_substr($orderInfo, 0, 100) . '...' 
                    : $orderInfo;
                
                $result[] = [
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'amount' => $order['amount_money'] . ' 元',
                    'status' => $order['status'],
                    'status_text' => self::getStatusText($order['status']),
                    'create_time' => $order['create_time'],
                    'complete_time' => $order['complete_time'] ?? '未完成',
                    'product_info' => $order['product_info'] ?? '无',
                    'product_type' => $order['product_type'] ?? 0,
                    'order_info' => $order['order_info'] ?? '无充值信息'
                ];
            }
            
            return $result;
        } catch (DbException $e) {
            Log::error('分页订单查询数据库异常', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'page' => $page,
                'module' => 'OrderService'
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error('分页订单查询业务异常', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'page' => $page,
                'module' => 'OrderService'
            ]);
            return [];
        }
    }
    
    /**
     * 订单状态文本映射（现有方法）
     */
    public static function getStatusText($status)
        {
            $statusMap = [
                self::STATUS_PENDING => '待充值',          // 使用常量代替硬编码
                self::STATUS_PROCESSING => '充值中',       // 使用常量代替硬编码
                self::STATUS_COMPLETED => '已完成',        // 使用常量代替硬编码
                self::STATUS_CANCELLED => '已取消',        // 使用常量代替硬编码
                self::STATUS_WAIT_QUERY => '待查询',       // 使用常量代替硬编码
                self::STATUS_QUERYING => '查询中',         // 使用常量代替硬编码
                self::STATUS_QUERY_SUCCESS => '查询成功',  // 使用常量代替硬编码
                self::STATUS_QUERY_FAILED => '查询失败'    // 使用常量代替硬编码
            ];
            
            return $statusMap[$status] ?? "未知状态({$status})";
        }
        /**
         * 判断状态是否为终态（无需再发送通知）
         * 终态：已完成、已取消
         */
        public static function isFinalStatus($status)
        {
            return in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
        }
    /**
     * 获取用户订单状态统计（现有方法）
     * @param int $userId 用户ID
     * @return array 包含各状态订单数量的数组
     */
        public function getOrderStatusCounts($userId)
        {
            try {
                $counts = Db::name('order')
                    ->where('uid', $userId)
                    ->where('type', 1)
                    ->field('status, count(*) as total')
                    ->group('status')
                    ->select();
                    
                $result = [
                    'pending' => 0,    // 待充值（对应STATUS_PENDING）
                    'processing' => 0, // 充值中（对应STATUS_PROCESSING）
                    'completed' => 0,  // 已完成（对应STATUS_COMPLETED）
                    'cancelled' => 0   // 已取消（对应STATUS_CANCELLED）
                ];
                
                foreach ($counts as $count) {
                    switch ($count['status']) {
                        case self::STATUS_PENDING:
                            $result['pending'] = $count['total'];
                            break;
                        case self::STATUS_PROCESSING:
                            $result['processing'] = $count['total'];
                            break;
                        case self::STATUS_COMPLETED:
                            $result['completed'] = $count['total'];
                            break;
                        case self::STATUS_CANCELLED:
                            $result['cancelled'] = $count['total'];
                            break;
                    }
                }
                
                return $result;
            } catch (\Exception $e) {
                trace('获取订单状态统计失败: ' . $e->getMessage() . ', SQL: ' . Db::getLastSql(), 'error');
                return [
                    'pending' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'cancelled' => 0
                ];
            }
        }
                /**
         * 更新订单状态（新增：状态变更时触发通知）
         * @param int $orderId 订单ID
         * @param int $newStatus 新状态
         * @return bool 是否更新成功
         */
        public function updateOrderStatus($orderId, $newStatus)
        {
            try {
                // 查询订单当前信息
                $order = Db::name('order')
                    ->where('id', $orderId)
                    ->find();
                    
                if (!$order) {
                    Log::error('更新订单状态失败：订单不存在', ['order_id' => $orderId]);
                    return false;
                }
                
                // 如果状态未变化，无需更新和通知
                if ($order['status'] == $newStatus) {
                    return true;
                }
                
                // 更新订单状态
                $updateResult = Db::name('order')
                    ->where('id', $orderId)
                    ->update([
                        'status' => $newStatus,
                        'update_time' => date('Y-m-d H:i:s')
                    ]);
                    
                if (!$updateResult) {
                    Log::error('订单状态更新失败', ['order_id' => $orderId, 'new_status' => $newStatus]);
                    return false;
                }
                
                // 状态更新成功后，触发通知
                $this->notifyOrderStatusChange($order['uid'], $order, $newStatus);
                
                return true;
            } catch (\Exception $e) {
                Log::error('更新订单状态异常', [
                    'error' => $e->getMessage(),
                    'order_id' => $orderId,
                    'new_status' => $newStatus
                ]);
                return false;
            }
        }
        
        /**
         * 触发订单状态变更通知（新增）
         * @param int $userId 用户ID
         * @param array $order 订单信息
         * @param int $newStatus 新状态
         */
        private function notifyOrderStatusChange($userId, $order, $newStatus)
        {
            try {
                // 实例化Telegram服务
                $telegramService = new TelegramService();
                
                // 准备通知内容
                $orderNumber = $order['order_number'] ?? '未知订单号';
                $amount = $order['amount_money'] . ' 元';
                $statusText = self::getStatusText($newStatus);
                $updateTime = date('Y-m-d H:i:s');
                
                // 调用发送通知方法
                $notifyResult = $telegramService->sendOrderStatusNotification(
                    $userId,
                    $orderNumber,
                    $amount,
                    $statusText,
                    $updateTime
                );
                
                // 记录通知结果
                Log::info('订单状态通知结果', [
                    'order_id' => $order['id'],
                    'user_id' => $userId,
                    'status' => $notifyResult ? '成功' : '失败',
                    'new_status' => $newStatus
                ]);
            } catch (\Exception $e) {
                Log::error('触发订单通知异常', [
                    'error' => $e->getMessage(),
                    'order_id' => $order['id'] ?? 0,
                    'user_id' => $userId
                ]);
            }
        }
}
    