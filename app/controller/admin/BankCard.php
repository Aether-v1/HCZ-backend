<?php
// HCZ B04 + B05-B: Admin 域控制器。bank_card_post 业务实现（B05-B 迁移，Stage B）。
// 操作日志统一走 AdminOperationLogService::record()（收编，不复制 AdminApi 私有实现）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\BankCard as BankCardModel;
use app\service\AdminOperationLogService;
class BankCard extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function bank_card_post(string $action)
    {
        // P2-004 P2-001: 银行卡管理权限检查
        if (!$this->authorize('admin.payment.manage')) {
            return $this->directDenyAdminPermission('支付管理');
        }
        $post_info = $this->request->post();
        switch ($action) {
            case 'dels':
                $data = BankCardModel::where('id', 'in', $post_info['ids'])->select();
                $deletedIds = [];
                foreach($data as $key => $vo) {
                    BankCardModel::destroy($vo['id']);
                    $deletedIds[] = (int)$vo['id'];
                }
                $this->writeAdminLog('删除银行卡', '支付管理', '批量删除用户银行卡，数量：' . count($deletedIds) . '，ID：' . implode(',', $deletedIds), [
                    'target_type' => 'bank_card',
                ]);
                return show(200, 'success', '删除成功');
                
            default:
                return show(500, 'error', '你不对劲');
        }
    }

    /**
     * 操作日志统一入口（B05-B 收编到 AdminOperationLogService::record()）
     */
    private function writeAdminLog(string $action, string $module, string $content, array $options = []): void
    {
        app(AdminOperationLogService::class)->record($action, $module, $content, array_merge([
            'admin' => $this->currentAdminIdentity(),
        ], $options));
    }
}