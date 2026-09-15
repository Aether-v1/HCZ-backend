<?php
// HCZ B10-21: Points 域只读查询控制器。points_exchange_orders_json（积分兑换订单列表查看，GET）迁移。
// 与 OLD AdminApi::points_exchange_orders_json 逐行等价迁移（迁移不重构）：
//  - 纯只读 SELECT（cz_points_exchange_order / cz_user），无事务、无锁、无写、无 DDL
//  - 权限 admin.points.view 原样保持；失败 directDenyAdminPermission('积分管理')
//  - Schema Owner = database/migrations/2024_01_08_000001_create_points_exchange_order_table.php（B10-13）
//  - 本控制器不触碰积分资产/兑换写入/Financial（保持 Frozen 留 AdminApi/PointsActions）
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\User as UserModel;
use think\facade\Db;

class PointsExchangeOrders extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    /**
     * 积分兑换订单列表（只读，GET）
     * 与 OLD AdminApi::points_exchange_orders_json 行为等价（B10-21）。
     */
    public function points_exchange_orders_json()
    {
        if (!$this->authorize('admin.points.view')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        $page    = max(1, (int)$this->request->get('page', 1));
        $limit   = max(1, min(100, (int)$this->request->get('limit', 20)));
        $status  = $this->request->get('status', '');
        $keyword = trim((string)$this->request->get('keyword', ''));

        $query = Db::name('points_exchange_order')->field('id,uid,item_id,item_type,item_title,points,status,remark,create_time');

        if ($status !== '' && in_array($status, ['0', '1', '2'], true)) {
            $query->where('status', (int)$status);
        }
        if ($keyword !== '') {
            $matchedUids = UserModel::whereLike('mobile|nickname', '%' . $keyword . '%')->column('id');
            $query->where(function ($q) use ($keyword, $matchedUids) {
                $q->whereLike('item_title', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $q->whereOr('uid', (int)$keyword);
                }
                if (!empty($matchedUids)) {
                    $q->whereOr('uid', 'in', $matchedUids);
                }
            });
        }

        $total  = (int)(clone $query)->count();
        $list   = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $uids   = array_values(array_unique(array_map(static fn ($r) => (int)($r['uid'] ?? 0), $list)));
        $userMap = [];
        if (!empty($uids)) {
            foreach (UserModel::whereIn('id', $uids)->field('id,mobile,nickname')->select()->toArray() as $u) {
                $userMap[(int)$u['id']] = $u;
            }
        }
        foreach ($list as &$row) {
            $u = $userMap[(int)($row['uid'] ?? 0)] ?? [];
            $row['mobile']   = $u['mobile'] ?? '';
            $row['nickname'] = $u['nickname'] ?? '';
        }
        unset($row);

        return show(200, 'success', '查询成功', [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}
