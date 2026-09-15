<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\V1Pagination;
use app\support\V1ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * R1.2 — V1Pagination Contract Tests
 *
 * 覆盖：默认值、合法自定义、非法 page/page_size 拒绝、整数解析语义、
 * offset/meta 计算、排序白名单、order 归一化、overflow 允许。
 */
class V1PaginationContractTest extends TestCase
{
    // ---------- 默认值 ----------

    public function testDefaultValuesWhenInputEmpty(): void
    {
        $p = V1Pagination::fromArray([]);
        $this->assertSame(1, $p['page']);
        $this->assertSame(20, $p['page_size']);
        $this->assertSame(0, $p['offset']);
        $this->assertSame(20, $p['limit']);
        $this->assertNull($p['sort']);
        $this->assertSame('desc', $p['order']);
    }

    public function testValidCustomValues(): void
    {
        $p = V1Pagination::fromArray(['page' => 3, 'page_size' => 50]);
        $this->assertSame(3, $p['page']);
        $this->assertSame(50, $p['page_size']);
        $this->assertSame(100, $p['offset']);
        $this->assertSame(50, $p['limit']);
    }

    // ---------- 非法 page ----------

    public function testPageZeroRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page' => 0]);
    }

    public function testPageNegativeRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page' => -1]);
    }

    public function testPageNonNumericRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page' => 'abc']);
    }

    public function testPageAboveMaxRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page' => 10001]);
    }

    // ---------- 非法 page_size ----------

    public function testPageSizeZeroRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page_size' => 0]);
    }

    public function testPageSizeAboveMaxRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page_size' => 101]);
    }

    public function testPageSizeNonNumericRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page_size' => 'huge']);
    }

    // ---------- offset 计算 ----------

    public function testOffsetCalculationBoundaries(): void
    {
        $this->assertSame(0, V1Pagination::fromArray(['page' => 1])['offset']);
        $this->assertSame(20, V1Pagination::fromArray(['page' => 2, 'page_size' => 20])['offset']);
        $p = V1Pagination::fromArray(['page' => 10000, 'page_size' => 100]);
        $this->assertSame(999900, $p['offset']);
        $this->assertSame(100, $p['limit']);
    }

    // ---------- meta ----------

    public function testMetaEmptyTotal(): void
    {
        $m = V1Pagination::meta(1, 20, 0);
        $this->assertSame(0, $m['total']);
        $this->assertSame(0, $m['total_pages']);
    }

    public function testMetaNormalTotalPages(): void
    {
        $this->assertSame(5, V1Pagination::meta(1, 20, 100)['total_pages']);
        $this->assertSame(6, V1Pagination::meta(1, 20, 101)['total_pages']);
        $this->assertSame(1, V1Pagination::meta(1, 20, 1)['total_pages']);
    }

    public function testMetaPreservesRequestedPage(): void
    {
        $m = V1Pagination::meta(7, 20, 100);
        $this->assertSame(7, $m['page']);
        $this->assertSame(20, $m['page_size']);
        $this->assertSame(100, $m['total']);
        $this->assertSame(5, $m['total_pages']);
    }

    // ---------- overflow：page > total_pages 不属于 validation failure ----------

    public function testOverflowPageIsAllowedByFromArray(): void
    {
        // fromArray 不校验 page vs total；meta 允许 page > total_pages
        $p = V1Pagination::fromArray(['page' => 999, 'page_size' => 20]);
        $this->assertSame(999, $p['page']);
        $m = V1Pagination::meta(999, 20, 10);
        $this->assertSame(999, $m['page']);
        $this->assertSame(1, $m['total_pages']);
    }

    // ---------- 排序白名单 ----------

    public function testAllowedSortAccepted(): void
    {
        $p = V1Pagination::fromArray(
            ['sort' => 'created_at'],
            ['id', 'created_at', 'status'],
            'id'
        );
        $this->assertSame('created_at', $p['sort']);
    }

    public function testUnknownSortRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(
            ['sort' => 'password'],
            ['id', 'created_at'],
            'id'
        );
    }

    public function testSortIgnoredWhenAllowedSortEmpty(): void
    {
        // endpoint 未声明排序能力：客户端 sort 被忽略，使用默认
        $p = V1Pagination::fromArray(['sort' => 'anything'], [], 'created_at');
        $this->assertSame('created_at', $p['sort']);
    }

    public function testDefaultSortUsedWhenNotProvided(): void
    {
        $p = V1Pagination::fromArray([], ['id', 'created_at'], 'created_at');
        $this->assertSame('created_at', $p['sort']);
    }

    // ---------- order ----------

    public function testInvalidOrderRejected(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['order' => 'desc; DROP TABLE']);
    }

    public function testOrderCaseNormalized(): void
    {
        $this->assertSame('asc', V1Pagination::fromArray(['order' => 'ASC'])['order']);
        $this->assertSame('desc', V1Pagination::fromArray(['order' => 'DESC'])['order']);
    }

    // ---------- Query-string 整数语义（真实 HTTP 可用性关键） ----------

    public function testCanonicalIntegerStringAccepted(): void
    {
        $p = V1Pagination::fromArray(['page' => '1', 'page_size' => '20']);
        $this->assertSame(1, $p['page']);
        $this->assertSame(20, $p['page_size']);
    }

    /**
     * @dataProvider nonCanonicalIntegerProvider
     */
    public function testNonCanonicalIntegerStringRejected(mixed $value): void
    {
        $this->expectException(V1ValidationException::class);
        V1Pagination::fromArray(['page' => $value]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonCanonicalIntegerProvider(): array
    {
        return [
            'leading zero'   => ['01'],
            'decimal'        => ['1.0'],
            'scientific'     => ['1e2'],
            'plus sign'      => ['+1'],
            'with spaces'    => [' 1 '],
            'alpha'          => ['abc'],
            'empty string'   => [''],
        ];
    }

    public function testRealIntegerFromJsonBodyAccepted(): void
    {
        // JSON body 中真 int 也接受（与 query string 语义一致）
        $p = V1Pagination::fromArray(['page' => 2, 'page_size' => 50]);
        $this->assertSame(2, $p['page']);
        $this->assertSame(50, $p['page_size']);
    }

    // ---------- 异常 details 结构 ----------

    public function testExceptionDetailsFieldMessagesArray(): void
    {
        try {
            V1Pagination::fromArray(['page' => 0, 'page_size' => 'x']);
            $this->fail('expected exception');
        } catch (V1ValidationException $e) {
            $this->assertArrayHasKey('page', $e->details);
            $this->assertArrayHasKey('page_size', $e->details);
            $this->assertIsArray($e->details['page']);
            $this->assertIsArray($e->details['page_size']);
            $this->assertNotEmpty($e->details['page'][0]);
        }
    }
}
