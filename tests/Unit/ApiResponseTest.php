<?php
declare(strict_types=1);

namespace tests\Unit;

use app\exception\BusinessException;
use app\support\ApiResponse;
use app\support\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Batch 1: API Response 工具单元测试
 *
 * 覆盖：
 * - success() 输出格式
 * - error() 输出格式
 * - paginate() 输出格式
 * - fromException() 输出格式
 * - toArray() 输出格式
 * - 与现有 show() 格式兼容性
 */
class ApiResponseTest extends TestCase
{
    public function testSuccessDefaultFormat(): void
    {
        $response = ApiResponse::success();
        $data = $response->getData();

        $this->assertSame(200, $data['code']);
        $this->assertSame('success', $data['status']);
        $this->assertSame('success', $data['message']);
        $this->assertNull($data['data']);
        $this->assertTrue($data['success']);
    }

    public function testSuccessWithDataAndMessage(): void
    {
        $payload = ['id' => 1, 'name' => 'test'];
        $response = ApiResponse::success($payload, '获取成功');
        $data = $response->getData();

        $this->assertSame(200, $data['code']);
        $this->assertSame('success', $data['status']);
        $this->assertSame('获取成功', $data['message']);
        $this->assertSame($payload, $data['data']);
        $this->assertTrue($data['success']);
    }

    public function testSuccessCodeIsAlways200(): void
    {
        // 成功响应的 code 固定为 200，与现有系统兼容
        $response = ApiResponse::success(null, 'ok', ErrorCode::SUCCESS);
        $data = $response->getData();
        $this->assertSame(200, $data['code']);
    }

    public function testErrorDefaultFormat(): void
    {
        $response = ApiResponse::error();
        $data = $response->getData();

        $this->assertSame(ErrorCode::BAD_REQUEST, $data['code']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('操作失败', $data['message']);
        $this->assertNull($data['data']);
        $this->assertFalse($data['success']);
    }

    public function testErrorWithCustomMessageAndCode(): void
    {
        $response = ApiResponse::error('余额不足', ErrorCode::INSUFFICIENT_BALANCE);
        $data = $response->getData();

        $this->assertSame(ErrorCode::INSUFFICIENT_BALANCE, $data['code']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('余额不足', $data['message']);
        $this->assertFalse($data['success']);
    }

    public function testErrorHttpStatusDerivedFromErrorCode(): void
    {
        $response = ApiResponse::error('未登录', ErrorCode::UNAUTHENTICATED);
        $this->assertSame(401, $response->getCode());

        $response = ApiResponse::error('无权限', ErrorCode::FORBIDDEN);
        $this->assertSame(403, $response->getCode());

        $response = ApiResponse::error('不存在', ErrorCode::RESOURCE_NOT_FOUND);
        $this->assertSame(404, $response->getCode());
    }

    public function testErrorWithExplicitHttpStatus(): void
    {
        $response = ApiResponse::error('自定义错误', ErrorCode::BUSINESS_ERROR, null, 418);
        $this->assertSame(418, $response->getCode());
    }

    public function testPaginateFormat(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $response = ApiResponse::paginate($items, 1, 20, 100);
        $data = $response->getData();

        $this->assertSame(200, $data['code']);
        $this->assertTrue($data['success']);
        $this->assertSame($items, $data['data']['items']);
        $this->assertSame(1, $data['data']['pagination']['page']);
        $this->assertSame(20, $data['data']['pagination']['page_size']);
        $this->assertSame(100, $data['data']['pagination']['total']);
        $this->assertSame(5, $data['data']['pagination']['total_pages']);
    }

    public function testPaginateTotalPagesCalculation(): void
    {
        $response = ApiResponse::paginate([], 1, 20, 0);
        $data = $response->getData();
        $this->assertSame(0, $data['data']['pagination']['total_pages']);

        $response = ApiResponse::paginate([], 1, 20, 1);
        $data = $response->getData();
        $this->assertSame(1, $data['data']['pagination']['total_pages']);

        $response = ApiResponse::paginate([], 1, 20, 21);
        $data = $response->getData();
        $this->assertSame(2, $data['data']['pagination']['total_pages']);
    }

    public function testFromBusinessException(): void
    {
        $e = new BusinessException('余额不足', ErrorCode::INSUFFICIENT_BALANCE, 422);
        $response = ApiResponse::fromException($e);
        $data = $response->getData();

        $this->assertSame(ErrorCode::INSUFFICIENT_BALANCE, $data['code']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('余额不足', $data['message']);
        $this->assertFalse($data['success']);
        $this->assertSame(422, $response->getCode());
    }

    public function testToArraySuccess(): void
    {
        $array = ApiResponse::toArray(true, '成功', 200, ['key' => 'value']);
        $this->assertSame([
            'code' => 200,
            'status' => 'success',
            'message' => '成功',
            'data' => ['key' => 'value'],
            'success' => true,
        ], $array);
    }

    public function testToArrayError(): void
    {
        $array = ApiResponse::toArray(false, '失败', 40000, null);
        $this->assertSame([
            'code' => 40000,
            'status' => 'error',
            'message' => '失败',
            'data' => null,
            'success' => false,
        ], $array);
    }

    public function testCompatibilityWithLegacyShowFormat(): void
    {
        // 验证新工具输出格式与现有 show() 函数完全一致
        // show(200, 'success', '测试消息', ['a' => 1]) 输出：
        // {code:200, status:'success', message:'测试消息', data:{a:1}, success:true}
        $response = ApiResponse::success(['a' => 1], '测试消息');
        $data = $response->getData();

        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('success', $data);
        $this->assertCount(5, $data, '响应应恰好包含 5 个字段，与现有 show() 一致');
    }
}
