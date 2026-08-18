<?php
namespace app\exception;

use think\exception\Handle as BaseHandle;
use think\exception\ValidateException;
use think\Response;
use think\facade\Request;

class Handle extends BaseHandle
{
    /**
     * 重写异常处理方法
     * 主要用于自定义CSRF令牌验证失败的提示
     */
    public function render($request, \Throwable $e): Response
    {
        // 处理CSRF令牌验证失败的异常
        if ($e instanceof ValidateException && $e->getMessage() === 'CSRF令牌验证失败') {
            // 区分AJAX请求和普通表单请求
            if (Request::isAjax()) {
                // AJAX请求返回JSON格式错误
                return json([
                    'code' => 400,
                    'status' => 'error',
                    'message'  => '请求已过期，请刷新页面重试',
                    'data' => null,
                    'success' => false,
                ], 400);
            } else {
                // 普通表单请求跳转回上一页，并显示错误提示
                return redirect()->back()->with('error', '请求已过期，请刷新页面重试');
            }
        }

        // 其他异常保持框架默认处理（如404、500等）
        return parent::render($request, $e);
    }
}