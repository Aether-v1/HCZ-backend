<?php
/**
 * HCZ R1.6a: Status Semantics & Model Constants Contract Test
 *
 * Locks the numeric values of canonical status constants so that
 * behavior-preserving refactoring cannot accidentally renumber them.
 *
 * These values are the frozen DB/API contract — do not change.
 */

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;
use app\model\Order;
use app\model\Withdrawal;
use app\model\Substation;
use app\model\Product;
use app\model\TransactionOrder;

class StatusConstantsContractTest extends TestCase
{
    // ===== Order (P2-R1.6-02) =====
    // 0 = pending, 1 = processing, 2 = completed, 3 = cancelled
    public function testOrderStatusPendingIsZero(): void
    {
        $this->assertSame(0, Order::STATUS_PENDING);
    }

    public function testOrderStatusProcessingIsOne(): void
    {
        $this->assertSame(1, Order::STATUS_PROCESSING);
    }

    public function testOrderStatusCompletedIsTwo(): void
    {
        $this->assertSame(2, Order::STATUS_COMPLETED);
    }

    public function testOrderStatusCancelledIsThree(): void
    {
        $this->assertSame(3, Order::STATUS_CANCELLED);
    }

    public function testOrderStatusValuesAreDistinct(): void
    {
        $values = [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_COMPLETED,
            Order::STATUS_CANCELLED,
        ];
        $this->assertSame($values, array_unique($values));
    }

    // ===== Withdrawal =====
    // 0 = pending review, 1 = approved/success, 2 = rejected/failed
    public function testWithdrawalStatusPendingIsZero(): void
    {
        $this->assertSame(0, Withdrawal::STATUS_PENDING);
    }

    public function testWithdrawalStatusApprovedIsOne(): void
    {
        $this->assertSame(1, Withdrawal::STATUS_APPROVED);
    }

    public function testWithdrawalStatusRejectedIsTwo(): void
    {
        $this->assertSame(2, Withdrawal::STATUS_REJECTED);
    }

    public function testWithdrawalStatusValuesAreDistinct(): void
    {
        $values = [
            Withdrawal::STATUS_PENDING,
            Withdrawal::STATUS_APPROVED,
            Withdrawal::STATUS_REJECTED,
        ];
        $this->assertSame($values, array_unique($values));
    }

    // ===== Substation (R1.6a.2-B — corrected semantics) =====
    // 0 = pending/new, 1 = submitted/awaiting audit, 2 = approved/audit passed,
    // 3 = rejected, 4 = suspended/frozen, 5 = activated/paid
    // CRITICAL: STATUS_REJECTED = 3 (NOT 2). Old R1.6a wrongly named it =2.
    public function testSubstationStatusPendingIsZero(): void
    {
        $this->assertSame(0, Substation::STATUS_PENDING);
    }

    public function testSubstationStatusSubmittedIsOne(): void
    {
        $this->assertSame(1, Substation::STATUS_SUBMITTED);
    }

    public function testSubstationStatusApprovedIsTwo(): void
    {
        $this->assertSame(2, Substation::STATUS_APPROVED);
    }

    public function testSubstationStatusRejectedIsThree(): void
    {
        $this->assertSame(3, Substation::STATUS_REJECTED);
    }

    public function testSubstationStatusSuspendedIsFour(): void
    {
        $this->assertSame(4, Substation::STATUS_SUSPENDED);
    }

    public function testSubstationStatusActivatedIsFive(): void
    {
        $this->assertSame(5, Substation::STATUS_ACTIVATED);
    }

    public function testSubstationStatusValuesAreDistinct(): void
    {
        $values = [
            Substation::STATUS_PENDING,
            Substation::STATUS_SUBMITTED,
            Substation::STATUS_APPROVED,
            Substation::STATUS_REJECTED,
            Substation::STATUS_SUSPENDED,
            Substation::STATUS_ACTIVATED,
        ];
        $this->assertSame($values, array_unique($values));
    }

    // ===== Product =====
    // 0 = disabled, 1 = enabled
    public function testProductStatusDisabledIsZero(): void
    {
        $this->assertSame(0, Product::STATUS_DISABLED);
    }

    public function testProductStatusEnabledIsOne(): void
    {
        $this->assertSame(1, Product::STATUS_ENABLED);
    }

    // ===== Cross-domain: no accidental value collision within same domain =====
    public function testAllConstantsArePublic(): void
    {
        $reflection = new \ReflectionClass(Order::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_')) {
                $this->assertTrue(
                    $reflection->getReflectionConstant($name)->isPublic(),
                    "Order::$name must be public"
                );
            }
        }
    }

    // ===== TransactionOrder (R1.6a.2-A) =====
    // 0 = pending(待汇款), 1 = remitted(已汇款), 2 = cancelled(已取消), 3 = completed(已完成)
    // CRITICAL: 2/3 are SWAPPED vs Order. Order: 2=completed, 3=cancelled.
    public function testTransactionOrderStatusPendingIsZero(): void
    {
        $this->assertSame(0, TransactionOrder::STATUS_PENDING);
    }

    public function testTransactionOrderStatusRemittedIsOne(): void
    {
        $this->assertSame(1, TransactionOrder::STATUS_REMITTED);
    }

    public function testTransactionOrderStatusCancelledIsTwo(): void
    {
        $this->assertSame(2, TransactionOrder::STATUS_CANCELLED);
    }

    public function testTransactionOrderStatusCompletedIsThree(): void
    {
        $this->assertSame(3, TransactionOrder::STATUS_COMPLETED);
    }

    public function testTransactionOrderStatusValuesAreDistinct(): void
    {
        $values = [
            TransactionOrder::STATUS_PENDING,
            TransactionOrder::STATUS_REMITTED,
            TransactionOrder::STATUS_CANCELLED,
            TransactionOrder::STATUS_COMPLETED,
        ];
        $this->assertSame($values, array_unique($values));
    }

    // ===== CRITICAL Cross-Entity: Order vs TransactionOrder 2/3 swap =====
    // This test exists to prevent developers from assuming Order and TransactionOrder
    // share the same status semantics. They do NOT — 2 and 3 are reversed.
    public function testOrderCompletedIsTwoButTransactionOrderCancelledIsTwo(): void
    {
        $this->assertSame(2, Order::STATUS_COMPLETED);
        $this->assertSame(2, TransactionOrder::STATUS_CANCELLED);
        $this->assertNotSame(Order::STATUS_COMPLETED, TransactionOrder::STATUS_COMPLETED);
    }

    public function testOrderCancelledIsThreeButTransactionOrderCompletedIsThree(): void
    {
        $this->assertSame(3, Order::STATUS_CANCELLED);
        $this->assertSame(3, TransactionOrder::STATUS_COMPLETED);
        $this->assertNotSame(Order::STATUS_CANCELLED, TransactionOrder::STATUS_CANCELLED);
    }

    public function testTransactionOrderConstantsArePublic(): void
    {
        $reflection = new \ReflectionClass(TransactionOrder::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_')) {
                $this->assertTrue(
                    $reflection->getReflectionConstant($name)->isPublic(),
                    "TransactionOrder::$name must be public"
                );
            }
        }
    }
}
