<?php
// HCZ B10-05: Points 域只读查询控制器。points_records_json（积分记录列表查看，GET）迁移。
// 与 OLD AdminApi::points_records_json 逐行等价迁移（迁移不重构）：
//  - 纯只读 SELECT（cz_points_record / cz_user），无事务、无锁、无写
//  - 权限 admin.points.view 原样保持；失败 directDenyAdminPermission('积分管理')
//  - mobile 经 UserModel getMobileMaskedAttr 脱敏（OLD 既有行为，不绕过）
// balance/order/recharge/withdrawal/rebate 等 Financial 保持 Frozen 留 AdminApi（本控制器无资金逻辑）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\PointsRecord;
use app\model\User as UserModel;

class PointsRecords extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    /**
     * 积分记录列表（只读，GET）
     * 与 OLD AdminApi::points_records_json 行为等价（B10-05）。
     */
    public function points_records_json()
    {
        if (!$this->authorize('admin.points.view')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        $type = trim((string)$this->request->get('type', ''));

        $query = PointsRecord::field('id,uid,points,reason,type,create_time');

        if ($type !== '' && in_array($type, ['earned', 'used'], true)) {
            $query->where('type', $type);
        }
        if ($keyword !== '') {
            $matchedUserIds = UserModel::whereLike('mobile|nickname', '%' . $keyword . '%')->column('id');
            $query->where(function ($q) use ($keyword, $matchedUserIds) {
                $q->whereLike('reason', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $q->whereOr('uid', (int)$keyword);
                }
                if (!empty($matchedUserIds)) {
                    $q->whereOr('uid', 'in', $matchedUserIds);
                }
            });
        }

        $totalQuery = clone $query;
        $total = (int)$totalQuery->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $uids = array_values(array_unique(array_filter(array_map(static fn ($row) => (int)($row['uid'] ?? 0), $list))));
        $userMap = [];
        if (!empty($uids)) {
            $users = UserModel::whereIn('id', $uids)->field('id,mobile,nickname')->select()->toArray();
            foreach ($users as $user) {
                $userMap[(int)$user['id']] = $user;
            }
        }
        foreach ($list as &$row) {
            $user = $userMap[(int)($row['uid'] ?? 0)] ?? [];
            $row['mobile'] = $user['mobile'] ?? '';
            $row['nickname'] = $user['nickname'] ?? '';
        }
        unset($row);

        return show(200, 'success', '查询成功', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }
}
