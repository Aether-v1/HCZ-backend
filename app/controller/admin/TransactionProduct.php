<?php
declare (strict_types=1);

namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\service\TransactionProductService;
use think\App;

/**
 * TransactionProduct 域控制器（B06 transaction_product_post 迁移）
 *
 * 仅承载：authorize → 参数提取 → 调用金融编排 Service → response。
 * 不包含 Wallet / Ledger / row-lock / 幂等 / 事务实现（Financial Main Chain 仅存在于
 * UserFundLedgerService + TransactionProductService 编排层）。
 */
class TransactionProduct extends \app\BaseController
{
    protected array $middleware = [AdminAuth::class];

    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function transaction_product_post(string $action)
    {
        // AUTHZ-01 修复：恢复 OLD 统一前置授权语义（交易挂单数据 → admin.transaction.pending.view），授权先于参数提取
        if (!$this->directHasAdminPermission('交易挂单数据')) {
            return $this->directDenyAdminPermission('交易挂单数据');
        }
        $post_info = $this->request->post();
        $operatorId = (int)($this->currentAdminIdentity()['id'] ?? 0);
        $service = app(TransactionProductService::class);
        switch ($action) {
            case 'operate':
                return $service->operate($post_info, $operatorId);

            case 'del':
                return $service->del($post_info, $operatorId);

            case 'dels':
                return $service->dels($post_info, $operatorId);

            default:
                return show(500, 'error', '你不对劲');
        }
    }
}
