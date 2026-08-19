<?php
use think\facade\Route;

// Telegram机器人Webhook路由
Route::post('robot/webhook', 'TelegramWebhook/index');

// 积分API分组
Route::group('api/points', function() {
    // 获取积分基本信息
    Route::get('info', 'IndexApi/api_points_info');
    // 签到接口
    Route::post('checkin', 'IndexApi/api_points_checkin');
    Route::get('tasks', 'IndexApi/api_points_tasks');
    Route::post('task/claim', 'IndexApi/api_points_task_claim');
    // 积分兑换配置
    Route::get('exchange/items', 'IndexApi/api_points_exchange_items');
    // 积分兑换提交
    Route::post('exchange/submit', 'IndexApi/api_points_exchange_submit');
    // 积分记录查询（支持分页和类型筛选）
    Route::get('records', 'IndexApi/api_points_records');
});

// 站点API分组
Route::group('api/site', function() {
    Route::get('config', 'IndexApi/api_site_config');
});

// 分站站点API
Route::group('api/site/substation', function() {
    Route::get('context', 'SiteSubstationApi/context');
    Route::get('profile', 'SiteSubstationApi/profile');
    Route::get('product-price', 'SiteSubstationApi/productPrice');
});

Route::get('api/home/bootstrap', 'IndexApi/api_home_bootstrap');

// 用户API分组
Route::group('api/user', function() {
    Route::get('bootstrap', 'IndexApi/api_user_bootstrap');
    Route::get('messages', 'IndexApi/api_user_messages');
    Route::get('messages/detail', 'IndexApi/api_user_message_detail');
    Route::post('messages/read', 'IndexApi/api_user_message_read');
    Route::post('messages/read-all', 'IndexApi/api_user_message_read_all');
    Route::post('messages/delete', 'IndexApi/api_user_message_delete');
    Route::get('messages/unread-count', 'IndexApi/api_user_message_unread_count');
});

// 授权API分组
Route::group('api/auth', function() {
    Route::get('csrf', 'IndexApi/api_auth_csrf');
    Route::post('login', 'IndexApi/api_auth_login');
    Route::post('twofa/recover', 'IndexApi/api_auth_twofa_recover');
    Route::post('register', 'IndexApi/api_auth_register');
    Route::post('logout', 'IndexApi/api_auth_logout');
    Route::post('check-password', 'IndexApi/api_auth_check_password');
});

// 产品API分组
Route::group('api/product', function() {
    Route::post('confirm-recharge', 'IndexApi/api_product_confirm_recharge');
    Route::post('confirm-payment', 'IndexApi/api_product_confirm_payment');
    Route::post('discount', 'IndexApi/api_product_discount');
    Route::post('query-submit', 'IndexApi/api_product_query_submit');
    Route::post('query-payment', 'IndexApi/api_product_query_payment');
});

// 上传API分组
Route::group('api/upload', function() {
    Route::post('image', 'IndexApi/api_upload_image');
});

Route::group('api/proof', function() {
    Route::get('recharge/<order_number>/view', 'IndexApi/api_proof_recharge_view');
    Route::get('trade/<order_number>/view', 'IndexApi/api_proof_trade_view');
});

// 分站用户API分组
Route::group('api/substation', function() {
    Route::post('open-pay', 'SubstationApi/openPay');
    Route::post('apply', 'SubstationApi/apply');
    Route::get('my/status', 'SubstationApi/myStatus');
    Route::get('my/profile', 'SubstationApi/myProfile');
    Route::get('my/product-catalog', 'SubstationApi/productCatalog');
    Route::post('my/profile-audit', 'SubstationApi/submitProfileAudit');
    Route::get('my/product-tier-list', 'SubstationApi/productTierList');
    Route::post('my/product-tier-save', 'SubstationApi/saveProductTierPrice');
    Route::get('my/income-log', 'SubstationApi/incomeLog');
    Route::post('my/wallet-transfer', 'SubstationApi/walletTransfer');
});

// 邀请API分组
Route::group('api/invite', function() {
    Route::get('info', 'IndexApi/api_invite_info');
});

