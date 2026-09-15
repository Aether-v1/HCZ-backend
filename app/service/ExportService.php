<?php
// HCZ B05-C: 导出工具服务。safeExcel / cleanupExpiredExportFiles / createPrivateExportDownload 从 AdminApi 提取（行为等价）。
// 被 admin\Export 控制器与 AdminApi（红区 order_post 兼容薄委托）共用。
namespace app\service;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use think\facade\Cache;
use think\facade\Log;
class ExportService
{
    public function safeExcel($value): string
    {
        // 防 Excel 公式注入：对导出单元格前缀为 = + - @ 的文本加单引号。
        $value = (string)$value;
        if (preg_match('/^[=+\-@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    public function cleanupExpiredExportFiles(): void
    {
        // 防导出文件泄露：清理 runtime/export 中超过 10 分钟的临时导出文件。
        $directory = rtrim(app()->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export';
        if (!is_dir($directory)) {
            return;
        }

        $expireBefore = time() - 600;
        foreach ((array)glob($directory . DIRECTORY_SEPARATOR . 'export_*.xls') as $filePath) {
            if (!is_string($filePath) || !is_file($filePath)) {
                continue;
            }

            $modifiedAt = @filemtime($filePath);
            if ($modifiedAt !== false && $modifiedAt <= $expireBefore) {
                @unlink($filePath);
            }
        }
    }

    public function createPrivateExportDownload(Spreadsheet $spreadsheet, string $scene = 'order_export', int $adminId = 0): string
    {
        // 防导出文件泄露：导出文件落到 runtime/export 私有目录，并通过一次性下载接口访问。
        $this->cleanupExpiredExportFiles();
        $directory = rtrim(app()->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $timestamp = date('YmdHis');
        $random = bin2hex(random_bytes(4));
        $downloadName = 'export_' . $adminId . '_' . $timestamp . '_' . $random . '.xls';
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $downloadName;

        $writer = new Xls($spreadsheet);
        $writer->save($absolutePath);

        $token = bin2hex(random_bytes(16));
        $cacheKey = 'admin_export_download:' . $token;
        Cache::set($cacheKey, [
            'admin_id' => $adminId,
            'path' => $absolutePath,
            'name' => $downloadName,
            'scene' => $scene,
        ], 600);

        Log::info('admin export file created', [
            'admin_id' => $adminId,
            'scene' => $scene,
            'path' => $absolutePath,
        ]);

        return '/' . trim((string)getConfig('backstage_entrance'), '/') . '/export_download?token=' . rawurlencode($token);
    }
}
