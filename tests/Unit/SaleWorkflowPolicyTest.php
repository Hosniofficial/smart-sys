<?php

declare(strict_types=1);

use App\Security\SaleWorkflowPolicy;
use PHPUnit\Framework\TestCase;

final class SaleWorkflowPolicyTest extends TestCase
{
    public function testAllowsPaymentsOnOpenInvoiceStatuses(): void
    {
        foreach (['pending_payment', 'partial', 'due'] as $status) {
            SaleWorkflowPolicy::assertPaymentAllowed($status);
        }

        self::assertTrue(true);
    }

    public function testRejectsPaymentsOnUnapprovedOrClosedInvoices(): void
    {
        foreach ([
            'pending_approval',
            'awaiting_approval',
            'rejected',
            'draft',
            'pending',
            'canceled',
            'cancelled',
            'paid',
            'returned',
            '',
        ] as $status) {
            try {
                SaleWorkflowPolicy::assertPaymentAllowed($status);
                self::fail("Expected payment to be rejected for {$status}.");
            } catch (DomainException $e) {
                self::assertSame('لا يمكن تسجيل دفعة على فاتورة غير معتمدة أو ملغاة.', $e->getMessage());
            }
        }
    }

    public function testRejectsReturnsOnUnapprovedOrCancelledSales(): void
    {
        foreach ([
            'draft',
            'pending',
            'pending_approval',
            'awaiting_approval',
            'rejected',
            'canceled',
            'CANCELED',
            'cancelled',
            ' CANCELLED ',
        ] as $status) {
            try {
                SaleWorkflowPolicy::assertReturnAllowed($status);
                self::fail("Expected return to be rejected for {$status}.");
            } catch (DomainException $e) {
                self::assertSame('لا يمكن إنشاء مرتجع لفاتورة مبيعات غير معتمدة أو ملغاة.', $e->getMessage());
            }
        }
    }

    public function testAllowsReturnsOnApprovedSalesRegardlessOfPaymentStatus(): void
    {
        foreach (['pending_payment', 'partial', 'due', 'paid', 'returned'] as $status) {
            SaleWorkflowPolicy::assertReturnAllowed($status);
        }

        self::assertTrue(true);
    }

    public function testRecognizesOnlyCanonicalApprovalPendingStatus(): void
    {
        self::assertTrue(SaleWorkflowPolicy::isApprovalPending('pending_approval'));
        self::assertTrue(SaleWorkflowPolicy::isApprovalPending(' PENDING_APPROVAL '));
        self::assertFalse(SaleWorkflowPolicy::isApprovalPending('pending'));
        self::assertFalse(SaleWorkflowPolicy::isApprovalPending('awaiting_approval'));
        self::assertFalse(SaleWorkflowPolicy::isApprovalPending('pending_payment'));
    }

    public function testAllowsEditingOnlyUnpostedSales(): void
    {
        foreach (['draft', 'pending_approval', 'rejected'] as $status) {
            SaleWorkflowPolicy::assertSaleEditable($status, false);
        }

        self::assertTrue(true);
    }

    public function testRejectsEditingApprovedOrPostedSales(): void
    {
        foreach (['pending', 'awaiting_approval', 'pending_payment', 'partial', 'due', 'paid', 'canceled', 'cancelled'] as $status) {
            try {
                SaleWorkflowPolicy::assertSaleEditable($status, false);
                self::fail("Expected editing to be rejected for {$status}.");
            } catch (DomainException $e) {
                self::assertStringContainsString('لا يمكن تعديل فاتورة', $e->getMessage());
            }

        }

        try {
            SaleWorkflowPolicy::assertSaleEditable('pending_approval', true);
            self::fail('Expected editing to be rejected when a posted journal exists.');
        } catch (DomainException $e) {
            self::assertStringContainsString('ترحيلها محاسبياً', $e->getMessage());
        }
    }

    public function testApprovalDoesNotPostCogsOutsideTheSaleJournal(): void
    {
        $root = dirname(__DIR__, 2);
        $handler = file_get_contents($root . '/api/v1/src/Handlers/SalesHandler.php');
        $accounting = file_get_contents($root . '/api/v1/src/Services/AccountingService.php');
        self::assertNotFalse($handler);
        self::assertNotFalse($accounting);

        $approveStart = strpos($handler, 'public function approve(');
        $rejectStart = strpos($handler, 'public function reject(', $approveStart);
        self::assertNotFalse($approveStart);
        self::assertNotFalse($rejectStart);
        $approveHandler = substr($handler, $approveStart, $rejectStart - $approveStart);
        self::assertStringNotContainsString('postCOGSForSale(', $approveHandler);

        $journalStart = strpos($accounting, 'public function postSaleJournalEntry(');
        self::assertNotFalse($journalStart);
        $saleJournal = substr($accounting, $journalStart);
        self::assertStringContainsString("'cogs_account_id'", $saleJournal);
        self::assertStringContainsString("'5103'", $saleJournal);
    }

    public function testRejectionAndCancellationCleanUpSaleArtifacts(): void
    {
        $root = dirname(__DIR__, 2);
        $approval = file_get_contents($root . '/api/v1/src/Services/SaleApprovalService.php');
        $sales = file_get_contents($root . '/api/v1/src/Services/SalesService.php');
        $handler = file_get_contents($root . '/api/v1/src/Handlers/SalesHandler.php');
        $payment = file_get_contents($root . '/api/v1/src/Services/SalePaymentService.php');
        self::assertNotFalse($approval);
        self::assertNotFalse($sales);
        self::assertNotFalse($handler);
        self::assertNotFalse($payment);
        self::assertStringContainsString(
            "DELETE FROM payments WHERE tenant_id = ? AND sale_id = ? AND is_draft = 1",
            $approval
        );
        self::assertStringNotContainsString('updateSaleStatus(', $approval);
        self::assertStringNotContainsString('public function updateStatus(', $handler);
        self::assertStringContainsString('SaleWorkflowPolicy::assertPaymentAllowed', $handler);
        self::assertStringContainsString('SaleWorkflowPolicy::assertPaymentAllowed', $payment);
        self::assertStringContainsString('$amount > $remaining + 0.01', $payment);
        self::assertStringContainsString("reference_type IN ('sale', 'cogs')", $sales);
        self::assertStringContainsString('reverseJournalEntry(', $sales);
        self::assertStringContainsString('لا يمكن تغيير طريقة الدفع بعد تسجيل دفعة على الفاتورة.', $sales);
    }
}
