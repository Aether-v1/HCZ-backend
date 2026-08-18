<?php
declare (strict_types=1);
namespace app\controller;
use app\middleware\UserAuth;
use app\model\User as UserModel;
use app\model\Batch;
use app\model\BankCard;
use app\model\Order;
use app\model\Product;
use app\model\Slide;
use app\model\Recharge;
use app\model\RebateRecord;
use app\model\Withdrawal;
use app\model\UserBalanceLog;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\PaymentVoucher as PaymentVoucherModel;
use app\service\UserFundLogLabelService;
use think\facade\Session;
use think\App;
use think\facade\View;
use think\facade\Route;
use think\Request;
use think\facade\Log;
use app\model\WalletTransferLog;
use think\facade\Db;

use app\model\CheckinRecord;
use app\model\PointsRecord;
class Index 
{
    /**
     * Request实例
     * @var Request
     */
    protected Request $request;

    /**
     * 应用实例
     * @var App
     */
    protected App $app;

    protected mixed $user_info;
    protected string|array|bool $config = [];
    protected array $middleware = [UserAuth::class];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 将当前登录管理员信息写入至私有属性
        $this->user_info = $this->request->session('user');
        $this->config = getConfig();
        View::assign('user', $this->user_info);
        View::assign('config', $this->config);
        if (isset($this->user_info['id'])) {
            $user_info = UserModel::where('id', $this->user_info['id'])->find();
            View::assign('user_info', $user_info);
        }
    }

    public function index()
    {
        
        View::assign('Product_list', Product::where('status', 1)->where('type', 1)->order('sort', 'desc')->select());

        View::assign('b_product', Product::find(getConfig('a_recommend_id')));
        View::assign('a_product', Product::find(getConfig('b_recommend_id')));


        View::assign('Slide_list', Slide::select());
        return View::fetch();
    }
    
    public function query_business()
    {
        View::assign('Product_list', Product::where('type', 2)->order('sort', 'desc')->select());
        return View::fetch();
    }

    public function query_business_page($id)
    {
        $Product = Product::find($id);
        View::assign('product', $Product);
        return View::fetch();
    }
    
    public function cors()
    {
        // 如果你希望访问cors页面不需要登录（跳过UserAuth中间件），可以临时注释下面的判断
        // if (empty($this->user_info['id'])) {
        //     return redirect(Route::buildUrl('login'));
        // }
        
        return View::fetch('cors');
    }
	
    public function order_cz()
    {
        View::assign('product_list', Product::where('type', 1)->field('id,name')->select());
        return View::fetch();
    }

    public function order_cx()
    {
        View::assign('product_list', Product::where('type', 1)->field('id,name')->select());
        return View::fetch();
    }

    public function out_order()
    {
        return View::fetch();
    }

    public function order_voucher()
    {
      $get_info = $this->request->get();    
      View::assign('order_id', $get_info['id']);    
      return View::fetch();
    }

    public function order_voucher_edit()
    {
      $get_info = $this->request->get();
      $list = PaymentVoucherModel::where('order_id', $get_info['order_id'])->find();   
      if(!$list){
          $list['order_id'] = $get_info['order_id'];
          $list['name'] = '';
          $list['money'] = '';
          for($a =1 ;$a<=8;$a++){
              $list['title'.$a] = '';
              $list['remark'.$a] ='';
          }
      }
      View::assign('list', $list);        
      return View::fetch();
    }


    public function order()
    {
        return View::fetch();
    }
    
