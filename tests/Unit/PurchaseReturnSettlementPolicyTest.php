<?php

declare(strict_types=1);

use App\Services\PurchaseReturnSettlementPolicy;
use PHPUnit\Framework\TestCase;

final class PurchaseReturnSettlementPolicyTest extends TestCase
{
    public function testCashModeRefundsTheFullSettledReturn(): void
    {
        self::assertSame(10000.0, PurchaseReturnSettlementPolicy::cashRefundAmount('cash', 10000.0, 0.0, 5000.0));
    }

    public function testCreditNoteModeLeavesTheFullReturnOnTheSupplierAccount(): void
    {
        self::assertSame(0.0, PurchaseReturnSettlementPolicy::cashRefundAmount('credit_note', 10000.0, 0.0, 5000.0));
    }

    public function testAutoModeOffsetsSupplierPayableBeforeRefundingTheExcess(): void
    {
        self::assertSame(7000.0, PurchaseReturnSettlementPolicy::cashRefundAmount('auto', 10000.0, 0.0, 3000.0));
    }

    public function testAutoModeRefundsFullyWhenThereIsNoSupplierPayable(): void
    {
        self::assertSame(10000.0, PurchaseReturnSettlementPolicy::cashRefundAmount('auto', 10000.0, 0.0, 0.0));
    }

    public function testNonCashModesDoNotRefundAnUnpaidPurchase(): void
    {
        self::assertSame(0.0, PurchaseReturnSettlementPolicy::cashRefundAmount('auto', 10000.0, 2500.0, 5000.0));
        self::assertSame(0.0, PurchaseReturnSettlementPolicy::cashRefundAmount('credit_note', 10000.0, 2500.0, 5000.0));
    }

    public function testCashModeRejectsAnUnpaidPurchase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('لا يمكن استلام نقدي');

        PurchaseReturnSettlementPolicy::cashRefundAmount('cash', 10000.0, 1.0, 5000.0);
    }

    public function testOutstandingSubtractsNetPaymentsAndAllReturns(): void
    {
        self::assertSame(
            400.0,
            PurchaseReturnSettlementPolicy::outstandingAmount(1000.0, 500.0, 100.0)
        );
    }

    public function testCashSupplierRefundReducesNetPaidWithoutCreatingInvoiceDebt(): void
    {
        self::assertSame(
            0.0,
            PurchaseReturnSettlementPolicy::outstandingAmount(1000.0, 900.0, 100.0)
        );
    }

    public function testOutstandingNeverBecomesNegative(): void
    {
        self::assertSame(
            0.0,
            PurchaseReturnSettlementPolicy::outstandingAmount(1000.0, 900.0, 200.0)
        );
    }
}
