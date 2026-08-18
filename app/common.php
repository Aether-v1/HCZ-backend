<?php

// 应用公共文件
use app\model\Cache as CacheModel;
use app\model\Config as ConfigModel;
use app\model\User as UserModel;
use app\model\Order;
use app\model\Product;
use app\model\RebateRecord;
use app\model\Phone;
use app\service\UserFundLedgerService;
use app\service\UserMessageService;
use think\facade\Db;
use think\facade\Log;
use app\model\WalletTransferLog;

use ip2region\Ip2Region;
use think\db\exception\DbException;
use think\facade\Request;
use think\facade\Session;
use think\response\Json;
use Yurun\Util\HttpRequest;
use yzh52521\filesystem\facade\Filesystem;

/**
 * Json接口统一输出
 * @param int $code 状态码
 * @param string $status 状态信息: "success" or "error"
 * @param string $message 返回内容
 * @param mixed $data 返回的数据
 * @param int $httpStatus HTTP状态码
 * @return Json
 */
function show(int $code = 200, string $status = 'success', string $message = "嗯", Mixed $data = null, int $httpStatus = 200): Json
{
    return json([
        "code"    => $code,
        "status"  => $status,
        "message" => $message,
        "data"    => $data,
        "success" => $status === "success",
    ], $httpStatus);
}

const AGENT_WALLET_TO_BALANCE_DUPLICATE_WINDOW_SECONDS = 8;

