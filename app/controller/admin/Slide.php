<?php
// HCZ B04 + B05-B: Admin 域控制器。slide_post 业务实现（B05-B 迁移，Stage B）。
// 操作日志统一走 AdminOperationLogService::record()（收编，不复制 AdminApi 私有实现）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\Slide as SlideModel;
use app\service\AdminOperationLogService;
class Slide extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function slide_post(string $action)
    {
        $post_info = $this->request->post();

        if (!$this->authorize('admin.banner.manage')) {
            return $this->directDenyAdminPermission('首页轮播图');
        }

        switch ($action) {
            case 'submit':
                if(empty($post_info['name'])){
                    return show(500, 'error', '请输入轮播图名称');
                }
                if(empty($post_info['image'])){
                    return show(500, 'error', '请上传轮播图图片');
                }
                $slideId = (int)($post_info['id'] ?? 0);
                if ($slideId > 0) {
                    $slide = SlideModel::find($slideId);
                    if ($slide) {
                        $oldName = (string)$slide['name'];
                        $slide->name = $post_info['name'];
                        $slide->image = $post_info['image'];
                        $slide->save();
                        $this->writeAdminLog('修改轮播图', '首页轮播图', '轮播图ID：' . $slideId . '，名称：' . $oldName . ' -> ' . $post_info['name']);
                        return show(200, 'success', '修改成功');
                    }
                }
                SlideModel::create([
                    'name' => $post_info['name'],
                    'image' => $post_info['image'],
                ]);
                $this->writeAdminLog('添加轮播图', '首页轮播图', '轮播图名称：' . $post_info['name']);
                return show(200, 'success', '添加成功');

            case 'del':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $slide = SlideModel::find($id);
                if (!$slide) {
                    return show(500, 'error', '轮播图不存在');
                }
                $slideName = (string)$slide['name'];
                SlideModel::destroy($id);
                $this->writeAdminLog('删除轮播图', '首页轮播图', '轮播图ID：' . $id . '，名称：' . $slideName);
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