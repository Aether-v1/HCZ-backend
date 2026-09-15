<?php
// HCZ B04 + B05-C: Admin 域控制器。export_download 业务实现（B05-C 迁移，承载 ExportService）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\service\ExportService;
use think\facade\Cache;
class Export
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function export_download()
    {
        // 防导出文件泄露：下载只允许通过后台私有接口读取 runtime/export 中的临时文件。
        app(ExportService::class)->cleanupExpiredExportFiles();
        $token = trim((string)request()->get('token', ''));
        if ($token === '') {
            return show(500, 'error', '导出文件不存在');
        }

        $cacheKey = 'admin_export_download:' . $token;
        $record = Cache::get($cacheKey);
        if (!is_array($record) || empty($record['path']) || empty($record['name'])) {
            return show(500, 'error', '导出文件不存在或已过期');
        }

        if ((int)($record['admin_id'] ?? 0) !== (int)(session('admin')['id'] ?? 0)) {
            return show(500, 'error', '无权下载该导出文件');
        }

        $absolutePath = (string)$record['path'];
        if (!is_file($absolutePath)) {
            Cache::delete($cacheKey);
            return show(500, 'error', '导出文件不存在或已过期');
        }

        return download($absolutePath, (string)$record['name']);
    }
}
