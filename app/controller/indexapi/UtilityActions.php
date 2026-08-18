<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\Batch;
use app\model\User as UserModel;
use app\service\UploadService;
use Exception;

trait UtilityActions
{
    protected function handleBatchPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        switch ($action) {
            case 'add_batch':
                if (empty($post_info['manual_import'])) {
                    return show(500, 'error', '请输入号码');
                }
                $manual_import = explode(',', $post_info['manual_import']);
                foreach ($manual_import as $number) {
                    $batch_count = Batch::where('uid', $user_info['id'])->where('number', $number)->count();
                    if (empty($batch_count)) {
                        $status = 0;
                    } else {
                        $status = 1;
                    }
                    Batch::create([
                        'uid' => $user_info['id'],
                        'number' => $number,
                        'status' => $status,
                    ]);
                }
                return show(200, 'success', '保存成功');

            case 'upload_batch':
                if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
                    $file = $_FILES['file']['tmp_name'];
                    $fileSize = (int)($_FILES['file']['size'] ?? 0);
                    if ($fileSize <= 0 || $fileSize > 2 * 1024 * 1024) {
                        return show(500, 'error', '文件大小不能超过2MB');
                    }

                    $handle = @fopen($file, 'rb');
                    if ($handle === false) {
                        return show(500, 'error', '导入失败');
                    }

                    $lineCount = 0;
                    $seenNumbers = [];
                    $numbers = [];

                    while (($line = fgets($handle)) !== false) {
                        $number = trim((string)$line);
                        if ($number === '') {
                            continue;
                        }

                        $lineCount++;
                        if ($lineCount > 5000) {
                            fclose($handle);
                            return show(500, 'error', '导入行数不能超过5000行');
                        }

                        if (isset($seenNumbers[$number])) {
                            continue;
                        }

                        $seenNumbers[$number] = true;
                        $numbers[] = $number;
                    }

                    fclose($handle);

                    if ($numbers === []) {
                        return show(500, 'error', '导入内容不能为空');
                    }

                    $existingNumbers = Batch::where('uid', $user_info['id'])
                        ->where('number', 'in', $numbers)
                        ->column('number');
                    $existingLookup = [];
                    foreach ($existingNumbers as $existingNumber) {
                        $existingLookup[(string)$existingNumber] = true;
                    }

                    $rows = [];
                    foreach ($numbers as $number) {
                        $rows[] = [
                            'uid' => $user_info['id'],
                            'number' => $number,
                            'status' => isset($existingLookup[$number]) ? 1 : 0,
                        ];
                    }

                    $dbFacade = '\\think\\facade\\Db';
                    $dbFacade::startTrans();
                    try {
                        (new Batch())->saveAll($rows);
                        $dbFacade::commit();
                    } catch (\Throwable $e) {
                        $dbFacade::rollback();
                        return show(500, 'error', '导入失败');
                    }

                    return show(200, 'success', '导入成功');
                }
                return show(500, 'error', '导入失败');

            case 'del':
                $Batch_info = Batch::where('id', (int)($post_info['id'] ?? 0))->where('uid', $user_info['id'])->find();
                if (!$Batch_info) {
                    return show(500, 'error', '记录不存在');
                }
                if ($Batch_info->delete() === false) {
                    return show(500, 'error', '删除失败');
                }
                return show(200, 'success', '删除成功');

            case 'dels':
                $batch = Batch::where('uid', $user_info['id'])->select();
                foreach ($batch as $vo) {
                    Batch::destroy($vo['id']);
                }
                return show(200, 'success', '清除成功');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleUploadPost()
    {
        try {
            $fileBag = (array)$this->request->file();
            $keyname = array_key_first($fileBag);
            $file = $keyname !== null ? ($fileBag[$keyname] ?? null) : null;
            if (!is_object($file)) {
                return show(400, 'error', '请选择图片文件');
            }

            $uploader = new UploadService();
            $stored = $uploader->storeImageUpload($file, [
                'directory' => 'storage/picture',
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'empty_message' => '图片上传错误',
            ]);

            return show(200, 'success', '上传成功', [
                'url' => (string)($stored['public_path'] ?? ''),
                'path' => (string)($stored['relative_path'] ?? ''),
            ]);
        } catch (Exception $e) {
            return show(500, 'error', $e->getMessage());
        }
    }
}
