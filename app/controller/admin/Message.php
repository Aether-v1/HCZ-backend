<?php
// HCZ B04 + B06: Admin 域控制器。message_send / message_detail / message_pin / message_delete 业务实现（B06 迁移，Stage B）。
// 保持原业务语义；不新增 CSRF / 2FA / 额外权限；message_send 创建者 id 使用 currentAdminIdentity()['id']（B07 惰性身份，等价替换原 admin_info['id']）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\model\User as UserModel;
use app\model\UserMessage;
use app\service\UserMessageService;
use think\facade\Log;

class Message extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function message_send()
    {
        // P2-007: 消息发送权限检查
        if (!$this->authorize('admin.message.send')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $post_info = $this->request->post();

        try {
            $isGlobal = (int)($post_info['is_global'] ?? 0) > 0 ? 1 : 0;
            $userId = (int)($post_info['user_id'] ?? $post_info['uid'] ?? 0);
            $account = trim((string)($post_info['account'] ?? $post_info['mobile'] ?? ''));
            $title = trim((string)($post_info['title'] ?? ''));
            $summary = trim((string)($post_info['summary'] ?? ''));
            $content = trim((string)($post_info['content'] ?? ''));
            $isPinned = (int)($post_info['is_pinned'] ?? 0) > 0 ? 1 : 0;
            $messageType = UserMessageService::normalizeMessageType((string)($post_info['message_type'] ?? 'official'));
            $actionType = UserMessageService::normalizeActionType((string)($post_info['action_type'] ?? 'none'));
            $actionValue = trim((string)($post_info['action_value'] ?? ''));
            $normalizedActionValue = UserMessageService::normalizeActionValue($actionType, $actionValue);

            if ($title === '') {
                return show(500, 'error', '请输入消息标题');
            }
            if ($content === '') {
                return show(500, 'error', '请输入消息正文');
            }

            if ($isGlobal === 1) {
                $publishResult = UserMessageService::publishGlobalMessage(
                    $title,
                    $content,
                    'admin',
                    $actionType,
                    $normalizedActionValue,
                    (int)($this->currentAdminIdentity()['id'] ?? 0),
                    $summary === '' ? null : $summary,
                    $isPinned
                );

                $message = $publishResult['template'];
                $queued = (int)($publishResult['queued'] ?? 0);

                return show(200, 'success', '全局消息发送成功', [
                    'id' => (int)($message['id'] ?? 0),
                    'is_global' => 1,
                    'queued' => $queued,
                    'message_type' => 'global',
                ]);
            }

            $user = null;
            if ($userId > 0) {
                $user = UserModel::where('id', $userId)->find();
            }
            if (!$user && $account !== '') {
                $user = UserModel::where('mobile', $account)->find();
            }
            if (!$user && $account !== '') {
                $user = UserModel::where('nickname', $account)->find();
            }
            if (!$user && $account !== '') {
                $user = UserModel::where('surname', $account)->find();
            }
            if (!$user) {
                return show(500, 'error', '目标用户不存在，请检查用户ID或账号');
            }

            if ($actionType !== 'none' && $actionValue === '') {
                return show(500, 'error', '请选择动作类型后填写跳转地址');
            }
            if ($actionType !== 'none' && $normalizedActionValue === null) {
                return show(500, 'error', '跳转地址不安全或不在允许范围内');
            }

            $message = createUserMessage(
                (int)$user['id'],
                $title,
                $content,
                'admin',
                $messageType,
                null,
                $actionType,
                $normalizedActionValue,
                (int)($this->currentAdminIdentity()['id'] ?? 0),
                $summary === '' ? null : $summary,
                $isPinned
            );

            return show(200, 'success', '消息发送成功', [
                'id' => (int)($message['id'] ?? 0),
                'user_id' => (int)($user['id'] ?? 0),
                'account' => (string)($user['mobile'] ?? $user['nickname'] ?? $user['surname'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::error('admin message_send error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'post' => $post_info,
            ]);
            return show(500, 'error', '消息发送失败：' . $e->getMessage());
        }
    }

    public function message_detail()
    {
        if (!$this->authorize('admin.message.view')) {
            return $this->directDenyAdminPermission('admin.message.view');
        }

        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            return show(500, 'error', '缺少消息ID');
        }

        $message = UserMessage::find($id);
        if (!$message) {
            return show(500, 'error', '消息不存在');
        }

        $user = UserModel::field('id,mobile,nickname,surname')->find((int)($message['user_id'] ?? 0));
        $sender = AdminModel::field('id,name,account')->find((int)($message['sender_admin_id'] ?? 0));

        return show(200, 'success', '查询成功', [
            'id' => (int)($message['id'] ?? 0),
            'user_id' => (int)($message['user_id'] ?? 0),
            'title' => (string)($message['title'] ?? ''),
            'summary' => UserMessageService::buildSummary((string)($message['summary'] ?? ''), (string)($message['content'] ?? '')),
            'content' => (string)($message['content'] ?? ''),
            'source_type' => (string)($message['source_type'] ?? 'admin'),
            'message_type' => (string)($message['message_type'] ?? 'official'),
            'action_type' => (string)($message['action_type'] ?? 'none'),
            'action_value' => (string)($message['action_value'] ?? ''),
            'is_pinned' => (int)($message['is_pinned'] ?? 0),
            'is_read' => (int)($message['is_read'] ?? 0),
            'read_time' => (string)($message['read_time'] ?? ''),
            'created_at' => (string)($message['created_at'] ?? ''),
            'updated_at' => (string)($message['updated_at'] ?? ''),
            'user_info' => $user ? [
                'id' => (int)($user['id'] ?? 0),
                'mobile' => (string)($user['mobile'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
                'surname' => (string)($user['surname'] ?? ''),
                'account' => (string)($user['mobile'] ?? $user['nickname'] ?? $user['surname'] ?? ''),
            ] : null,
            'sender_admin' => $sender ? [
                'id' => (int)($sender['id'] ?? 0),
                'name' => (string)($sender['name'] ?? ''),
                'account' => (string)($sender['account'] ?? ''),
            ] : null,
        ]);
    }

    public function message_pin()
    {
        // P2-007: 消息置顶权限检查
        if (!$this->authorize('admin.message.manage')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $post_info = $this->request->post();
        $id = (int)($post_info['id'] ?? 0);
        $isPinned = (int)($post_info['is_pinned'] ?? -1);

        if ($id <= 0) {
            return show(500, 'error', '缺少消息ID');
        }

        if ($isPinned !== 0 && $isPinned !== 1) {
            return show(500, 'error', '置顶状态错误');
        }

        $message = UserMessage::find($id);
        if (!$message) {
            return show(500, 'error', '消息不存在');
        }

        $message->is_pinned = $isPinned;
        $message->save();

        return show(200, 'success', $isPinned === 1 ? '置顶成功' : '已取消置顶', [
            'id' => (int)($message['id'] ?? 0),
            'is_pinned' => (int)($message['is_pinned'] ?? 0),
        ]);
    }

    public function message_delete()
    {
        // P2-007: 消息删除权限检查
        if (!$this->authorize('admin.message.delete')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $post_info = $this->request->post();
        $id = (int)($post_info['id'] ?? 0);

        if ($id <= 0) {
            return show(500, 'error', '缺少消息ID');
        }

        $message = UserMessage::find($id);
        if (!$message) {
            return show(500, 'error', '消息不存在');
        }

        if ((int)($message['is_deleted'] ?? 0) === 1) {
            return show(200, 'success', '消息已删除', [
                'id' => (int)($message['id'] ?? 0),
                'deleted' => 1,
            ]);
        }

        $message->is_deleted = 1;
        $message->updated_at = date('Y-m-d H:i:s');
        $message->save();

        return show(200, 'success', '删除成功', [
            'id' => (int)($message['id'] ?? 0),
            'deleted' => 1,
        ]);
    }
}
