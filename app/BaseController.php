<?php
declare (strict_types = 1);

namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\facade\Log;
use think\facade\Session;
use app\service\AuthorizationService;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected \think\Request $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected App $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected array $middleware = [];

    /**
     * 当前登录管理员身份缓存（惰性，首次调用 currentAdminIdentity() 时读取；非 Admin 场景不触发）
     * @var array|null
     */
    protected ?array $adminIdentity = null;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

    /**
     * 当前登录管理员身份（惰性读取，不强制 Session；非 Admin 场景不触发）
     * @return array
     */
    protected function currentAdminIdentity(): array
    {
        if ($this->adminIdentity === null) {
            $this->adminIdentity = (array)$this->request->session('admin', []);
        }
        return $this->adminIdentity;
    }

    protected function directCsrfTokenName(): string
    {
        $csrfConfig = (array)config('app.csrf');

        return (string)($csrfConfig['token_name'] ?? '_csrf_token');
    }

    protected function directCurrentRequestPath(): string
    {
        return trim(str_replace('\\', '/', (string)$this->request->pathinfo()), '/');
    }

    protected function directRequestPathMatches(string $relativePath): bool
    {
        $currentPath = strtolower($this->directCurrentRequestPath());
        $relativePath = strtolower(trim(str_replace('\\', '/', $relativePath), '/'));
        if ($relativePath === '') {
            return false;
        }

        return $currentPath === $relativePath
            || str_ends_with($currentPath, '/' . $relativePath)
            || str_contains($currentPath, '/' . $relativePath . '/');
    }

    protected function directValidateOptionalCsrfToken(): bool
    {
        $tokenName = $this->directCsrfTokenName();
        $requestToken = trim((string)$this->request->post($tokenName, $this->request->post('__token__', '')));
        if ($requestToken === '') {
            $requestToken = trim((string)$this->request->header('X-CSRF-Token', ''));
        }
        if ($requestToken === '') {
            return true;
        }

        $sessionToken = (string)Session::get($tokenName, '');

        return $sessionToken !== '' && hash_equals($sessionToken, $requestToken);
    }

    protected function directValidateRequiredCsrfToken(): bool
    {
        $tokenName = $this->directCsrfTokenName();
        $requestToken = trim((string)$this->request->post($tokenName, $this->request->post('__token__', '')));
        if ($requestToken === '') {
            $requestToken = trim((string)$this->request->header('X-CSRF-Token', ''));
        }
        if ($requestToken === '') {
            return false;
        }

        $sessionToken = (string)Session::get($tokenName, '');

        return $sessionToken !== '' && hash_equals($sessionToken, $requestToken);
    }

    protected function authorize(string $permission): bool
    {
        $adminId = (int)($this->currentAdminIdentity()['id'] ?? 0);
        if ($adminId <= 0) {
            return false;
        }
        try {
            return (new AuthorizationService())->can($adminId, $permission);
        } catch (\Throwable $e) {
            Log::error('AdminApi authorize failed', [
                'admin_id' => $adminId,
                'permission' => $permission,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    protected function directHasAdminPermission(string $permission): bool
    {
        // Batch 2-D3-A: 兼容层 — 旧中文权限 → RBAC 映射（仅用于尚未迁移的 D3-F 资金接口）
        $map = [
            '用户列表' => 'admin.user.view',
            '支付管理' => 'admin.payment.manage',
            '充值业务 - 产品列表' => 'admin.product.recharge.view',
            '查询业务 - 产品列表' => 'admin.product.query.view',
            '充值业务 - 订单列表' => 'admin.order.recharge.view',
            '查询业务 - 订单列表' => 'admin.order.query.view',
            '交易挂单数据' => 'admin.transaction.pending.view',
            '交易订单数据' => 'admin.transaction.order.view',
            '充值订单记录' => 'admin.recharge.view',
            '提现订单记录' => 'admin.withdrawal.view',
            '返佣记录' => 'admin.rebate.view',
            '首页轮播图' => 'admin.banner.manage',
            '积分管理' => 'admin.points.manage',
            '管理员列表' => 'admin.admin.view',
            '操作记录' => 'admin.log.view',
            '系统设置管理' => 'admin.setting.manage',
        ];
        $rbacCode = $map[trim($permission)] ?? null;
        if ($rbacCode === null) {
            Log::warning('AdminApi legacy permission not mapped', ['permission' => $permission]);
            return false;
        }
        return $this->authorize($rbacCode);
    }

    protected function directDenyAdminPermission(string $permission)
    {
        Log::warning('admin permission denied', [
            'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
            'permission' => $permission,
            'ip' => (string)$this->request->ip(),
            'path' => $this->directCurrentRequestPath(),
        ]);

        return show(403, 'error', '权限不足');
    }
}
