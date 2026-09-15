<?php
// HCZ B04 + B05-B: Admin 域控制器。account_post 业务实现（B05-B 迁移，Stage B）。
// admin_info 按设计等价替换为 currentAdminIdentity()（B07 惰性能力，Session 语义保持，fail-closed）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\service\UploadService;
use think\db\exception\DbException;
class Account extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function account_post(string $action)
    {
        // P2-004 P3-002: 显式 CSRF 双重保护（全局 CsrfCheck 已保护，此处增加 controller 层校验）
        if (!$this->directValidateRequiredCsrfToken()) {
            return show(403, 'error', '请求校验失败');
        }
        $post_info = $this->request->post();
        try {
            switch ($action) {
                case 'account':
                    if(empty($post_info['account'])){
                        return show(500, 'error', '登录账号不可为空');
                    }
                    $admin_info = AdminModel::where('id', $this->currentAdminIdentity()['id'])->find();
                    if(!empty($post_info['password'])){
                        $salt = randomkeys(4);
                        $admin_info->password = password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT);
                        $admin_info->salt = $salt;
                    }
                    $admin_info->account = $post_info['account'];
                    $admin_info->save();
                    return show(200, 'success', '信息修改成功');
                case 'avatar':
                    try {
                        $stored = (new UploadService())->storeImageUpload(
                            (string)$this->request->post('result'),
                            [
                                'directory' => 'storage/avatar',
                                'basename' => (string)($this->currentAdminIdentity()['account'] ?? ''),
                                'allowed_mimes' => ['image/jpeg', 'image/png'],
                                'empty_message' => '图片上传错误',
                            ]
                        );
                        $admin_info = AdminModel::where('id', $this->currentAdminIdentity()['id'])->find();
                        if (!$admin_info) {
                            return show(500, 'error', '管理员信息不存在');
                        }
                        $admin_info->avatar = $stored['public_path'];
                        $admin_info->save();
                        return show(200, 'success', '头像上传成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                default:
                    return show(500, 'error', '你不对劲');
            }
        } catch (DbException $e) {
            return show(500, 'error', $e->getMessage());
        }
    }
}