// 代理API分组
Route::group('api/agent', function() {
    Route::get('summary', 'IndexApi/api_agent_summary');
    Route::get('users', 'IndexApi/api_agent_users');
    Route::post('activate', 'IndexApi/api_agent_activate');
    Route::post('wallet-transfer', 'IndexApi/api_agent_wallet_transfer');
});

// 订单API
Route::get('api/order/list', 'IndexApi/api_order_list');
Route::post('api/order/query', 'IndexApi/api_order_query');
Route::get('api/order/detail/<order_number>', 'IndexApi/api_order_detail');
Route::post('api/order/cancel', 'IndexApi/api_order_cancel');
Route::post('api/order/delete', 'IndexApi/api_order_delete');
Route::post('api/order/confirm-receipt', 'IndexApi/api_order_confirm_receipt');

// 产品API
Route::get('api/product/detail/<id>', 'IndexApi/api_product_detail');

// 财务API
Route::get('api/finance/wallet-details', 'IndexApi/api_finance_wallet_details');
Route::post('api/finance/wallet-details', 'IndexApi/api_finance_wallet_details');

// 手机号归属地API
Route::get('api/phone/meta', 'IndexApi/api_phone_meta');
Route::post('api/phone/meta', 'IndexApi/api_phone_meta');

// 地区树形结构API
Route::get('api/region/tree', 'IndexApi/api_region_tree');

// 交易API分组
Route::group('api/transaction', function() {
    Route::get('market', 'IndexApi/api_transaction_market');
    Route::get('orders', 'IndexApi/api_transaction_orders');
    Route::get('my-sale', 'IndexApi/api_transaction_my_sale');
    Route::get('order-detail/<order_number>', 'IndexApi/api_transaction_order_detail');
    Route::post('buy', 'IndexApi/api_transaction_buy');
    Route::post('order-proof-image', 'IndexApi/api_transaction_order_proof_image');
    Route::post('order-proof-submit', 'IndexApi/api_transaction_order_proof_submit');
    Route::post('order-cancel', 'IndexApi/api_transaction_order_cancel');
    Route::post('order-release', 'IndexApi/api_transaction_order_release');
    Route::post('sale-submit', 'IndexApi/api_transaction_sale_submit');
    Route::post('sale-status', 'IndexApi/api_transaction_sale_status');
});

// 账户API分组
Route::group('api/account', function() {
    Route::get('profile', 'IndexApi/api_account_profile');
    Route::get('settings', 'IndexApi/api_account_settings');
    Route::get('twofa/status', 'IndexApi/api_account_twofa_status');
    Route::post('twofa/init', 'IndexApi/api_account_twofa_init');
    Route::post('twofa/verify', 'IndexApi/api_account_twofa_verify');
    Route::post('twofa/disable', 'IndexApi/api_account_twofa_disable');
    Route::post('twofa/reset', 'IndexApi/api_account_twofa_reset');
    Route::post('twofa/recover', 'IndexApi/api_account_twofa_recover');
    Route::post('twofa/recovery-codes/regenerate', 'IndexApi/api_account_twofa_recovery_codes_regenerate');
    Route::get('telegram-binding-status', 'IndexApi/api_account_telegram_binding_status');
    Route::post('profile-save', 'IndexApi/api_account_profile_save');
    Route::post('password-save', 'IndexApi/api_account_password_save');
    Route::post('telegram-binding-code', 'IndexApi/api_account_telegram_binding_code');
    Route::post('telegram-unbind', 'IndexApi/api_account_telegram_unbind');
    Route::post('wallet-address-save', 'IndexApi/api_account_wallet_address_save');
    Route::get('wallet-address', 'IndexApi/api_account_wallet_address');
    Route::get('bank-card', 'IndexApi/api_account_bank_card');
    Route::post('bank-card-save', 'IndexApi/api_account_bank_card_save');
    Route::post('bank-card-delete', 'IndexApi/api_account_bank_card_delete');
    Route::post('bank-card-default', 'IndexApi/api_account_bank_card_default');
});