public function order_info()
{
    // 1. 验证订单号参数是否存在
    if (!isset($_REQUEST['order_number']) || empty($_REQUEST['order_number'])) {
        return '缺少订单编号参数';
    }
    
    $orderNumber = $_REQUEST['order_number'];
    
    // 2. 验证用户信息是否存在
    if (empty($this->user_info['id'])) {
        return '用户信息错误';
    }
    
    // 3. 查询订单（使用参数绑定，更安全）
    $order = Order::where('uid', $this->user_info['id'])
                  ->where('order_number', $orderNumber)
                  ->find();
                  
    if (!$order) {
        return '订单不存在';
    }
    
    // 4. 处理订单信息
    $order_info = '';
    // 确保order_info是数组，避免foreach错误
    $orderDetails = is_array($order['order_info']) ? $order['order_info'] : [];
    
    foreach ($orderDetails as $item) {
        if (preg_match('/\[(.*?)\](.*)/', $item, $matches)) {
            // 验证正则匹配结果
            if (count($matches) < 3) {
                continue; // 跳过格式不正确的项
            }
            
            $result = checkIfImageExists(url('/')->domain(true) . $matches[2]);
            
            if ($result == 1) {
                // 处理图片类型
                $order_info .= '
                <div class="flexJA flexSb">
                    <div class="num">'.$matches[1].':<img src="'.$matches[2].'" style="width: 50px;" alt="'.$matches[1].'"></div>
                </div>';
            } else {
                // 处理文本类型，注意转义特殊字符
                $value = htmlspecialchars($matches[2], ENT_QUOTES);
                $order_info .= '
                <div class="flexJA flexSb">
                    <div class="num">'.$matches[1].':'.$value.'</div>
                    <div class="num" onclick="copyToClipboard(\''.addslashes($value).'\')">复制</div>
                </div>';
                
                // 处理运营商信息
                $operator = phone_info($value);
                if (in_array($operator, ['移动', '联通', '电信'])) {
                    $order_info .= '
                    <div class="flexJA flexSb">
                        <div class="num">运营商:'.phone_info($value, 1).'</div>
                    </div>';
                }
                
                // 处理余额信息
                if (!empty($order['phone_yue_a'])) {
                    $order_info .= '
                    <div class="flexJA flexSb">
                        <div class="num">下单前余额:'.$order['phone_yue_a'].'</div>
                    </div>';
                }
            }
        }
    }
    
    // 5. 分配变量到视图
    $orderAmountReceivedDisplay = number_format(order_display_received_cny($order), 2, '.', '');
    $orderRefundWalletAmount = number_format(order_refund_usdt($order), 2, '.', '');
    View::assign('order_infos', $order_info);
    View::assign('order_info', $order);
    View::assign('order_amount_received_display', $orderAmountReceivedDisplay);
    View::assign('order_refund_wallet_amount', $orderRefundWalletAmount);
    
    return View::fetch();
}
    

    
    public function recharge_withdrawal()
    {
        View::assign('recharge_list', Recharge::where('uid', $this->user_info['id'])->order('id', 'desc')->select());
        View::assign('withdrawal_list', Withdrawal::where('uid', $this->user_info['id'])->order('id', 'desc')->select());
        return View::fetch();
    }

