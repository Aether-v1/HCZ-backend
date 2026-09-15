<?php
// HCZ B04 + B05-A: Admin 域控制器。admin_footer 业务实现（B05-A 迁移，只读统计）。
// setting_post 仍薄转发到 AdminApi（B05-B，待 B07）；不得复制/重写业务逻辑。
namespace app\controller\admin;

use app\controller\AdminApi;
use app\middleware\AdminAuth;
use app\model\Order;
use app\model\Recharge;
use app\model\Withdrawal;
class Setting
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function setting_post(string $action)
    {
        return app(AdminApi::class)->setting_post($action);
    }

    public function admin_footer(string $action)
    {
        $post_info = request()->post();
        switch ($action) {
            case 'out_order':
                $order_cz = Order::where('status', 0)->where('type', 1)->count();
                $order_cx = Order::where('status', 0)->where('type', 2)->count();
                $recharge = Recharge::where('status', 1)->count();
                $withdrawal = Withdrawal::where('status', \app\model\Withdrawal::STATUS_PENDING)->count();
                $data = [
                    'order_cz' => $order_cz,
                    'order_cx' => $order_cx,
                    'recharge' => $recharge,
                    'withdrawal' => $withdrawal,
                ];
                return show(200, 'success', '查询成功', $data);

            default:
                return show(500, 'error', '请求出错');
        }
    }
}
