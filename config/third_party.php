<?php
// +----------------------------------------------------------------------
// | 第三方生活服务供应商配置（F4/F5/F6）
// +----------------------------------------------------------------------
// | 安全要求：
// |   1. 所有供应商 Key / Secret / ID 一律从 .env 读取，禁止硬编码在 PHP 源码。
// |   2. 本文件只保留占位/空默认值，真实密钥由运营方在 .env 中配置。
// |   3. 不在任何日志、SQL、测试代码、文档中写入真实密钥。
// |   4. 历史版本曾把真实密钥提交进 Git（见 initial commit），
// |      代码移除 ≠ 历史泄露解决，真实密钥必须由运营方在供应商侧轮换。
// +----------------------------------------------------------------------

return [
    // xiaoyun.top 号码归属地查询（getTelecomOperator）
    'xiaoyun' => [
        'url' => env('XIAOYUN_API_URL', 'https://ap.xiaoyun.top/api/xy/xhzw'),
        'key' => env('XIAOYUN_API_KEY', ''),
    ],

    // gfggf.cn 号码余额查询（phone_yue_bak，当前无调用方=死代码）
    // F5：当前默认端点为 http://（明文）。HTTPS 端点可用性需向服务商确认后，
    //     通过 GFGGF_API_URL 覆盖；未确认前不得猜测/伪造 https 端点。
    'gfggf' => [
        'url' => env('GFGGF_API_URL', 'http://api.gfggf.cn/api/gateway'),
        'id'  => env('GFGGF_API_ID', ''),
        'key' => env('GFGGF_API_KEY', ''),
    ],

    // taolale.com 号码余额查询（phone_yue）
    // F6：当前签名 secret 为空（sign = md5(query . secret)），签名可被伪造。
    //     供应商正式签名规范 / 真实 Secret 需向服务商确认；未确认前不自行发明算法。
    //     若供应商提供真实签名密钥，配置 TAOLALE_SIGN_SECRET 后即参与签名。
    'taolale' => [
        'url' => env('TAOLALE_API_URL', 'https://api.taolale.com/api/Inquiry_Phone_Charges/get'),
        'key' => env('TAOLALE_API_KEY', ''),
        'sign_secret' => env('TAOLALE_SIGN_SECRET', ''),
    ],
];
