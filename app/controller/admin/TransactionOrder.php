<?php
// HCZ B06: Admin 域控制器。transaction_order_post 业务实现（B06 迁移，Stage B）。
// 操作日志统一走 AdminOperationLogService::record()（不复制 AdminApi 私有实现）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\TransactionOrder as TransactionOrderModel;
use app\service\AdminOperationLogService;
class TransactionOrder extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function transaction_order_post(string $action)    {        $post_info = $this->request->post();        switch ($action) {            case 'del':                if (!$this->authorize('admin.transaction.order.delete')) return $this->directDenyAdminPermission('admin.transaction.order.delete');                $id = (int)($post_info['id'] ?? 0);                if ($id <= 0) {                    return show(500, 'error', '参数错误');                }                $order = TransactionOrderModel::find($id);                if (!$order) {                    return show(500, 'error', '订单不存在');                }                $orderNumber = (string)($order['order_number'] ?? '');                $orderStatus = (int)($order['status'] ?? 0);                $buyerUid = (int)($order['uid'] ?? 0);                $sellerUid = (int)($order['seller_uid'] ?? 0);                TransactionOrderModel::destroy($id);                $this->writeAdminLog('删除交易订单', '交易订单', '订单ID：' . $id . '，订单号：' . $orderNumber . '，买家UID：' . $buyerUid . '，卖家UID：' . $sellerUid . '，删除前状态：' . $orderStatus);                return show(200, 'success', '删除成功');            case 'dels':                if (!$this->authorize('admin.transaction.order.delete')) return $this->directDenyAdminPermission('admin.transaction.order.delete');                $idsRaw = $post_info['ids'] ?? '';                if (is_array($idsRaw)) {                    $ids = array_filter(array_map('intval', $idsRaw), static fn($v) => $v > 0);                } else {                    $ids = array_filter(array_map('intval', explode(',', (string)$idsRaw)), static fn($v) => $v > 0);                }                if (empty($ids)) {                    return show(500, 'error', '参数错误');                }                $orders = TransactionOrderModel::where('id', 'in', $ids)->select();                $deletedCount = 0;                $orderNumbers = [];                foreach ($orders as $vo) {                    $orderNumbers[] = (string)($vo['order_number'] ?? '');                    TransactionOrderModel::destroy($vo['id']);                    $deletedCount++;                }                $this->writeAdminLog('批量删除交易订单', '交易订单', '删除数量：' . $deletedCount . '，订单ID：' . implode(',', $ids) . '，订单号：' . implode(',', $orderNumbers));                return show(200, 'success', '删除成功');            default:                return show(500, 'error', '你不对劲');        }    }    
    /**
     * 操作日志统一入口（B06 迁移到 AdminOperationLogService::record()）
     */
    private function writeAdminLog(string $action, string $module, string $content, array $options = []): void
    {
        app(AdminOperationLogService::class)->record($action, $module, $content, array_merge([
            'admin' => $this->currentAdminIdentity(),
        ], $options));
    }
}
