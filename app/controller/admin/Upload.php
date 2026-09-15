<?php
// HCZ B04 + B05-B: Admin 域控制器。upload_post 业务实现（B05-B 迁移，Stage B）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\service\UploadService;
class Upload extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function upload_post()
    {
        $fileBag = (array)$this->request->file();
        $keyname = array_key_first($fileBag);
        $file = $keyname !== null ? ($fileBag[$keyname] ?? null) : null;
        if ($keyname === null || !is_object($file)) {
            return show(404, 'error', '请选择图片');
        }

        // P2-004 P2-002: 按 keyname 动态权限检查
        $settingKeys = ['a_recommend_upload', 'b_recommend_upload', 'contact_service_upload', 'user_avatar_upload'];
        if (in_array($keyname, $settingKeys, true)) {
            if (!$this->authorize('admin.setting.manage')) {
                return $this->directDenyAdminPermission('系统设置管理');
            }
        } elseif ($keyname === 'upload') {
            // 通用上传：产品管理/轮播图/系统设置任意一个权限即可
            $hasUploadPerm = $this->authorize('admin.product.manage')
                || $this->authorize('admin.product.manage')
                || $this->authorize('admin.banner.manage')
                || $this->authorize('admin.setting.manage');
            if (!$hasUploadPerm) {
                return $this->directDenyAdminPermission('文件上传');
            }
        }

        $uploader = new UploadService();
        if (in_array($keyname, ['a_recommend_upload', 'b_recommend_upload', 'contact_service_upload', 'user_avatar_upload', 'upload'], true)) {
            try {
                $stored = $uploader->storeImageUpload($file, [
                    'directory' => 'storage',
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif'],
                ]);
                return show(200, 'success', '上传成功', $stored['public_path']);
            } catch (\Throwable $e) {
                return show(404, 'error', $e->getMessage());
            }
        }

        return show(404, 'error', '非白名单文件，禁止上传' . $keyname);
    }
}