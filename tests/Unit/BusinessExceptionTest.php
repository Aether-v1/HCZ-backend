<?php
declare(strict_types=1);

namespace tests\Unit;

use app\exception\AuthenticationException;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\support\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Batch 1: 业务异常体系单元测试
 *
 * 覆盖：
 * - BusinessException 基础属性
 * - 各子类异常的默认 code/httpStatus
 * - 安全事件标记
 * - 上下文数据
 * - 便捷构造方法
 */
class BusinessExceptionTest extends TestCase
{
    public function testBusinessExceptionDefaultValues(): void
    {
        $e = new BusinessException();
        $this->assertSame('业务处理失败', $e->getMessage());
        $this->assertSame(ErrorCode::BUSINESS_ERROR, $e->getErrorCode());
        $this->assertSame(400, $e->getHttpStatus());
        $this->assertNull($e->getContext());
        $this->assertFalse($e->isSecurityEvent());
    }

    public function testBusinessExceptionWithCustomValues(): void
    {
        $context = ['order_id' => 123, 'amount' => 100.00];
        $e = new BusinessException(
            '余额不足',
            ErrorCode::INSUFFICIENT_BALANCE,
            422,
            $context,
            false
        );
        $this->assertSame('余额不足', $e->getMessage());
        $this->assertSame(ErrorCode::INSUFFICIENT_BALANCE, $e->getErrorCode());
        $this->assertSame(422, $e->getHttpStatus());
        $this->assertSame($context, $e->getContext());
        $this->assertFalse($e->isSecurityEvent());
    }

    public function testBusinessExceptionWithContext(): void
    {
        $context = ['user_id' => 456];
        $e = BusinessException::withContext('操作失败', ErrorCode::BAD_REQUEST, $context);
        $this->assertSame('操作失败', $e->getMessage());
        $this->assertSame(ErrorCode::BAD_REQUEST, $e->getErrorCode());
        $this->assertSame(400, $e->getHttpStatus());
        $this->assertSame($context, $e->getContext());
    }

    public function testAuthenticationExceptionDefaults(): void
    {
        $e = new AuthenticationException();
        $this->assertSame('未认证或登录已过期', $e->getMessage());
        $this->assertSame(ErrorCode::UNAUTHENTICATED, $e->getErrorCode());
        $this->assertSame(401, $e->getHttpStatus());
        $this->assertTrue($e->isSecurityEvent(), '认证异常应标记为安全事件');
    }

    public function testAuthorizationExceptionDefaults(): void
    {
        $e = new AuthorizationException();
        $this->assertSame('无权限执行此操作', $e->getMessage());
        $this->assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        $this->assertSame(403, $e->getHttpStatus());
        $this->assertTrue($e->isSecurityEvent(), '授权异常应标记为安全事件');
    }

    public function testValidationExceptionDefaults(): void
    {
        $e = new ValidationException();
        $this->assertSame('参数验证失败', $e->getMessage());
        $this->assertSame(ErrorCode::VALIDATION_FAILED, $e->getErrorCode());
        $this->assertSame(422, $e->getHttpStatus());
        $this->assertFalse($e->isSecurityEvent());
        $this->assertSame([], $e->getFieldErrors());
    }

    public function testValidationExceptionWithFieldErrors(): void
    {
        $fieldErrors = ['mobile' => '手机号格式不正确', 'amount' => '金额必须大于0'];
        $e = new ValidationException('参数验证失败', ErrorCode::VALIDATION_FAILED, $fieldErrors);
        $this->assertSame($fieldErrors, $e->getFieldErrors());
    }

    public function testValidationExceptionFieldHelper(): void
    {
        $e = ValidationException::field('mobile', '手机号格式不正确');
        $this->assertSame('手机号格式不正确', $e->getMessage());
        $this->assertSame(['mobile' => '手机号格式不正确'], $e->getFieldErrors());
    }

    public function testResourceNotFoundExceptionDefaults(): void
    {
        $e = new ResourceNotFoundException();
        $this->assertSame('资源不存在', $e->getMessage());
        $this->assertSame(ErrorCode::RESOURCE_NOT_FOUND, $e->getErrorCode());
        $this->assertSame(404, $e->getHttpStatus());
        $this->assertFalse($e->isSecurityEvent());
    }

    public function testResourceNotFoundExceptionOfHelper(): void
    {
        $e = ResourceNotFoundException::of('订单', 123);
        $this->assertSame('订单不存在: 123', $e->getMessage());
        $this->assertSame(404, $e->getHttpStatus());
        $this->assertSame(['resource_type' => '订单', 'id' => 123], $e->getContext());
    }

    public function testAllExceptionsImplementInterface(): void
    {
        $exceptions = [
            new BusinessException(),
            new ValidationException(),
            new AuthenticationException(),
            new AuthorizationException(),
            new ResourceNotFoundException(),
        ];
        foreach ($exceptions as $e) {
            $this->assertInstanceOf(\app\contracts\BusinessExceptionInterface::class, $e);
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }
}
