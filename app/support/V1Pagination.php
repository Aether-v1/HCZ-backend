<?php
declare(strict_types=1);

namespace app\support;

use RuntimeException;

/**
 * V1 分页参数 Foundation。
 *
 * 职责：解析并校验 page/page_size/sort/order，计算 offset/limit/meta。
 * 不查询数据库、不依赖 Model、不生成 SQL、不修改 Request。
 *
 * 严格整数语义：
 *   - 真 int 或 canonical 整数字符串 "1" / "20" 接受
 *   - "01" / "1.0" / "1e2" / "+1" / " 1 " / "abc" / 0 / -1 一律拒绝
 *   不使用 (int) 强转，避免 "abc"→0 吞掉客户端错误。
 */
class V1Pagination
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    public const MAX_PAGE = 10000;

    /**
     * 从 query 数组解析分页参数。
     *
     * @param array       $input           原始 query 参数（如 $request->get()）
     * @param array       $allowedSort     允许的排序字段白名单；为空表示 endpoint 不支持自定义排序
     * @param string|null $defaultSort     默认排序字段；null 表示不排序
     * @param string      $defaultOrder    默认排序方向 asc|desc
     * @param int         $defaultPageSize 默认每页条数
     * @param int         $maxPageSize     每页条数上限
     *
     * @return array{page:int,page_size:int,offset:int,limit:int,sort:string|null,order:string}
     *
     * @throws V1ValidationException 任何参数非法时抛出，details 为 field=>messages[]
     */
    public static function fromArray(
        array $input,
        array $allowedSort = [],
        ?string $defaultSort = null,
        string $defaultOrder = 'desc',
        int $defaultPageSize = self::DEFAULT_PAGE_SIZE,
        int $maxPageSize = self::MAX_PAGE_SIZE
    ): array {
        $errors = [];

        // page
        $pageRaw = array_key_exists('page', $input) ? $input['page'] : self::DEFAULT_PAGE;
        $page = self::parsePositiveInt($pageRaw);
        if ($page === null) {
            $errors['page'] = ['page must be a positive integer'];
        } elseif ($page > self::MAX_PAGE) {
            $errors['page'] = ['page must not exceed ' . self::MAX_PAGE];
            $page = null;
        }

        // page_size
        $sizeRaw = array_key_exists('page_size', $input) ? $input['page_size'] : $defaultPageSize;
        $pageSize = self::parsePositiveInt($sizeRaw);
        if ($pageSize === null) {
            $errors['page_size'] = ['page_size must be a positive integer'];
        } elseif ($pageSize > $maxPageSize) {
            $errors['page_size'] = ['page_size must not exceed ' . $maxPageSize];
            $pageSize = null;
        }

        // sort
        $sort = $defaultSort;
        if (array_key_exists('sort', $input) && $input['sort'] !== null && $input['sort'] !== '') {
            $sortRaw = $input['sort'];
            if (!is_string($sortRaw)) {
                $errors['sort'] = ['sort must be a string'];
            } elseif ($allowedSort === []) {
                // endpoint 未声明排序能力：忽略客户端 sort，使用默认
                $sort = $defaultSort;
            } elseif (!in_array($sortRaw, $allowedSort, true)) {
                $errors['sort'] = ['sort is not allowed'];
            } else {
                $sort = $sortRaw;
            }
        }

        // order
        $orderRaw = array_key_exists('order', $input) ? $input['order'] : $defaultOrder;
        if (!is_string($orderRaw)) {
            $errors['order'] = ['order must be asc or desc'];
            $order = $defaultOrder;
        } else {
            $order = strtolower(trim($orderRaw));
            if (!in_array($order, ['asc', 'desc'], true)) {
                $errors['order'] = ['order must be asc or desc'];
                $order = $defaultOrder;
            }
        }

        if ($errors !== []) {
            throw new V1ValidationException($errors);
        }

        return [
            'page'      => $page,
            'page_size' => $pageSize,
            'offset'    => ($page - 1) * $pageSize,
            'limit'     => $pageSize,
            'sort'      => $sort,
            'order'     => $order,
        ];
    }

    /**
     * 生成分页 meta。
     *
     * @return array{page:int,page_size:int,total:int,total_pages:int}
     */
    public static function meta(int $page, int $pageSize, int $total): array
    {
        if ($pageSize < 1) {
            throw new RuntimeException('pageSize must be >= 1');
        }
        if ($total < 0) {
            throw new RuntimeException('total must be >= 0');
        }
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $pageSize);
        return [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * 解析正整数：接受真 int 或 canonical 整数字符串。
     * 拒绝前导零、小数、科学计数、正负号、空白、非数字。
     */
    private static function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }
}