function agentWalletToBalanceUsingLedger($uid, $amount = null) {
    $transferLog = null;
    try {
        $ledger = new UserFundLedgerService();
        $user = $ledger->lockUser((int)$uid);
        if (empty($user)) {
            throw new \Exception('用户不存在');
        }

        if ($user->agent_status != 1) {
            throw new \Exception('未开通代理，无法将佣金钱包金额转入普通钱包');
        }

        $agentWallet = round(floatval($user->agent_wallet), 2);
        $currentBalance = round(floatval($user->balance), 2);
        $requestedAmount = $amount === null ? null : round(floatval($amount), 2);
        $recentTransferQuery = WalletTransferLog::where('uid', (int)$uid)
            ->where('from_type', 'agent_wallet')
            ->where('to_type', 'balance')
            ->where('status', 1)
            ->where('transfer_time', '>=', date('Y-m-d H:i:s', time() - AGENT_WALLET_TO_BALANCE_DUPLICATE_WINDOW_SECONDS))
            ->order('id', 'desc');

        if ($requestedAmount !== null) {
            $recentTransferQuery->where('amount', $requestedAmount);
        }

        $recentTransfer = $recentTransferQuery->find();
        if ($recentTransfer && ($requestedAmount !== null || $agentWallet <= 0)) {
            Db::commit();

            return [
                'status' => 1,
                'msg' => "成功转入" . round((float)($recentTransfer['amount'] ?? 0), 2) . "，当前普通钱包余额：{$currentBalance}"
            ];
        }

        if ($agentWallet <= 0) {
            throw new \Exception('佣金钱包余额为 0，无需转入');
        }

        if ($amount !== null) {
            $amount = $requestedAmount;
            if ($amount <= 0 || $amount > $agentWallet) {
                throw new \Exception('转入金额无效（需大于 0 且不超过佣金钱包余额）');
            }
        } else {
            $amount = $agentWallet;
        }

        $transferLog = WalletTransferLog::create([
            'uid' => (int)$uid,
            'from_type' => 'agent_wallet',
            'to_type' => 'balance',
            'amount' => $amount,
            'transfer_time' => date('Y-m-d H:i:s'),
            'status' => 0,
        ]);
        if (!$transferLog) {
            throw new \Exception('杞寘娴佹按鍒涘缓澶辫触');
        }

        $transferLogId = (int)($transferLog['id'] ?? 0);
        if ($transferLogId <= 0) {
            throw new \Exception('杞寘娴佹按涓氬姟 ID 寮傚父');
        }

        $bizNo = 'AWTB' . $transferLogId;
        $requestNo = 'agent_wallet_transfer:' . $transferLogId;
        $transferResult = $ledger->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_AGENT,
            UserFundLedgerService::WALLET_BALANCE,
            (float)$amount,
            [
                'biz_type' => 'agent_wallet_transfer',
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'operator_type' => 'user',
                'operator_id' => (int)$uid,
                'status' => 'done',
                'request_no' => $requestNo,
                'out_change_type' => 'agent_wallet_transfer_out',
                'in_change_type' => 'agent_wallet_transfer_in',
                'idempotent' => true,
                'remark' => '佣金钱包转入普通钱包',
                'extra' => [
                    'source' => 'agentWalletToBalance',
                    'from_wallet_type' => UserFundLedgerService::WALLET_AGENT,
                    'to_wallet_type' => UserFundLedgerService::WALLET_BALANCE,
                ],
            ]
        );

        $walletSnapshot = (array)($transferResult['wallet_snapshot'] ?? []);
        if (array_key_exists('balance', $walletSnapshot) && $walletSnapshot['balance'] !== null && $walletSnapshot['balance'] !== '') {
            $currentBalance = round((float)$walletSnapshot['balance'], 2);
        } elseif (isset($user['balance'])) {
            $currentBalance = round((float)$user['balance'], 2);
        } else {
            $latestBalance = UserModel::where('id', (int)$uid)->lock(true)->value('balance');
            $currentBalance = round((float)($latestBalance ?? 0), 2);
        }

        $transferLog->status = 1;
        $transferLog->transfer_time = date('Y-m-d H:i:s');
        if ($transferLog->save() === false) {
            throw new \Exception('杞寘娴佹按鏇存柊澶辫触');
        }

        Db::commit();

        return [
            'status' => 1,
            'msg' => "成功转入{$amount}，当前普通钱包余额：{$currentBalance}"
        ];
    } catch (\Exception $e) {
        if ($transferLog && (int)($transferLog['status'] ?? 0) !== 1) {
            try {
                $transferLog->delete();
            } catch (\Throwable $cleanupException) {
                Log::warning('agent wallet transfer log cleanup failed', [
                    'uid' => (int)$uid,
                    'transfer_log_id' => (int)($transferLog['id'] ?? 0),
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }

        Db::rollback();

        Log::error("agent wallet to balance failed: uid={$uid}, amount={$amount}, error={$e->getMessage()}");

        return [
            'status' => 0,
            'msg' => $e->getMessage()
        ];
    }
}

/**
 * API返回信息统一输出
 * @param int $code 状态码
 * @param string $status 状态信息: "success" or "error"
 * @param string $message 返回内容
 * @param mixed $data 返回的数据
 * @return array
 */
function shows(int $code = 200, string $status = 'success', string $message = "嗯", Mixed $data = null): array
{
    return [
        "code"    => $code,
        "status"  => $status,
        "message" => $message,
        "data"    => $data,
        "success" => $status === "success",
    ];
}

/**
 * 规范化并校验图片 URL，拦截危险协议。
 * 允许：
 * - http / https
 * - 站内相对路径
 * - data:image/* 内联图片
 *
 * 拦截：
 * - javascript:
 * - vbscript:
 * - 非图片的 data:
 *
 * @param mixed $value 原始 URL
 * @param string $fallback 校验失败时返回的兜底值
 * @return string
 */
function sanitizeImageUrl(mixed $value, string $fallback = ''): string
{
    $url = strip_tags((string) $value);
    $url = preg_replace('/[\x00-\x1F\x7F]+/u', '', $url ?? '');
    $url = trim((string) $url);

    if ($url === '') {
        return $fallback;
    }

    $normalized = ltrim($url);

    if (preg_match('/^(?:javascript|vbscript)\s*:/i', $normalized)) {
        return $fallback;
    }

    if (preg_match('/^data\s*:/i', $normalized) && !preg_match('/^data:image\//i', $normalized)) {
        return $fallback;
    }

    return $url;
}

/**
 * 字符过滤器（防XSS）
 * @param mixed $string 内容
 * @return mixed
 */
function createUserMessage(
    int $userId,
    string $title,
    string $content,
    string $sourceType = 'system',
    string $messageType = 'official',
    int|string|null $bizId = null,
    string $actionType = 'none',
    ?string $actionValue = null,
    ?int $senderAdminId = null,
    ?string $summary = null,
    int $isPinned = 0
): \app\model\UserMessage
{
    return UserMessageService::createUserMessage(
        $userId,
        $title,
        $content,
        $sourceType,
        $messageType,
        $bizId,
        $actionType,
        $actionValue,
        $senderAdminId,
        $summary,
        $isPinned
    );
}

function daddslashes(mixed $string): mixed
{
    if (is_array($string)) {
        foreach ($string as $key => $val) {
            $string[$key] = daddslashes($val);
        }
    } else {
        if (empty($string)) {
            return '';
        }
        $string = addslashes($string);
    }
    return $string;
}


/**
 * 随机生成字符
 * @param int $length 字符长度
 * @param string $method 方法：text or number
 * @return string
 */
function randomkeys(int $length, string $method = 'text'): string
{
    $key = '';
    if ($method === 'number') {
        $pattern = '1234567890';
        $max = 9;
    } elseif ($method === 'lowercase') {
        $pattern = '1234567890abcdefghijklmnopqrstuvwxyz';
        $max = 35;
    } elseif ($method === 'en') {
        $pattern = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLOMNOPQRSTUVWXYZ';
        $max = 51;
    } else {
        $pattern = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLOMNOPQRSTUVWXYZ';
        $max = 61;
    }
    for ($i = 0; $i < $length; $i++) {
        // 生成php随机数
        $key .= $pattern[random_int(0, $max)];
    }
    return $key;
}


/**
 * 随机生成汉字
 * @param int $num 字符长度
 * @return string
 */
function randomchinese(int $num): string
{
    $b = '';
    for ($i = 0; $i < $num; $i++) {
        // 使用chr()函数拼接双字节汉字，前一个chr()为高位字节，后一个为低位字节
        $a = chr(random_int(0xB0, 0xD0)) . chr(random_int(0xA1, 0xF0));
        // 转码
        $b .= iconv('GB2312', 'UTF-8', $a);
    }
    return $b;
}

/**
 * 替换字符串的中间部分
 * @param string $str 输入字符串
 * @param string $replaceChar 要替换的单个字符
 * @param int $leftLen 左边保留正常显示的长度
 * @param int $rightLen 右面保留正常显示的长度
 * @param bool $notEnoughReplace 计算后要替换的字符串长度不足时，输入的字符串是否进行整体替换
 * @return string
 */
function strReplaceMiddle(string $str, string $replaceChar = '*', int $leftLen = 3, int $rightLen = 3, bool $notEnoughReplace = true): string
{
    $len = mb_strlen($str);
    $replaceLen = $len - $leftLen - $rightLen;

    if ($replaceLen > 0) {
        $replaceStr = str_repeat($replaceChar, $replaceLen);
    } else {
        // 计算后要替换的字符串长度不足时，$replaceLen = $len - $frontLen - $backLen;
        $replaceStr = str_repeat($replaceChar, $len);
        return $notEnoughReplace ? $replaceStr : $str;
    }
    return mb_substr($str, 0, $leftLen) . $replaceStr . mb_substr($str, $leftLen + $replaceLen);
}

/**
 * 通过IP地址获取地理位置
 * @param string $ip IP地址
 * @return string 地理位置
 */
function getIpCity(string $ip = '127.0.0.1', $province = null): string
{
    try {
        // 无视IPv6地址
        if (Request::isValidIP($ip, 'ipv6')) {
            return '无法识别IPv6地理位置';
        }
        // 取得 国家|区域|省份|城市|ISP
        $citydata = Ip2Region::newWithVectorIndex()->search($ip);
        // 将地址信息根据|分割为数组并剔除空值与重复值
        $citydata = array_unique(array_filter(explode('|', $citydata)));
        $hello = explode(' ', implode(' ', $citydata));

        if($province == 1){
            return $hello[1] . ' - ' . $hello[2];
        }
        return $hello[1];
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

/**
 * 获取系统配置信息
 * @param null|string $name 字段名（英文），为空则以数组的形式返回全部
 * @return array|string|bool 内容
 */
function getConfig(null|string $name = null): array|string|bool
{
    // 如果没有向函数传入字段名，则读取缓存并直接以数组形式输出全部配置信息
    if (!empty($name)) {
        // 以字段名查找并输出对应的值，如不存在对应的值，则直接输出字符串空值
        return ConfigModel::where('k', '=', $name)->value('v', '');
    }
    // 从数据库缓存表内读取配置缓存，并通过tp框架自带缓存引擎缓存结果10秒
    $configCache = CacheModel::where('k', 'config')->value('v');
    // 如果缓存为空
    if (empty($configCache)) {
        // 从站点配置表内读取全部配置信息
        $result = ConfigModel::column('k,v');
        // 如果读取配置信息失败，返回false
        if (!$result || !is_array($result)) {
            return false;
        }
        $cache = [];
        foreach ($result as $row) {
            $cache[$row['k']] = $row['v'];
        }
        // 对站点配置信息进行序列化存储
        $results = serialize($cache);
        CacheModel::create([
            'k'      => 'config',
            'v'      => $results,
            'expire' => 0
        ], ['k', 'v', 'expire'], true);
        // 重新读取一遍缓存信息，并通过tp框架自带缓存引擎缓存结果10秒
        $configCache = CacheModel::where('k', 'config')->cache(10)->value('v');
    }
    // 对系统缓存站点配置信息进行反序列化
    $decoded = @unserialize($configCache, ['allowed_classes' => false]);
    return is_array($decoded) ? $decoded : false;
}





// 折扣查询
function discount($product_id, $amount_money)
{
    $Product = Product::find($product_id);
    // 修复：提前初始化变量，避免未定义
    $amount = $amount_money;
    $paymentAmount = $amount;
    $discount = 0;
    $inDiscountRange = 0; // 新增：默认不在折扣范围
    $discountAmount = 0;  // 新增：默认折扣金额为0

    foreach ($Product['discount']  as $vo) {
        if ($amount >= $vo["mini_amount"] && $amount <= $vo["maxi_amount"]) {
            $discountAmount = $amount - ($amount * ($vo["discount"] / 10));
            $paymentAmount = $amount - $discountAmount;
            $inDiscountRange = 1;
            $discount = $vo["discount"];
            break;
        }
    }
    $data = [
        'inDiscountRange' => $inDiscountRange, 
        'discountAmount' => number_format($discountAmount, 2), 
        'paymentAmount' => $paymentAmount, 
        'discount' => $discount, 
        'cnyAmount' => number_format($paymentAmount / (getConfig('rate') ?: 1), 2), // 修复：避免除以null
    ];
    return $data;
}

function order_original_pay_cny($order)
{
    return round((float)($order['amount_money'] ?? 0) - (float)($order['discount_amount'] ?? 0), 2);
}

function order_refund_usdt($order)
{
    return round((float)($order['settlement_refund_usdt_amount'] ?? 0), 2);
}

function order_actual_pay_usdt($order)
{
    return max(0, round((float)($order['cny_amount'] ?? 0) - order_refund_usdt($order), 2));
}

function order_commission_base_usdt($order)
{
    $commissionBase = round((float)($order['commission_base_snapshot'] ?? 0), 2);
    if ($commissionBase <= 0) {
        return order_actual_pay_usdt($order);
    }

    $originalSubstationPay = round((float)($order['cny_amount'] ?? 0), 2);
    if ($originalSubstationPay <= 0) {
        return max(0, $commissionBase);
    }

    $actualSubstationPay = order_actual_pay_usdt($order);
    $ratio = $actualSubstationPay <= 0 ? 0 : min(1, round($actualSubstationPay / $originalSubstationPay, 8));

    return max(0, round($commissionBase * $ratio, 2));
}

function order_display_received_cny($order)
{
    $amountReceived = round((float)($order['amount_received'] ?? 0), 2);
    if ($amountReceived > 0) {
        return $amountReceived;
    }
    if ((int)($order['status'] ?? 0) === 2) {
        return round((float)($order['amount_money'] ?? 0), 2);
    }
    return 0.00;
}

function order_final_pay_cny($order)
{
    if (isset($order['settlement_final_cny_amount']) && $order['settlement_final_cny_amount'] !== '' && $order['settlement_final_cny_amount'] !== null) {
        return round((float)$order['settlement_final_cny_amount'], 2);
    }
    return order_original_pay_cny($order);
}

function order_refundable_usdt($order)
{
    return max(0, round((float)($order['cny_amount'] ?? 0) - order_refund_usdt($order), 2));
}



function checkIfImageExists($imagePath) {
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') {
        return 2;
    }

    $parts = parse_url($imagePath);
    if ($parts !== false && (!empty($parts['scheme']) || !empty($parts['host']))) {
        return 2;
    }

    $normalizedPath = '/' . ltrim((string)($parts['path'] ?? $imagePath), '/');
    if (!str_starts_with($normalizedPath, '/upload/')) {
        return 2;
    }

    if (str_contains($normalizedPath, '..')) {
        return 2;
    }

    $publicRoot = realpath(app()->getRootPath() . 'public');
    $absolutePath = realpath(app()->getRootPath() . 'public' . $normalizedPath);
    if ($publicRoot === false || $absolutePath === false || !is_file($absolutePath)) {
        return 2;
    }

    $normalizedPublicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
    $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);
    if (!str_starts_with($normalizedAbsolutePath, $normalizedPublicRoot . '/')) {
        return 2;
    }

    return 1;
}



function rebate($order_number) {
    // 1. 基础数据空值校验（原有BUG1修复逻辑保留）
    $order_info = Order::where('order_number', $order_number)->find();
    if (empty($order_info)) {
        return 0;
    }
    
    $product_info = Product::find($order_info['product_id']);
    if (empty($product_info)) {
        return 0;
    }
    
    $user_info = UserModel::find($order_info['uid']);
    if (empty($user_info)) {
        return 0;
    }

    // 2. 分佣基数改为平台价口径；若订单发生部分退款，则按完成比例折算平台价分佣
    $cny_amount = order_commission_base_usdt($order_info);

    // 3. 封装通用返佣逻辑（简化重复代码，提升可维护性）
    $rebateHandler = function($level) use ($cny_amount, $product_info, $user_info, $order_info) {
        // 拼接字段名：kickback_rtion_1、tid_1 等
        $ratioField = "kickback_rtion_{$level}";
        $tidField = "tid_{$level}";
        
        // 基础条件校验
        if (empty($product_info[$ratioField]) || empty($user_info[$tidField])) {
            return;
        }
        
        // 计算佣金金额
        $amount = round((float)$cny_amount * ((float)$product_info[$ratioField] / 100), 2);
        if ($amount <= 0) {
            return;
        }

        $targetUid = (int)($user_info[$tidField] ?? 0);
        if ($targetUid <= 0) {
            return;
        }

        Db::transaction(function () use ($order_info, $level, $targetUid, $amount) {
            $t_user_info = UserModel::where('id', $targetUid)->lock(true)->find();
            if (empty($t_user_info)) {
                return;
            }

            $rebateRecord = RebateRecord::where('uid', (int)($order_info['uid'] ?? 0))
                ->where('tid', $targetUid)
                ->where('order_number', (string)($order_info['order_number'] ?? ''))
                ->where('level', $level)
                ->lock(true)
                ->find();

            if (!$rebateRecord) {
                $rebateRecord = RebateRecord::create([
                    'uid' => (int)($order_info['uid'] ?? 0),
                    'tid' => $targetUid,
                    'order_number' => (string)($order_info['order_number'] ?? ''),
                    'amount' => $amount,
                    'level' => $level,
                ]);
            }

            if (!$rebateRecord) {
                throw new \Exception('返佣记录创建失败');
            }

            $ledgerAmount = round((float)($rebateRecord['amount'] ?? $amount), 2);
            if ($ledgerAmount <= 0) {
                return;
            }

            $bizNo = (string)($order_info['order_number'] ?? '') . ':L' . (int)$level . ':T' . $targetUid;
            (new UserFundLedgerService())->changeLockedUserWallet(
                $t_user_info,
                UserFundLedgerService::WALLET_AGENT,
                $ledgerAmount,
                [
                    'biz_type' => 'agent_rebate',
                    'biz_id' => (int)($rebateRecord['id'] ?? 0),
                    'biz_no' => $bizNo,
                    'order_number' => (string)($order_info['order_number'] ?? ''),
                    'change_type' => 'agent_rebate_income',
                    'operator_type' => 'system',
                    'operator_id' => 0,
                    'status' => 'done',
                    'request_no' => 'agent_rebate_income:' . $bizNo,
                    'remark' => 'agent rebate income',
                    'idempotent' => true,
                    'extra' => [
                        'source' => 'rebate',
                        'source_order_number' => (string)($order_info['order_number'] ?? ''),
                        'level' => (int)$level,
                        'target_uid' => $targetUid,
                    ],
                ]
            );
        });
    };

    // 4. 执行1-10级返佣（调用封装函数，简化代码）
    for ($level = 1; $level <= 10; $level++) {
        $rebateHandler($level);
    }

    return 1;
}
function agentWalletToBalance($uid, $amount = null) {
    return agentWalletToBalanceUsingLedger($uid, $amount);
}

// 函数使用示例
// 示例1：转入全部佣金钱包金额
// $result = agentWalletToBalance(1001);
// 示例2：转入指定金额（如100元）
// $result = agentWalletToBalance(1001, 100);
// if ($result['status'] == 1) {
//     echo $result['msg'];
// } else {
//     echo "操作失败：{$result['msg']}";
// }

function power($power, $name)
{
    $selected = '1';
    if(strpos($power, $name) === false){
        $selected = '2';
    }
    return $selected;
}




function getTelecomOperator($phone, $type = 0) {
    $postData = array(
        'key' => 'b9I8kweccpt6uizL9rHtgFkGuS',
        'phone' => $phone
    );
    // 初始化 cURL
    $ch = curl_init();

    // 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, 'https://ap.xiaoyun.top/api/xy/xhzw');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));  // 将数据编码为 URL 字符串
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // 执行 cURL 请求
    $response = curl_exec($ch);
    // 关闭 cURL 资源
    curl_close($ch);

    if ($response === false) {
        return '查询失败';
    } else {
        $responseData = json_decode($response, true);
        if($responseData['code'] == 200){
            if($type == 0){
                
                return $responseData['data']['oldIsp'];
            }else{
                return $responseData['data']['area'] .' - ' . $responseData['data']['city'] . '（'. $responseData['data']['oldIsp']. '）';
            }
            
        }
        return '查询失败';
    }
}


function phone_yue_bak($phone) {
    if(getTelecomOperator($phone) == '移动'){
        $channel = 'mobile_balance';
    }
    if(getTelecomOperator($phone) == '联通'){
        $channel = 'unicom_balance';
    }
    if(getTelecomOperator($phone) == '电信'){
        $channel = 'telecom_balance';
    }
    
    $postData = array(
        'id' => '241111155762',
        'key' => '11882c0cf4235b24d86aa48cba76f172',
        'channel' => $channel,
        'mobile' => $phone
    );
    // 初始化 cURL
    $ch = curl_init();

    // 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, 'http://api.gfggf.cn/api/gateway');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));  // 将数据编码为 URL 字符串
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // 执行 cURL 请求
    $response = curl_exec($ch);

    // 关闭 cURL 资源
    curl_close($ch);

    // 处理响应
    if ($response === false) {
        return '查询失败';
    } else {
        $responseData = json_decode($response, true);
        if($responseData['code'] == 200){
            return $responseData['data']['curFee'];
        }
        return '查询失败';
    }
}


function nsend($API_URL, $get_post_data, $type, $ifsign, $sk = '')
{
    $get_post_data = http_build_query($get_post_data);
    if ($ifsign) {
        $sign = md5($get_post_data . $sk);
      
        $res = nsend_curl($API_URL, $type, $get_post_data, $sign);
    } else {
      
        $res = nsend_curl($API_URL, $type, $get_post_data, null);
    }
    return $res;
}


function phone_yue($phone) {
    $postData = array(
        'key' => 'TG:@pay5188888',
        'mobile' => $phone
    );
    $res = nsend('https://api.taolale.com/api/Inquiry_Phone_Charges/get',$postData,'POST',true,'');
    if($res['code'] != 200){
        return '查询失败,食不果腹,自行查询';
    }else{
        return $res['data']['mobile_fee'];
    }
}

function nsend_curl($API_URL, $type, $get_post_data, $sign= '')
{
    $ch = curl_init();
    $scheme = strtolower((string)parse_url((string)$API_URL, PHP_URL_SCHEME));
    if ($type == 'POST') {
        curl_setopt($ch, CURLOPT_URL, $API_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $get_post_data);
    } elseif ($type == 'GET') {
        curl_setopt($ch, CURLOPT_URL, $API_URL . '?' . $get_post_data);
    }
    if ($sign) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['sign:' . $sign]);
    }
    curl_setopt($ch, CURLOPT_REFERER, $API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($scheme === 'https') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    $resdata = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($resdata,true);
}



/**
 * 根据手机号获取运营商以及地理归属信息（国内）
 * @param int|string $phone 手机号
 * @return array|bool
 */
function phone_info(int|string $phone, $type = 0)
{
    if (!preg_match("/^1[3456789]\d{9}$/", $phone)) {
        return false;
    }
    try {
        $phone_info = Phone::find(substr($phone, 0, 7));
        if ($phone_info) {
            if($type == 0){
                return $phone_info['isp'];
            }else{
                return $phone_info['province'] .' - ' . $phone_info['city'] . '（'. $phone_info['isp']. '）';
            }
        }
        return false;
    } catch (DbException $e) {
        return [];
    }
}
