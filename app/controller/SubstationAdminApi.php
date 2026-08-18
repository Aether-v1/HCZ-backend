<?php
declare (strict_types=1);

namespace app\controller;

use app\middleware\AdminAuth;
use app\model\Order;
use app\model\Substation;
use app\model\SubstationIncomeLog;
use app\model\SubstationProfile;
use app\model\SubstationProfileAudit;
use app\model\Config;
use app\model\User as UserModel;
use think\App;
use think\Request;
use think\facade\Db;

class SubstationAdminApi
{
    protected Request $request;
    protected App $app;
    protected mixed $admin_info;
    protected array $middleware = [AdminAuth::class];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->admin_info = $this->request->session('admin');
    }

    private function profileTable(): string
    {
        return (new SubstationProfile())->getTable();
    }

    private function normalizeAuditRows($rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $row : $row->toArray();
            $substation = Substation::find((int)($item['substation_id'] ?? 0));
            if ($substation) {
                $item['substation_status'] = (int)($substation['status'] ?? 0);
            }
            $list[] = $item;
        }
        return $list;
    }

    public function applyList()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        // 域名审核：统一展示开通申请与资料修改中涉及二级域名前缀的审核记录
        $query = SubstationProfileAudit::whereIn('audit_type', [1, 2])->order('id desc');
        if ($this->request->get('status', '') !== '') {
            $query->where('status', (int)$this->request->get('status'));
        } else {
            // 域名审核页默认看待审核
            $query->where('status', 0);
        }
        if ($keyword !== '') {
            $query->whereLike('site_name|subdomain|full_domain|uid|substation_id', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->select();
        return show(200, 'success', '查询成功', ['list' => $this->normalizeAuditRows($list), 'total' => $total, 'page' => $page, 'limit' => $limit, 'base_domain' => (string)getConfig('substation_base_domain')]);
    }

    public function profileAuditList()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        // 分站资料修改记录：仅保留资料修改审核类型
        $query = SubstationProfileAudit::where('audit_type', 2)->order('id desc');
        if ($this->request->get('status', '') !== '') {
            $query->where('status', (int)$this->request->get('status'));
        }
        if ($keyword !== '') {
            $query->whereLike('site_name|subdomain|full_domain|uid|substation_id', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->select();
        return show(200, 'success', '查询成功', ['list' => $this->normalizeAuditRows($list), 'total' => $total, 'page' => $page, 'limit' => $limit, 'base_domain' => (string)getConfig('substation_base_domain')]);
    }

    public function saveBaseDomain()
    {
        try {
            $value = strtolower(trim((string)$this->request->post('base_domain', '')));
            if ($value === '') {
                throw new \Exception('请输入基础域名');
            }
            if (!preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/', $value)) {
                throw new \Exception('基础域名格式不正确');
            }
            $config = Config::find('substation_base_domain');
            if (!$config) {
                $config = new Config();
                $config->k = 'substation_base_domain';
                $config->create_time = date('Y-m-d H:i:s');
            }
            $config->v = $value;
            $config->update_time = date('Y-m-d H:i:s');
            $config->save();
            return show(200, 'success', '保存成功');
        } catch (\Throwable $e) {
            return show(500, 'error', $e->getMessage());
        }
    }

    public function audit()
    {
        try {
            $auditId = (int)$this->request->post('audit_id', 0);
            $status = (int)$this->request->post('status', 0);
            $rejectReason = trim((string)$this->request->post('reject_reason', ''));
            if (!in_array($status, [1, 2], true)) {
                throw new \Exception('审核状态错误');
            }
            Db::transaction(function () use ($auditId, $status, $rejectReason) {
                $audit = SubstationProfileAudit::where('id', $auditId)->lock(true)->find();
                if (!$audit) {
                    throw new \Exception('审核记录不存在');
                }
                if ((int)$audit['status'] !== 0) {
                    throw new \Exception('该申请已处理');
                }
                $substation = Substation::where('id', (int)$audit['substation_id'])->lock(true)->find();
                if (!$substation) {
                    throw new \Exception('分站不存在');
                }
                if ($status === 1) {
                    $subdomain = strtolower(trim((string)$audit['subdomain']));
                    if ($subdomain === '') {
                        throw new \Exception('分站前缀不能为空');
                    }
                    $baseDomain = strtolower(trim((string)getConfig('substation_base_domain')));
                    if ($baseDomain === '') {
                        throw new \Exception('请先配置基础域名');
                    }
                    $fullDomain = $subdomain . '.' . $baseDomain;
                    $exists = SubstationProfile::where('full_domain', $fullDomain)->where('substation_id', '<>', (int)$substation['id'])->lock(true)->find();
                    if ($exists) {
                        throw new \Exception('该分站域名已被占用');
                    }
                    $profile = SubstationProfile::where('substation_id', (int)$substation['id'])->lock(true)->find();
                    if (!$profile) {
                        $profile = new SubstationProfile();
                        $profile->substation_id = (int)$substation['id'];
                        $profile->uid = (int)$substation['uid'];
                        $profile->create_time = date('Y-m-d H:i:s');
                    }
                    $profile->subdomain = $subdomain;
                    $profile->full_domain = $fullDomain;
                    $profile->site_name = (string)$audit['site_name'];
                    $profile->notice = (string)$audit['notice'];
                    $profile->logo = (string)$audit['logo'];
                    $profile->status = 1;
                    $profile->audit_pass_time = date('Y-m-d H:i:s');
                    $profile->update_time = date('Y-m-d H:i:s');
                    if ($profile->save() === false) {
                        throw new \Exception('保存正式配置失败');
                    }
                    $substation->status = 2;
                    $substation->open_time = $substation['open_time'] ?: date('Y-m-d H:i:s');
                    $substation->reject_reason = null;
                    $substation->update_time = date('Y-m-d H:i:s');
                    $substation->save();
                } else {
                    $substation->reject_reason = $rejectReason;
                    if ((int)$audit['audit_type'] === 1) {
                        $substation->status = 3;
                    }
                    $substation->update_time = date('Y-m-d H:i:s');
                    $substation->save();
                }
                $audit->status = $status;
                $audit->reject_reason = $status === 2 ? $rejectReason : null;
                $audit->audit_admin_id = (int)($this->admin_info['id'] ?? 0);
                $audit->audit_time = date('Y-m-d H:i:s');
                $audit->update_time = date('Y-m-d H:i:s');
                $audit->save();
            });
            return show(200, 'success', '审核成功');
        } catch (\Throwable $e) {
            return show(500, 'error', $e->getMessage());
        }
    }

    public function list()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        $statusParam = (string)$this->request->get('status', '2');
        $query = Substation::alias('s')
            ->leftJoin($this->profileTable() . ' p', 's.id = p.substation_id')
            ->field('s.*,p.subdomain,p.full_domain,p.site_name,p.logo')
            ->order('s.id desc');
        if ($statusParam !== '') {
            $query->where('s.status', (int)$statusParam);
        }
        if ($keyword !== '') {
            $query->whereLike('s.id|s.uid|p.subdomain|p.full_domain|p.site_name', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $row : $row->toArray();
            $user = UserModel::find((int)($item['uid'] ?? 0));
            $item['user_mobile'] = (string)($user['mobile'] ?? '');
            $list[] = $item;
        }
        return show(200, 'success', '查询成功', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function manageAction()
    {
        try {
            $action = trim((string)$this->request->post('action', ''));
            $ids = trim((string)$this->request->post('ids', ''));
            $amount = round((float)$this->request->post('amount', 0), 2);
            $remark = trim((string)$this->request->post('remark', ''));
            $idList = array_values(array_filter(array_unique(array_map('intval', explode(',', $ids)))));
            if (empty($idList)) {
                throw new \Exception('请选择分站');
            }
            Db::transaction(function () use ($action, $idList, $amount, $remark) {
                $list = Substation::whereIn('id', $idList)->lock(true)->select();
                foreach ($list as $row) {
                    $beforeBalance = round((float)($row['wallet_balance'] ?? 0), 2);
                    if ($action === 'freeze') {
                        $row->status = 4;
                    } elseif ($action === 'resume') {
                        $row->status = 2;
                    } elseif ($action === 'cancel') {
                        $row->status = 0;
                    } elseif ($action === 'wallet_add' || $action === 'wallet_deduct') {
                        if ($amount <= 0) {
                            throw new \Exception('请输入正确的调整金额');
                        }
                        $nextBalance = $action === 'wallet_add'
                            ? $beforeBalance + $amount
                            : $beforeBalance - $amount;
                        if ($nextBalance < 0) {
                            throw new \Exception('扣减后余额不能小于0');
                        }
                        $row->wallet_balance = round($nextBalance, 2);

                        SubstationIncomeLog::create([
                            'substation_id' => (int)($row['id'] ?? 0),
                            'uid' => (int)($row['uid'] ?? 0),
                            'order_id' => 0,
                            'order_number' => '',
                            'product_id' => 0,
                            'tier_key' => '',
                            'scene' => 'substation_admin_adjust_balance',
                            'change_type' => $action === 'wallet_add' ? 1 : 2,
                            'currency' => 'USDT',
                            'amount' => $amount,
                            'balance_before' => $beforeBalance,
                            'balance_after' => round((float)($row['wallet_balance'] ?? 0), 2),
                            'remark' => $remark !== '' ? $remark : ($action === 'wallet_add' ? '后台手工加余额' : '后台手工扣余额'),
                            'operator_id' => (int)($this->admin_info['id'] ?? 0),
                            'create_time' => date('Y-m-d H:i:s'),
                        ]);
                    } else {
                        throw new \Exception('操作类型错误');
                    }
                    $row->update_time = date('Y-m-d H:i:s');
                    $row->save();
                }
            });
            return show(200, 'success', '操作成功');
        } catch (\Throwable $e) {
            return show(500, 'error', $e->getMessage());
        }
    }

    public function orders()
    {
        $substationId = (int)$this->request->get('substation_id', 0);
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        $status = (string)$this->request->get('status', '');
        $confirmStatus = (string)$this->request->get('confirm_status', '');
        $query = Order::where('substation_id', '>', 0)->where('type', 1)->order('id desc');
        if ($substationId > 0) {
            $query->where('substation_id', $substationId);
        }
        if ($keyword !== '') {
            $query->whereLike('order_number|product_info|order_info|amount_money', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($confirmStatus !== '') {
            $query->where('confirm_status', (int)$confirmStatus);
        }
        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $row : $row->toArray();
            $user = UserModel::find((int)($item['uid'] ?? 0));
            $item['user_mobile'] = (string)($user['mobile'] ?? '');
            $item['user_nickname'] = (string)($user['nickname'] ?? '');
            $list[] = $item;
        }
        return show(200, 'success', '查询成功', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function incomeLog()
    {
        $substationId = (int)$this->request->get('substation_id', 0);
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        $scene = trim((string)$this->request->get('scene', ''));
        $changeType = trim((string)$this->request->get('change_type', ''));

        $query = SubstationIncomeLog::where('substation_id', '>', 0)->order('id desc');
        if ($substationId > 0) {
            $query->where('substation_id', $substationId);
        }
        if ($scene !== '') {
            $query->where('scene', $scene);
        }
        if ($changeType !== '') {
            $query->where('change_type', (int)$changeType);
        }
        if ($keyword !== '') {
            $query->whereLike('order_number|uid|substation_id|remark|scene', '%' . $keyword . '%');
        }

        $total = $query->count();
        $rows = $query->page($page, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $row : $row->toArray();
            $user = UserModel::find((int)($item['uid'] ?? 0));
            $profile = SubstationProfile::where('substation_id', (int)($item['substation_id'] ?? 0))->find();
            $item['user_mobile'] = (string)($user['mobile'] ?? '');
            $item['site_name'] = (string)($profile['site_name'] ?? '');
            $item['full_domain'] = (string)($profile['full_domain'] ?? '');
            $list[] = $item;
        }

        return show(200, 'success', '查询成功', ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }
}
