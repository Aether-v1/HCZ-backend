<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\V1Context;
use PHPUnit\Framework\TestCase;
use think\Request;

/**
 * R1.4 - V1 Context Detection Contract Tests
 *
 * 覆盖：
 * - V1Context::isV1Path() 精确边界
 * - V1Context::isV1Request() 基于 Request 对象
 * - 必须排除 /api/v10, /api/v1foo, /api/callback, epay_notify_url
 * - 禁止使用无 boundary 的 str_starts_with($path, 'api/v1')
 */
class V1ContextDetectionTest extends TestCase
{
    /**
     * @dataProvider v1PathProvider
     */
    public function testV1PathExactBoundary(string $path, bool $expected): void
    {
        $this->assertSame($expected, V1Context::isV1Path($path));
    }

    public static function v1PathProvider(): array
    {
        return [
            // TRUE: exact V1 boundary
            'api/v1'              => ['api/v1', true],
            'api/v1/orders'       => ['api/v1/orders', true],
            'api/v1/recharge'     => ['api/v1/recharge', true],
            'api/v1/withdraw'     => ['api/v1/withdraw', true],
            'api/v1/auth/login'   => ['api/v1/auth/login', true],
            'api/v1/'             => ['api/v1/', true],

            // FALSE: must NOT capture /api/v10 or /api/v1foo
            'api/v10'             => ['api/v10', false],
            'api/v10/orders'      => ['api/v10/orders', false],
            'api/v1foo'           => ['api/v1foo', false],
            'api/v1foo/bar'       => ['api/v1foo/bar', false],
            'api/v1-anything'     => ['api/v1-anything', false],

            // FALSE: legacy API and other paths
            'api/orders'          => ['api/orders', false],
            'api/user/bootstrap'  => ['api/user/bootstrap', false],
            'api/auth/login'      => ['api/auth/login', false],
            'api/callback/bepusdt'=> ['api/callback/bepusdt', false],
            'epay_notify_url'     => ['epay_notify_url', false],
            'admin/recovery/list' => ['admin/recovery/list', false],
            ''                    => ['', false],
        ];
    }

    public function testIsV1RequestUsesPathinfo(): void
    {
        $request = new Request();
        $request->setPathinfo('api/v1/orders');
        $this->assertTrue(V1Context::isV1Request($request));
    }

    public function testIsV1RequestRejectsV10(): void
    {
        $request = new Request();
        $request->setPathinfo('api/v10/orders');
        $this->assertFalse(V1Context::isV1Request($request));
    }

    public function testIsV1RequestRejectsLegacyApi(): void
    {
        $request = new Request();
        $request->setPathinfo('api/orders');
        $this->assertFalse(V1Context::isV1Request($request));
    }

    public function testIsV1RequestRejectsPaymentCallback(): void
    {
        $request = new Request();
        $request->setPathinfo('api/callback/bepusdt');
        $this->assertFalse(V1Context::isV1Request($request));

        $request2 = new Request();
        $request2->setPathinfo('epay_notify_url');
        $this->assertFalse(V1Context::isV1Request($request2));
    }
}
