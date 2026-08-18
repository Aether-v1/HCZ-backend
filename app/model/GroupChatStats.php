<?php
namespace app\model;

use think\Model;

class GroupChatStats extends Model
{
    // 仅保留必要配置，排除所有可能的干扰
    protected $table = 'group_chat_stats'; // 强制指定表名
    protected $hidden = []; // 不隐藏任何字段
    protected $autoWriteTimestamp = false; // 禁用自动时间戳
    // 移除 $schema 和 $field 配置，让框架自动识别字段
}