public function agentWalletTransfer()
{
    $uid = 0; // 初始化避免catch块未定义
    try {
        // 1. 验证用户登录
        if (empty($this->user_info) || empty($this->user_info['id'])) {
            return json(['status' => 0, 'msg' => '请先登录后操作']);
        }
        $uid = intval($this->user_info['id']);

        // 2. 获取并校验金额
        $amount = floatval($this->request->post('amount', 0));
        if ($amount <= 0) {
            return json(['status' => 0, 'msg' => '转入金额必须大于0']);
        }

        // 3. 确保核心函数存在（防止common.php未自动加载）
        if (!function_exists('agentWalletToBalance')) {
            require_once app_path() . '/common.php';
        }

        // 4. 执行转入逻辑
        $result = agentWalletToBalance($uid, $amount);
        return json($result);

    } catch (\Exception $e) {
        Log::error("佣金钱包转入接口异常：UID={$uid}，错误：{$e->getMessage()}");
        return json(['status' => 0, 'msg' => '系统异常，请稍后重试']);
    }
}
	
    public function recharge($order_number)
    {
        $Recharge = Recharge::where('order_number', $order_number)->find();
        if($Recharge){
            View::assign('recharge', $Recharge);
            return View::fetch();
        }
        return '订单不存在';
    }

    public function withdrawal_confirm()
    {
        return View::fetch();
    }

    public function wallet_details()
    {
        View::assign('product_cz_list', Product::where('type', 1)->order('id', 'desc')->select());

        return View::fetch();
    }

    public function wallet_details_data()
    {
        $wallet_details_data = '';
        // 冻结金额
        if($_REQUEST['type'] == 1){
            $wallet_details_data .= '<div class="list">';
            $order_1 = Order::where('uid', $this->user_info['id'])->where('status', 'in', '0,1,2')->where('confirm_status', 'in', '0,1')->where('type', 1)->select();
            foreach($order_1 as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">金额冻结</div>
                            <div class="num">'.number_format((float)($vo['amount'] ?? 0), 2, '.', '').'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">充值订单</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $order_2 = Order::where('uid', $this->user_info['id'])->where('status', 'in', '0,1')->where('type', 2)->select();
            foreach($order_2 as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">金额冻结</div>
                            <div class="num">'.number_format(order_actual_pay_usdt($vo), 2, '.', '').'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">线索提供</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            
            $T_product = TransactionProduct::where('uid', $this->user_info['id'])->where('status', 'in', '1,2')->select();
            foreach($T_product as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">金额冻结</div>
                            <div class="num">'.$vo['sell_account'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">交易挂单</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 订单退款
        if($_REQUEST['type'] == 2){
            $wallet_details_data .= '<div class="list">';
            $labelService = new UserFundLogLabelService();
            $order_1 = \app\model\UserFundLog::where('uid', $this->user_info['id'])
                ->where('wallet_type', 'balance')
                ->whereIn('change_type', ['product_order_cancel_refund', 'product_order_partial_refund'])
                ->order('id', 'desc')
                ->select();
            foreach($order_1 as $key => $vo) {
                $refundText = $labelService->displayLabel((string)($vo['change_type'] ?? ''), 'balance') . ' ' . (string)($vo['order_number'] ?? '');
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">订单退款</div>
                            <div class="num">'.number_format((float)($vo['amount'] ?? 0), 2, '.', '').'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">'.$refundText.'</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.($vo['create_time'] ?? $vo['created_at'] ?? '').'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 充值业务
        $parts = explode("_", $_REQUEST['type']);
        if($parts[0] == 'product'){
            $wallet_details_data .= '<div class="list">';
            $order = Order::where('uid', $this->user_info['id'])->where('product_id', $parts[1])->select();
            foreach($order as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">'.$vo['product_info']['name'].'</div>
                            <div class="num">'.number_format(order_actual_pay_usdt($vo), 2, '.', '').'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">充值订单</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 查询业务
        if($_REQUEST['type'] == 3){
            $wallet_details_data .= '<div class="list">';
            $order_1 = Order::where('uid', $this->user_info['id'])->where('type', 2)->select();
            foreach($order_1 as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">'.$vo['product_info']['name'].'</div>
                            <div class="num">'.$vo['cny_amount'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">线索提供</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 提现U币
        if($_REQUEST['type'] == 4){
            $wallet_details_data .= '<div class="list">';
            $Withdrawal = Withdrawal::where('uid', $this->user_info['id'])->select();
            foreach($Withdrawal as $key => $vo) {
                if($vo['status'] == 0){
                    $status = '申请中';
                }
                if($vo['status'] == 1){
                    $status = '提现成功';
                }
                if($vo['status'] == 2){
                    $status = '提现失败';
                }
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">余额提现</div>
                            <div class="num">'.$vo['amount'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">'.$status.'</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 充值U币
        if($_REQUEST['type'] == 5){
            $wallet_details_data .= '<div class="list">';
            $Recharge = Recharge::where('uid', $this->user_info['id'])->select();
            foreach($Recharge as $key => $vo) {
                if($vo['status'] == 0){
                    $status = '待汇款提交';
                }
                if($vo['status'] == 1){
                    $status = '已提交';
                }
                if($vo['status'] == 2){
                    $status = '取消订单';
                }
                if($vo['status'] == 3){
                    $status = '订单完成';
                }
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">余额充值</div>
                            <div class="num">'.$vo['amount'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text">'.$status.'</div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 代理分润
        if($_REQUEST['type'] == 6){
            $wallet_details_data .= '<div class="list">';
            $RebateRecord = RebateRecord::where('tid', $this->user_info['id'])->select();
            foreach($RebateRecord as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">返佣分润</div>
                            <div class="num">'.$vo['amount'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text"></div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }


        // 交易买入
        if($_REQUEST['type'] == 7){
            $wallet_details_data .= '<div class="list">';
            $TransactionOrder = TransactionOrder::where('uid', $this->user_info['id'])->select();
            foreach($TransactionOrder as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">交易买入</div>
                            <div class="num">'.$vo['usdt_amount'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text"></div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }

        // 交易卖出
        if($_REQUEST['type'] == 8){
            $wallet_details_data .= '<div class="list">';
            $TransactionOrder = TransactionOrder::where('sell_uid', $this->user_info['id'])->select();
            foreach($TransactionOrder as $key => $vo) {
                $wallet_details_data .= '
                <div class="item flexJA flexFs flexAis">
                    <div class="icon">
                        <div style="background-image: url(/static/index/image/unusable_amount_icon.png); background-position: center center; background-size: cover; background-repeat: no-repeat;height: 100%;"></div>
                    </div>
                    <div class="right">
                        <div class="bar flexJA flexSb">
                            <div class="title">交易卖出</div>
                            <div class="num">'.$vo['pay_amount'].'</div>
                        </div>
                        <div class="bar flexJA flexSb">
                            <div class="text"></div>
                            <div class="unit">USDT</div>
                        </div>
                        <div class="date">'.$vo['create_time'].'</div>
                    </div>
                </div>';
            }
            $wallet_details_data .= '</div>';
        }



        View::assign('wallet_details_data', $wallet_details_data);
        return View::fetch();
    }
    
    
    public function my()
    {
        // 验证用户登录状态
        if (empty($this->user_info['id'])) {
            return redirect(Route::buildUrl('login'));
        }
        
        View::assign('order_cz_count', Order::where('uid', $this->user_info['id'])->where('type', 1)->count());
        View::assign('order_cx_count', Order::where('uid', $this->user_info['id'])->where('type', 2)->count());
        

        $order_1 = 0.00;
        foreach (Order::where('uid', $this->user_info['id'])->where('status', 'in', '0,1,2')->where('confirm_status', 'in', '0,1,3')->where('type', 1)->select() as $vo) {
            $order_1 = round($order_1 + order_actual_pay_usdt($vo), 2);
        }
        $order_2 = 0.00;
        foreach (Order::where('uid', $this->user_info['id'])->where('status', 'in', '0,1')->where('type', 2)->select() as $vo) {
            $order_2 = round($order_2 + order_actual_pay_usdt($vo), 2);
        }
        $T_product = TransactionProduct::where('uid', $this->user_info['id'])->where('status', 'in', '1,2')->sum('sell_account');
       
        View::assign('frozen_amount', number_format($order_1 + $order_2 + $T_product, 2));

        $TransactionOrder_count = TransactionOrder::where('uid|sell_uid', '=', $this->user_info['id'])->where('status', 'in', '0,1')->count();
        View::assign('TransactionOrder_count', $TransactionOrder_count);
        return View::fetch();
    }

    public function account_settings()
    {
        return View::fetch();
    }

    public function information()
    {
        return View::fetch();
    }

    public function password()
    {
        return View::fetch();
    }

    public function wallet_address()
    {
        return View::fetch();
    }
    
    public function agreement()
    {
        return View::fetch();
    }
    
    public function privacy_policy()
    {
        return View::fetch();
    }
    
    public function oil_card_list()
    {
        return View::fetch();
    }
    
    public function invite_friends()
    {
        return View::fetch();
    }
    
    public function chat_message()
    {
        return View::fetch();
    }
    
    public function agency_center()
    {
        $rebate_jr = RebateRecord::where('tid', $this->user_info['id'])->whereDay('create_time')->sum('amount');
        View::assign('rebate_jr', number_format($rebate_jr, 2));

        $rebate_s = RebateRecord::where('tid', $this->user_info['id'])->sum('amount');
        View::assign('rebate_s', number_format($rebate_s, 2));

        return View::fetch();
    }
    
public function agency_center_list()
    {
        if (!$this->user_info) {
            return json(['code' => 401, 'msg' => '请先登录']);
        }
        
        // 当前登录用户ID
        $currentUserId = $this->user_info['id'];
        $level = $this->request->post('type', 1);
        
        if ($level < 1 || $level > 10) {
            return json(['code' => 400, 'msg' => '代理等级必须在1-10之间']);
        }
        
        // 确定对应等级的上级ID字段
        $tidField = "tid_{$level}";
        
        // 1. 从UserModel查询该等级下的所有下级用户
        $subUsers = UserModel::where($tidField, $currentUserId)
            ->where('status', 1) // 只查询状态正常的用户
            ->select()
            ->toArray();
        
        $data = [];
        foreach ($subUsers as $user) {
            // 从UserModel获取用户信息
            $userName = '';
            if (!empty($user['nickname'])) {
                $userName = $user['nickname'];
            } elseif (!empty($user['surname'])) {
                $userName = $user['surname'];
            } elseif (!empty($user['mobile'])) {
                $userName = '用户' . substr($user['mobile'], -4);
            } else {
                $userName = '用户ID:' . $user['id'];
            }
            
            $userAvatar = $user['avatar'] ?? '/default-avatar.png';
            $joinTime = $user['create_time'] ?? '';
            
            // 2. 从RebateRecord查询该用户的总返佣金额
            $totalRebate = RebateRecord::where([
                'tid' => $currentUserId,
                'uid' => $user['id'],
                'level' => $level
            ])->sum('amount') ?? 0;
            
            // 获取最近的返佣记录时间
            $latestRebateTime = RebateRecord::where([
                'tid' => $currentUserId,
                'uid' => $user['id'],
                'level' => $level
            ])->order('create_time', 'desc')
              ->value('create_time');
            
            $data[] = [
                // 用户信息（来自UserModel）
                'user_id' => $user['id'],
                'user_name' => $userName,
                'user_avatar' => $userAvatar,
                'join_time' => $joinTime,
                
                // 返佣信息（来自RebateRecord）
                'total_rebate' => number_format($totalRebate, 2),
                'latest_rebate_time' => $latestRebateTime ?? '',
                'rebate_level' => $level,
                
                // 关联信息
                'parent_id' => $currentUserId
            ];
        }
        
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $data,
            ]
        ]);
    }

    
    public function bank_card()
    {
        View::assign('BankCard_list', BankCard::where('uid', $this->user_info['id'])->order('id', 'desc')->select());
        return View::fetch();
    }
    public function bank_card_add_modify()
    {
        $BankCard_info = BankCard::where('uid', $this->user_info['id'])->find($_REQUEST['id']??'');
        View::assign('BankCard_info', $BankCard_info);

        return View::fetch();
    }
    

    public function product($id)
    {
        $Product = Product::find($id);

        View::assign('product', $Product);

        $order_info = $Product['order_info'];
        usort($order_info, function($a, $b) {
            return intval($b['sort']) - intval($a['sort']);
        });
        View::assign('order_info', $order_info);
        
        // 面值
        $par_values = $Product['par_value'];
        usort($par_values, function($a, $b) {
            return intval($a['value']) - intval($b['value']);
        });
        View::assign('par_values', $par_values);

        
        View::assign('batch_ok_count', Batch::where('uid', $this->user_info['id'])->where('status', 0)->count());
        return View::fetch();
    }

    public function batch()
    {
        return View::fetch();
    }

    public function transaction_index()
    {
        return View::fetch();
    }
    
    public function transaction_my_sale()
    {
        return View::fetch();
    }

    public function transaction_sale_edit()
    {
        $TransactionProduct_info = TransactionProduct::where('uid', $this->user_info['id'])->where('status', 'in', '1,2')->find($_REQUEST['id']??'');
        View::assign('TransactionProduct_info', $TransactionProduct_info);
        return View::fetch();
    }
    
    public function transaction_buy()
    {
        $TransactionProduct_info = TransactionProduct::where('status', 'in', '1')->find($_REQUEST['id']??'');
        View::assign('TransactionProduct_info', $TransactionProduct_info);
        
        $sell_uid_info = UserModel::where('id', $TransactionProduct_info['uid'])->find();
        View::assign('sell_uid_info', $sell_uid_info);

        return View::fetch();
    }

    public function transaction_order()
    {
        return View::fetch();
    }

    public function transaction_trading_details($order_number)
    {
        View::assign('TransactionOrder_info', TransactionOrder::where('order_number', $order_number)->find());
        return View::fetch();
    }
    
    public function login()
    {
        return View::fetch();
    }

    public function register()
    {
        return View::fetch();
    }

    public function modern_twofa()
    {
        $user_2fa_status = UserModel::where('id', $this->user_info['id'])
            ->value('twofa_enabled'); 
        View::assign('user_2fa_status', $user_2fa_status);
        
        return View::fetch();
    }

    public function order_query()
    {
        return View::fetch();
    }

    public function twofa_verify()
    {
        // 获取用户2FA状态（可选，用于页面判断）
        $user_2fa = UserModel::where('id', $this->user_info['id'])
            ->field('twofa_enabled, twofa_secret')
            ->find();
        
        View::assign('user_2fa', $user_2fa);
        return View::fetch(); // 对应视图文件 twofa_verify.html
    }

    // 2FA禁用页面（需密码验证）
    public function twofa_disable()
    {
        // 可添加判断：如果用户未开启2FA，跳转到绑定页面
        $user_2fa_enabled = UserModel::where('id', $this->user_info['id'])
            ->value('twofa_enabled');
        
        if (!$user_2fa_enabled) {
            return redirect(Route::buildUrl('modern_twofa'));
        }
        
        return View::fetch(); // 对应视图文件 twofa_disable.html
    }

    // 2FA恢复码管理页面（查看/重新生成）
    public function twofa_recovery()
    {
        // 获取用户的恢复码（注意：实际应加密存储，此处仅为示例）
        $recovery_codes = UserModel::where('id', $this->user_info['id'])
            ->value('twofa_recovery_codes');
        
        // 恢复码通常是数组，需处理后传给视图
        View::assign('recovery_codes', $recovery_codes ? explode(',', $recovery_codes) : []);
        return View::fetch(); // 对应视图文件 twofa_recovery.html
    }
    
    // 在Index控制器中添加以下方法

    /**
     * 积分首页
     */
    public function points()
    {
        // 验证用户登录状态
        if (empty($this->user_info['id'])) {
            return redirect(Route::buildUrl('login'));
        }
        
        // 获取用户积分信息
        $user = UserModel::where('id', $this->user_info['id'])
            ->field('id, points_balance, month_earned, month_used, total_earned')
            ->find();
        
        if (!$user) {
            return '用户信息不存在';
        }
        
        // 获取签到信息
        $checkinInfo = $this->getUserCheckinInfo($this->user_info['id']);
        
        View::assign([
            'points_balance' => $user['points_balance'] ?? 0,
            'month_earned' => $user['month_earned'] ?? 0,
            'month_used' => $user['month_used'] ?? 0,
            'total_earned' => $user['total_earned'] ?? 0,
            'checkin_info' => $checkinInfo
        ]);
        
        return View::fetch('points');
    }

    /**
     * 获取用户签到信息 - 已修复exists()方法错误
     */
    private function getUserCheckinInfo($userId)
    {
        // 假设存在签到记录表
        $today = date('Y-m-d');
        $startOfMonth = date('Y-m-01');
        
        // 检查今天是否已签到 - 修改此处
        $isCheckedIn = CheckinRecord::where('uid', $userId)
            ->where('checkin_date', $today)
            ->count() > 0;  // 用count() > 0替代exists()
        
        // 获取连续签到天数，从昨天开始计算
        $continuousDays = 0;
        $currentDate = date('Y-m-d', strtotime($today . ' -1 day')); // 从昨天开始检查
        
        while (true) {
            $hasCheckin = CheckinRecord::where('uid', $userId)
                ->where('checkin_date', $currentDate)
                ->count() > 0;  // 用count() > 0替代exists()
            
            if ($hasCheckin) {
                $continuousDays++;
                $currentDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
            } else {
                break;
            }
        }
        
        return [
            'is_checked_in' => $isCheckedIn,
            'continuous_days' => $continuousDays,
            'month_checkin_count' => CheckinRecord::where('uid', $userId)
                ->where('checkin_date', '>=', $startOfMonth)
                ->count()
        ];
    }

    /**
     * 处理用户签到
     */
    public function checkin()
    {
        if (empty($this->user_info['id'])) {
            return json(['code' => 401, 'msg' => '请先登录']);
        }
        
        $userId = $this->user_info['id'];
        $today = date('Y-m-d');
        
        // 检查是否已签到 - 修改此处
        $existingCheckin = CheckinRecord::where('uid', $userId)
            ->where('checkin_date', $today)
            ->find();
        
        if ($existingCheckin) {
            return json([
                'code' => 400,
                'msg' => '今天已经签到过了'
            ]);
        }
        
        // 获取连续签到天数计算奖励积分
        $checkinInfo = $this->getUserCheckinInfo($userId);
        $points = $this->calculateCheckinPoints($checkinInfo['continuous_days']);
        
        // 开启事务
        $this->app->db->startTrans();
        try {
            // 创建签到记录
            $checkin = new CheckinRecord();
            $checkin->uid = $userId;
            $checkin->checkin_date = $today;
            $checkin->points = $points;
            $checkin->create_time = date('Y-m-d H:i:s');
            $checkin->save();
            
            // 更新用户积分
            $user = UserModel::where('id', $userId)->find();
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            $oldBalance = $user['points_balance'] ?? 0;
            $newBalance = $oldBalance + $points;
            
            // 更新用户表积分字段
            $user->points_balance = $newBalance;
            $user->month_earned = ($user->month_earned ?? 0) + $points;
            $user->total_earned = ($user->total_earned ?? 0) + $points;
            $user->save();
            
            // 记录积分变动
            $this->recordPointsChange($userId, $points, '签到', 'earned');
            
            $this->app->db->commit();
            
            return json([
                'code' => 200,
                'msg' => '签到成功',
                'data' => [
                    'points' => $points,
                    'new_balance' => $newBalance,
                    'new_month_earned' => $user->month_earned,
                    'new_total_earned' => $user->total_earned,
                    'continuous_days' => $checkinInfo['continuous_days'] + 1
                ]
            ]);
        } catch (\Exception $e) {
            $this->app->db->rollback();
            Log::error('签到失败: ' . $e->getMessage(), [
                'user_id' => $userId,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return json([
                'code' => 500,
                'msg' => '签到失败，请稍后重试'
            ]);
        }
    }

/**
 * 计算签到获得的积分
 */
private function calculateCheckinPoints($continuousDays)
{
    // 连续签到奖励规则：7天内递增，7天后固定3积分
    $pointsMap = [1, 1, 2, 2, 2, 3, 3];
    return $continuousDays < 7 ? $pointsMap[$continuousDays] : 3;
}

/**
 * 记录积分变动
 */
private function recordPointsChange($userId, $points, $reason, $type = 'earned')
{
    $record = new PointsRecord();
    $record->uid = $userId;
    $record->points = $points;
    $record->reason = $reason;
    $record->type = $type; // earned-获取, used-使用
    $record->create_time = date('Y-m-d H:i:s');
    $record->save();
}

/**
 * 获取积分记录（分页）
 */
public function pointsRecords()
{
    if (empty($this->user_info['id'])) {
        return json(['code' => 401, 'msg' => '请先登录']);
    }
    
    $type = $this->request->get('type', 'earned');
    // 修复参数类型问题：强制转换为整数并限制范围
    $page = max(1, intval($this->request->get('page', 1)));
    $pageSize = max(1, min(100, intval($this->request->get('pageSize', 10))));
    
    // 验证类型
    if (!in_array($type, ['earned', 'used'])) {
        return json(['code' => 400, 'msg' => '无效的记录类型']);
    }
    
    try {
        // 查询积分记录
        $query = PointsRecord::where('uid', $this->user_info['id'])
            ->where('type', $type)
            ->order('create_time', 'desc');
        
        $total = $query->count();
        $records = $query->page($page, $pageSize)->select()->toArray();
        
        // 格式化记录，添加空值处理
        $formattedRecords = array_map(function($item) {
            return [
                'id' => $item['id'] ?? 0,
                'points' => $item['points'] ?? 0,
                'reason' => $item['reason'] ?? '无原因',
                'time' => !empty($item['create_time']) ? date('Y-m-d H:i', strtotime($item['create_time'])) : '',
                'create_time' => $item['create_time'] ?? ''
            ];
        }, $records);
        
        return json([
            'code' => 200,
            'msg' => '获取积分记录成功',
            'data' => [
                'records' => $formattedRecords,
                'total' => $total,
                'totalPages' => ceil($total / $pageSize),
                'currentPage' => $page,
                'pageSize' => $pageSize
            ]
        ]);
    } catch (\Exception $e) {
        Log::error('获取积分记录失败: ' . $e->getMessage(), [
            'user_id' => $this->user_info['id'],
            'type' => $type,
            'page' => $page,
            'pageSize' => $pageSize,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return json([
            'code' => 500,
            'msg' => '获取积分记录失败'
        ]);
    }
}

/**
 * 积分规则页面
 */
public function pointsRule()
{
    // 可以从配置或数据库中获取积分规则内容
    $rules = [
        [
            'title' => '每日签到',
            'description' => '连续签到可获得更多积分，1-2天1积分，3-5天2积分，6-7天3积分',
            'points' => '1-3积分/天'
        ],
        [
            'title' => '邀请好友',
            'description' => '成功邀请新用户注册并完成首次任务',
            'points' => '5积分/人'
        ],
        [
            'title' => '完成每日任务',
            'description' => '完成指定的每日任务',
            'points' => '1-5积分/任务'
        ],
        [
            'title' => '积分使用',
            'description' => '兑换礼品或服务将消耗相应积分',
            'points' => '根据兑换物品而定'
        ]
    ];
    
    View::assign('rules', $rules);
    return View::fetch('points_rule');
}
}
