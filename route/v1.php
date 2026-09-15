<?php
// R1.4: /api/v1 Route Registration Extension Point
//
// 本文件是 ThinkPHP 自动加载的 route/v1.php（Http::loadRoutes() 扫描 route/*.php）。
// 当前仅注册空 route group 作为未来 R3 业务路由的扩展入口。
//
// 严格约束（R1.4）：
// - 本阶段不得新增任何真实业务路由
// - 禁止 /api/v1/orders, /api/v1/recharge, /api/v1/withdraw, /api/v1/auth/*
// - 禁止 fake health endpoint / test route / placeholder business endpoint
// - 未来 R3 业务接入时在此 group 内注册路由
//
// 未来 middleware 栈（R2/R3 逐步加入）：
//   RequestId → CORS → [future Bearer Auth] → [future Idempotency] → Controller
//
// V1 响应格式由 V1ApiResponse 直接输出，ApiResponseFormat 已对 /api/v1 排除。
// V1 异常由 ExceptionHandle::renderV1() 输出 V1 envelope。

use think\facade\Route;

Route::group('api/v1', function () {
    // R1.4: 空 route group — 仅作为扩展入口
    // 未来 R3 在此注册真实业务路由，例如：
    //   Route::post('orders', 'v1.Order/create');
    //   Route::get('orders', 'v1.Order/list');
    //   Route::post('withdraw', 'v1.Finance/withdraw');
});
