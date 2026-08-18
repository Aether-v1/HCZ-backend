<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        if ($request->isAjax() || $request->isJson() || str_starts_with($request->pathinfo(), "api/")) {
            $httpCode = 500;
            if ($e instanceof ValidateException) {
                $httpCode = 422;
            } elseif ($e instanceof HttpException) {
                $httpCode = $e->getStatusCode();
            }

            return json([
                "code" => $httpCode,
                "status" => "error",
                "message" => $e->getMessage() ?: "系统异常，请稍后重试",
                "data" => null,
                "success" => false,
            ], $httpCode);
        }

        return parent::render($request, $e);
    }
}