// 财务API分组
Route::group('api/finance', function() {
    Route::get('summary', 'IndexApi/api_finance_summary');
    Route::get('orders', 'IndexApi/api_finance_orders');
    Route::get('detail-summary', 'IndexApi/api_finance_detail_summary');
    Route::get('detail-records', 'IndexApi/api_finance_detail_records');
    Route::post('recharge', 'IndexApi/api_finance_recharge');
    Route::get('recharge-detail', 'IndexApi/api_finance_recharge_detail');
    Route::post('recharge-submit', 'IndexApi/api_finance_recharge_submit');
    Route::post('withdrawal', 'IndexApi/api_finance_withdrawal');
    Route::get('withdrawal-preview', 'IndexApi/api_finance_withdrawal_preview');
    Route::post('withdrawal-submit', 'IndexApi/api_finance_withdrawal_submit');
    Route::get('withdrawal-detail', 'IndexApi/api_finance_withdrawal_detail');
});

// 易支付交易回调
Route::get('epay_notify_url', 'IndexApi/epay_notify_url');
Route::post('epay_notify_url', 'IndexApi/epay_notify_url');

// 回调API分组
Route::group('api/callback', function () {
    Route::post('bepusdt', 'Notify/api_callback_bepusdt');
});

// 后台管理路由分组（动态后台入口）
Route::group(getConfig('backstage_entrance'), static function () {
    // 后台首页
    Route::get('/', 'Admin/index');
    // 后台登录
    Route::get('login', 'Admin/login');
    // 后台登录验证
    Route::post('login_check', 'AdminApi/login_check');
    // 防被动登出：后台退出改为 POST，避免第三方页面通过 GET 链接或图片触发退出。
    Route::post('logout', 'AdminApi/logout');
    // 系统设置
    Route::get('setting', 'Admin/setting');
    // 系统设置提交
    Route::post('setting_post/:action', 'AdminApi/setting_post');
    // 管理员基本资料
    Route::get('account', 'Admin/account');
    // 管理员基本资料请求
    Route::post('account_post/:action', 'AdminApi/account_post');
    // 管理员2FA请求
    Route::post('twofa_post/:action', 'AdminApi/twofa_post');
    // 私有导出下载接口
    Route::get('export_download', 'AdminApi/export_download');

    // 用户管理页面
    Route::get('user', 'Admin/user');
    // 用户管理页面请求
    Route::post('user_post/:action', 'AdminApi/user_post');
    // 用户管理列表Ajax分页请求
    Route::get('user_list', 'AdminList/user_list');
    // 站内消息页面
    Route::get('message', 'Admin/message');
    // 站内消息发送
    Route::post('message/send', 'AdminApi/message_send');
    // 站内消息列表
    Route::get('message/list', 'AdminList/message_list');
    // 站内消息详情
    Route::get('message/detail', 'AdminApi/message_detail');
    // 站内消息置顶
    Route::post('message/pin', 'AdminApi/message_pin');
    // 站内消息删除
    Route::post('message/delete', 'AdminApi/message_delete');

    // 产品充值管理页面
    Route::get('product_cz', 'Admin/product_cz');
    // 产品查询页面
    Route::get('product_cx', 'Admin/product_cx');
    // 产品页面请求
    Route::post('product_post/:action', 'AdminApi/product_post');
    // 产品列表Ajax分页请求
    Route::get('product_list', 'AdminList/product_list');

    // 充值业务-订单管理页面
    Route::get('order_cz', 'Admin/order_cz');
    // 订单管理列表Ajax分页请求
    Route::get('order_cz_list', 'AdminList/order_cz_list');
    // 查询业务-订单管理页面
    Route::get('order_cx', 'Admin/order_cx');
    // 订单管理列表Ajax分页请求
    Route::get('order_cx_list', 'AdminList/order_cx_list');
    // 订单管理页面请求
    Route::post('order_post/:action', 'AdminApi/order_post');

    // 轮播图管理页面
    Route::get('slide', 'Admin/slide');
    // 轮播图管理页面请求
    Route::post('slide_post/:action', 'AdminApi/slide_post');
    // 轮播图管理列表Ajax分页请求
    Route::get('slide_list', 'AdminList/slide_list');

    // 交易挂单数据
    Route::get('transaction_product', 'Admin/transaction_product');
    // 交易挂单数据请求
    Route::post('transaction_product_post/:action', 'AdminApi/transaction_product_post');
    // 交易挂单列表Ajax分页请求
    Route::get('transaction_product_list', 'AdminList/transaction_product_list');

    // 交易订单数据
    Route::get('transaction_order', 'Admin/transaction_order');
    // 交易订单数据请求
    Route::post('transaction_order_post/:action', 'AdminApi/transaction_order_post');
    // 交易订单列表Ajax分页请求
    Route::get('transaction_order_list', 'AdminList/transaction_order_list');

    // 积分管理页面
    Route::get('points_management', 'Admin/points_management');
    Route::get('points_config', 'Admin/points_config');
    Route::get('points_tasks', 'Admin/points_tasks');
    Route::get('points_records', 'Admin/points_records');
    Route::get('points_exchange', 'Admin/points_exchange');
    // 积分兑换订单管理
    Route::get('points_exchange_orders', 'Admin/points_exchange_orders');
    Route::get('points_exchange_orders_json', 'AdminApi/points_exchange_orders_json');
    Route::post('points_exchange_order_post/:action', 'AdminApi/points_exchange_order_post');
    // 积分管理操作
    Route::post('points_management_post/:action', 'AdminApi/points_management_post');
    Route::post('points_post/:action', 'AdminApi/points_post');
    Route::get('points_records_json', 'AdminApi/points_records_json');
    // 积分管理列表
    Route::get('points_management_list', 'AdminList/points_management_list');

    // 分站中心页面
    Route::get('substation_apply', 'Admin/substation_apply');
    Route::get('substation_profile_audit', 'Admin/substation_profile_audit');
    Route::get('substation_manage', 'Admin/substation_manage');
    Route::get('substation_order', 'Admin/substation_order');
    Route::get('substation_income', 'Admin/substation_income');
    // 分站中心API
    Route::get('substation/apply-list', 'SubstationAdminApi/applyList');
    Route::get('substation/profile-audit-list', 'SubstationAdminApi/profileAuditList');
    Route::post('substation/audit', 'SubstationAdminApi/audit');
    Route::post('substation/base-domain', 'SubstationAdminApi/saveBaseDomain');
    Route::get('substation/list', 'SubstationAdminApi/list');
    Route::post('substation/manage-action', 'SubstationAdminApi/manageAction');
    Route::get('substation/orders', 'SubstationAdminApi/orders');
    Route::get('substation/income-log', 'SubstationAdminApi/incomeLog');

    // 支付管理请求
    Route::post('bank_card_post/:action', 'AdminApi/bank_card_post');
    // 支付管理
    Route::get('bank_card', 'Admin/bank_card');
    // 支付管理列表Ajax分页请求
    Route::get('bank_card_list', 'AdminList/bank_card_list');

    // 下级用户
    Route::get('user_t', 'Admin/user_t');
    // 下级用户列表Ajax分页请求
    Route::get('user_t_list', 'AdminList/user_t_list');

    // 返利记录请求
    Route::post('rebate_record_post/:action', 'AdminApi/rebate_record_post');
    // 返利记录
    Route::get('rebate_record', 'Admin/rebate_record');
    // 返利记录列表Ajax分页请求
    Route::get('rebate_record_list', 'AdminList/rebate_record_list');

    // 充值订单管理页面
    Route::get('recharge', 'Admin/recharge');
    // 充值订单管理请求
    Route::post('recharge_post/:action', 'AdminApi/recharge_post');
    // 充值订单列表Ajax分页请求
    Route::get('recharge_list', 'AdminList/recharge_list');

    // 提现订单管理页面
    Route::get('withdrawal', 'Admin/withdrawal');
    // 提现订单管理请求
    Route::post('withdrawal_post/:action', 'AdminApi/withdrawal_post');
    // 提现订单列表Ajax分页请求
    Route::get('withdrawal_list', 'AdminList/withdrawal_list');

    // 管理员
    Route::get('admin', 'Admin/admin');
    // 管理员列表Ajax分页请求
    Route::get('admin_list', 'AdminList/admin_list');
    // 管理员请求
    Route::post('admin_post/:action', 'AdminApi/admin_post');

    // 操作记录
    Route::get('operation_log', 'Admin/operation_log');
    // 操作记录列表Ajax分页请求
    Route::get('operation_log_list', 'AdminList/operation_log_list');

    // 后台全局请求
    Route::post('admin_footer/:action', 'AdminApi/admin_footer');
    // 图片上传
    Route::post('upload_post', 'AdminApi/upload_post');
});
