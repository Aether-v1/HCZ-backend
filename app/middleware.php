<?php
// 全局中间件定义文件
use think\middleware\SessionInit;

return [
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
     SessionInit::class,
    // CORS 跨域处理（必须在 SessionInit 之后，CSRF/Auth 之前；OPTIONS 预检直接返回 204 不进入后续中间件）
     \app\middleware\CorsMiddleware::class,
    // 全局 CSRF 防护（保护 POST/PUT/PATCH/DELETE，排除登录/注册/验证码/支付回调/Webhook；需在 SessionInit 之后读取 Token）
     \app\middleware\CsrfCheck::class,
     \app\middleware\ApiResponseFormat::class
];