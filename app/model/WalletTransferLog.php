<?php
// app/model/WalletTransferLog.php
namespace app\model;

use think\Model;

class WalletTransferLog extends Model
{
    // 定义对应的数据表名（如果表名是 cz_wallet_transfer_log，且模型名符合驼峰规则，可省略）
    protected $table = 'cz_wallet_transfer_log';
    
    // 关闭自动时间戳（如果想让框架自动填充 create_time，可开启）
    protected $autoWriteTimestamp = false;
    
    // 允许批量赋值的字段
    protected $fillable = ['uid', 'from_type', 'to_type', 'amount', 'transfer_time', 'status'];
}