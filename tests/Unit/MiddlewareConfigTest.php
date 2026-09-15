<?php
declare(strict_types=1);

namespace tests\Unit;

use app\middleware\AdminAuth;
use app\middleware\CorsMiddleware;
use app\middleware\CsrfCheck;
use app\middleware\LegacyUserFrontendDisabled;
use app\middleware\RequestIdMiddleware;
use app\middleware\UserAuth;
use PHPUnit\Framework\TestCase;
use think\App;

/**
 * Batch 2-A: 中间件架构配置验证测试
 *
 * 验证：
 * - RequestIdMiddleware 注册为全局第一个中间件
 * - CsrfCheck 是唯一的 CSRF 机制（内置 Csrf 已移除）
 * - UserAuth 不再包含 Telegram 业务方法
 * - AdminAuth 白名单正确
 * - 中间件链顺序正确
 */
class MiddlewareConfigTest extends TestCase
{
    private function getMiddlewareConfig(): array
    {
        $app = new App(dirname(__DIR__, 2));
        $app->initialize();
        return $app->config->get('middleware');
    }

    public function testRequestIdMiddlewareIsFirstInGlobalChain(): void
    {
        $config = $this->getMiddlewareConfig();
        $globalMiddleware = $config['middleware'] ?? [];

        $this->assertNotEmpty($globalMiddleware, '全局中间件不应为空');

        $first = $globalMiddleware[0];
        $firstClass = is_array($first) ? ($first[0] ?? '') : $first;

        $this->assertSame(
            RequestIdMiddleware::class,
            $firstClass,
            'RequestIdMiddleware 应为全局第一个中间件'
        );
    }

    public function testRequestIdMiddlewareIsFirstInPriority(): void
    {
        $config = $this->getMiddlewareConfig();
        $priority = $config['priority'] ?? [];

        $this->assertNotEmpty($priority, '优先级列表不应为空');
        $this->assertSame(
            RequestIdMiddleware::class,
            $priority[0],
            'RequestIdMiddleware 应为优先级第一个'
        );
    }

    public function testNoBuiltinCsrfInGlobalChain(): void
    {
        $config = $this->getMiddlewareConfig();
        $globalMiddleware = $config['middleware'] ?? [];

        $hasBuiltinCsrf = false;
        foreach ($globalMiddleware as $m) {
            $class = is_array($m) ? ($m[0] ?? '') : $m;
            if ($class === 'think\\middleware\\Csrf' || $class === \think\middleware\Csrf::class) {
                $hasBuiltinCsrf = true;
                break;
            }
        }

        $this->assertFalse($hasBuiltinCsrf, '全局中间件不应包含不存在的 think\\middleware\\Csrf');
    }

    public function testNoBuiltinCsrfInPriority(): void
    {
        $config = $this->getMiddlewareConfig();
        $priority = $config['priority'] ?? [];

        $hasBuiltinCsrf = in_array('think\\middleware\\Csrf', $priority, true)
            || in_array(\think\middleware\Csrf::class, $priority, true);

        $this->assertFalse($hasBuiltinCsrf, '优先级列表不应包含不存在的 think\\middleware\\Csrf');
    }

    public function testCsrfCheckIsPresent(): void
    {
        $config = $this->getMiddlewareConfig();
        $globalMiddleware = $config['middleware'] ?? [];

        $hasCsrfCheck = false;
        foreach ($globalMiddleware as $m) {
            $class = is_array($m) ? ($m[0] ?? '') : $m;
            if ($class === CsrfCheck::class) {
                $hasCsrfCheck = true;
                break;
            }
        }

        $this->assertTrue($hasCsrfCheck, '全局中间件应包含自定义 CsrfCheck');
    }

    public function testMiddlewareChainOrder(): void
    {
        $config = $this->getMiddlewareConfig();
        $globalMiddleware = $config['middleware'] ?? [];

        $classes = array_map(function ($m) {
            return is_array($m) ? ($m[0] ?? '') : $m;
        }, $globalMiddleware);

        // 期望顺序：RequestId → SessionInit → Cors → Legacy → CsrfCheck
        $expectedOrder = [
            RequestIdMiddleware::class,
            'think\\middleware\\SessionInit',
            CorsMiddleware::class,
            LegacyUserFrontendDisabled::class,
            CsrfCheck::class,
        ];

        foreach ($expectedOrder as $i => $expectedClass) {
            $this->assertSame(
                $expectedClass,
                $classes[$i] ?? null,
                "全局中间件第 " . ($i + 1) . " 个应为 {$expectedClass}"
            );
        }
    }

    public function testUserAuthHasNoTelegramBusinessMethod(): void
    {
        $reflection = new \ReflectionClass(UserAuth::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(fn($m) => $m->getName(), $methods);

        $this->assertNotContains(
            'generateTgBindCode',
            $methodNames,
            'UserAuth 不应包含 generateTgBindCode 业务方法'
        );
    }

    public function testUserAuthOnlyHasAuthenticationMethods(): void
    {
        $reflection = new \ReflectionClass(UserAuth::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(fn($m) => $m->getName(), $methods);

        // UserAuth 应只有 handle 方法（__construct 不算）
        $publicMethods = array_diff($methodNames, ['__construct']);
        $this->assertSame(
            ['handle'],
            array_values($publicMethods),
            'UserAuth 应只有 handle 一个公开方法'
        );
    }

    public function testAdminAuthWhitelistIsCorrect(): void
    {
        $reflection = new \ReflectionClass(AdminAuth::class);
        $method = $reflection->getMethod('handle');
        $source = file_get_contents($reflection->getFileName());

        // 验证白名单包含 login 和 login_check
        $this->assertStringContainsString("'login'", $source, 'AdminAuth 白名单应包含 login');
        $this->assertStringContainsString("'login_check'", $source, 'AdminAuth 白名单应包含 login_check');

        // 验证白名单不包含 password（死代码已移除）
        $this->assertStringNotContainsString("'password'", $source, 'AdminAuth 白名单不应包含 password 死代码');
    }

    public function testBuiltinCsrfClassDoesNotExist(): void
    {
        // 验证 think\middleware\Csrf 确实不存在，解释为什么要从配置中移除
        $this->assertFalse(
            class_exists('think\\middleware\\Csrf'),
            'think\\middleware\\Csrf 类不存在，不应在配置中引用'
        );
    }

    public function testAliasDoesNotContainBuiltinCsrf(): void
    {
        $config = $this->getMiddlewareConfig();
        $alias = $config['alias'] ?? [];

        $this->assertArrayNotHasKey('csrf', $alias, '别名不应包含不存在的 csrf 别名');
        $this->assertArrayHasKey('user_csrf', $alias, '别名应包含 user_csrf 别名');
    }
}
