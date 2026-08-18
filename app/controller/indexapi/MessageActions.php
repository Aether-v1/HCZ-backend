<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\service\UserMessageService;
use think\facade\Db;

trait MessageActions
{
    private function normalizeUserMessageActionPayload($message): array
    {
        $actionType = UserMessageService::normalizeActionType((string)($message['action_type'] ?? 'none'));
        $actionValue = UserMessageService::normalizeActionValue($actionType, (string)($message['action_value'] ?? ''));
        if ($actionType !== 'none' && $actionValue === null) {
            $actionType = 'none';
        }

        return [
            'action_type' => $actionType,
            'action_value' => $actionValue ?? '',
        ];
    }

    private function normalizeUserMessageItem($message): array
    {
        $actionPayload = $this->normalizeUserMessageActionPayload($message);
        return [
            'id' => (int)($message['id'] ?? 0),
            'title' => trim((string)($message['title'] ?? '')),
            'summary' => UserMessageService::buildSummary((string)($message['summary'] ?? ''), (string)($message['content'] ?? '')),
            'content' => (string)($message['content'] ?? ''),
            'source_type' => (string)($message['source_type'] ?? 'system'),
            'message_type' => (string)($message['message_type'] ?? 'official'),
            'action_type' => $actionPayload['action_type'],
            'action_value' => $actionPayload['action_value'],
            'is_pinned' => (int)($message['is_pinned'] ?? 0),
            'is_read' => (int)($message['is_read'] ?? 0),
            'created_at' => (string)($message['created_at'] ?? ''),
        ];
    }

    private function normalizeUserMessageDetail($message): array
    {
        $title = trim((string)($message['title'] ?? ''));
        $summary = UserMessageService::buildSummary((string)($message['summary'] ?? ''), (string)($message['content'] ?? ''));
        $content = trim((string)($message['content'] ?? ''));
        $actionPayload = $this->normalizeUserMessageActionPayload($message);

        return [
            'id' => (int)($message['id'] ?? 0),
            'title' => $title !== '' ? $title : '消息通知',
            'summary' => $summary,
            'content' => $content !== '' ? $content : $summary,
            'source_type' => (string)($message['source_type'] ?? 'system'),
            'message_type' => (string)($message['message_type'] ?? 'official'),
            'biz_id' => (int)($message['biz_id'] ?? 0),
            'action_type' => $actionPayload['action_type'],
            'action_value' => $actionPayload['action_value'],
            'is_pinned' => (int)($message['is_pinned'] ?? 0),
            'is_read' => (int)($message['is_read'] ?? 0),
            'read_time' => (string)($message['read_time'] ?? ''),
            'created_at' => (string)($message['created_at'] ?? ''),
            'updated_at' => (string)($message['updated_at'] ?? ''),
        ];
    }

    protected function handleApiUserMessages()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        UserMessageService::syncGlobalMessagesForUser($uid);

        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(50, (int)$this->request->get('pageSize', $this->request->get('limit', 20))));

        $buildQuery = function () use ($uid) {
            return Db::name('user_message')->where('user_id', $uid)
                ->where('is_deleted', 0)
                ->where('title', '<>', '')
                ->where('content', '<>', '');
        };

        $rows = $buildQuery()
            ->order('is_pinned', 'desc')
            ->order('created_at', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $row) {
            $list[] = $this->normalizeUserMessageItem($row);
        }

        $total = (int)$buildQuery()->count();

        return $this->apiOk('查询成功', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
        ]);
    }

    protected function handleApiUserMessageDetail()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            return $this->apiError('缺少消息ID', 400);
        }

        $message = Db::name('user_message')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->where('is_deleted', 0)
            ->where('title', '<>', '')
            ->where('content', '<>', '')
            ->find();

        if (!$message) {
            return $this->apiError('消息不存在', 404);
        }

        return $this->apiOk('查询成功', $this->normalizeUserMessageDetail($message));
    }

    protected function handleApiUserMessageRead()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        $post = $this->readRequestPayload();
        $id = (int)($post['id'] ?? 0);
        if ($id <= 0) {
            return $this->apiError('缺少消息ID', 400);
        }

        $message = Db::name('user_message')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->where('is_deleted', 0)
            ->where('title', '<>', '')
            ->where('content', '<>', '')
            ->find();

        if (!$message) {
            return $this->apiError('消息不存在', 404);
        }

        $changed = 0;
        if ((int)($message['is_read'] ?? 0) !== 1) {
            $changed = (int)Db::name('user_message')
                ->where('id', $id)
                ->where('user_id', $uid)
                ->where('is_deleted', 0)
                ->where('is_read', 0)
                ->update([
                    'is_read' => 1,
                    'read_time' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if ($changed > 0) {
                $message['is_read'] = 1;
                $message['read_time'] = date('Y-m-d H:i:s');
            }
        }

        return $this->apiOk('操作成功', [
            'id' => (int)($message['id'] ?? 0),
            'is_read' => (int)($message['is_read'] ?? 0),
            'read_time' => (string)($message['read_time'] ?? ''),
            'changed' => $changed > 0 ? 1 : 0,
        ]);
    }

    protected function handleApiUserMessageReadAll()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        $affected = (int)Db::name('user_message')
            ->where('user_id', $uid)
            ->where('is_deleted', 0)
            ->where('is_read', 0)
            ->where('title', '<>', '')
            ->where('content', '<>', '')
            ->update([
                'is_read' => 1,
                'read_time' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->apiOk('操作成功', [
            'affected' => $affected,
        ]);
    }

    protected function handleApiUserMessageUnreadCount()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        UserMessageService::syncGlobalMessagesForUser($uid);

        $count = (int)Db::name('user_message')
            ->where('is_deleted', 0)
            ->where('is_read', 0)
            ->where('user_id', $uid)
            ->where('title', '<>', '')
            ->where('content', '<>', '')
            ->count();

        return $this->apiOk('查询成功', [
            'count' => $count,
        ]);
    }

    protected function handleApiUserMessageDelete()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        $post = $this->readRequestPayload();
        $id = (int)($post['id'] ?? 0);
        if ($id <= 0) {
            return $this->apiError('缺少消息ID', 400);
        }

        $message = Db::name('user_message')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->where('is_deleted', 0)
            ->find();

        if (!$message) {
            return $this->apiError('消息不存在', 404);
        }

        $affected = (int)Db::name('user_message')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->where('is_deleted', 0)
            ->update([
                'is_deleted' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->apiOk('删除成功', [
            'id' => (int)($message['id'] ?? 0),
            'deleted' => $affected > 0 ? 1 : 0,
        ]);
    }
}
