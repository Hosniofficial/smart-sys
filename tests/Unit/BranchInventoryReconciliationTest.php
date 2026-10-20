<?php

declare(strict_types=1);

use App\Services\BranchInventoryReconciliationService;
use PHPUnit\Framework\TestCase;

final class BranchInventoryReconciliationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE branch_products (
            tenant_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
            quantity NUMERIC NOT NULL, quantity_cost NUMERIC NOT NULL, gl_reconciled INTEGER NULL,
            PRIMARY KEY (tenant_id, branch_id, product_id)
        )');
        $this->db->exec("INSERT INTO branch_products VALUES (1, 10, 100, 4, 400, 1), (1, 10, 101, 4, 400, 0)");
    }

    public function testMarksPreviouslyReconciledStockUnreconciledInTheActiveTransaction(): void
    {
        $this->db->beginTransaction();
        $this->db->exec('UPDATE branch_products SET quantity = 3 WHERE tenant_id = 1 AND branch_id = 10 AND product_id = 100');

        BranchInventoryReconciliationService::markUnreconciled($this->db, 1, 10, 100);

        self::assertSame(0, (int) $this->readFlag(100));
        $this->db->commit();
        self::assertSame(0, (int) $this->readFlag(100));
    }

    public function testDoesNotChangeARecordAlreadyMarkedUnreconciled(): void
    {
        $this->db->beginTransaction();

        BranchInventoryReconciliationService::markUnreconciled($this->db, 1, 10, 101);

        self::assertSame(0, (int) $this->readFlag(101));
        self::assertSame(0, $this->db->query('SELECT changes()')->fetchColumn());
        $this->db->commit();
    }

    public function testStockAndReconciliationChangesRollbackTogether(): void
    {
        $this->db->beginTransaction();
        $this->db->exec('UPDATE branch_products SET quantity = 9, quantity_cost = 900 WHERE tenant_id = 1 AND branch_id = 10 AND product_id = 100');
        BranchInventoryReconciliationService::markUnreconciled($this->db, 1, 10, 100);

        self::assertSame(0, (int) $this->readFlag(100));
        $this->db->rollBack();

        self::assertSame(1, (int) $this->readFlag(100));
        self::assertSame(4.0, (float) $this->readQuantity(100));
        self::assertSame(400.0, (float) $this->readQuantityCost(100));
    }

    public function testRefusesToChangeTheFlagOutsideTheOperationTransaction(): void
    {
        $this->expectException(LogicException::class);
        BranchInventoryReconciliationService::markUnreconciled($this->db, 1, 10, 100);
    }

    public function testSalesDeleteSaleRestoresQuantityAndCostAndUnreconcilesStock(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/api/v1/src/Services/SalesService.php');
        self::assertNotFalse($source);
        self::assertSame(1, preg_match(
            '/UPDATE branch_products\\s+SET quantity = quantity \\+ \\?,\\s+quantity_cost = quantity_cost \\+ \\?.*?BranchInventoryReconciliationService::markUnreconciled\\(/s',
            $source
        ));
        self::assertStringContainsString("si.quantity, si.conversion_factor, si.purchase_price", $source);

        $this->db->beginTransaction();
        $this->db->exec('UPDATE branch_products SET quantity = quantity + 1, quantity_cost = quantity_cost + 100 WHERE tenant_id = 1 AND branch_id = 10 AND product_id = 100');
        BranchInventoryReconciliationService::markUnreconciled($this->db, 1, 10, 100);

        self::assertSame(0, (int) $this->readFlag(100));
        self::assertSame(5.0, (float) $this->readQuantity(100));
        self::assertSame(500.0, (float) $this->readQuantityCost(100));
        $this->db->rollBack();
    }

    public function testSaleCancellationDoesNotRequireCurrentBranchActivation(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/api/v1/src/Services/SalesService.php');
        self::assertNotFalse($source);
        self::assertStringNotContainsString('ProductBranchActivationGuard::assertActiveForStock(', $source);
        self::assertStringContainsString("COALESCE(p.product_type, 'stock') AS product_type", $source);
        self::assertStringContainsString('لا يمكن إلغاء فاتورة عليها مبلغ مدفوع', $source);
        self::assertStringContainsString('لا يمكن إلغاء فاتورة مرتبطة بمرتجع أو بتسوية رصيد', $source);
        self::assertStringContainsString('DELETE FROM payments WHERE tenant_id = ? AND sale_id = ? AND is_draft = 1', $source);
    }

    public function testStockMutationEntryPointsUpdateReconciliationState(): void
    {
        self::assertSame(1, $this->countGuardCalls('api/v1/src/Services/PurchaseService.php'));
        self::assertSame(1, $this->countGuardCalls('api/v1/src/Services/SaleCreationService.php'));
        self::assertSame(1, $this->countGuardCalls('api/v1/src/Services/ReturnService.php'));
        self::assertSame(2, $this->countGuardCalls('api/v1/src/Handlers/StockTransferHandler.php'));
        self::assertSame(1, $this->countGuardCalls('api/v1/src/Services/SaleApprovalService.php'));
        self::assertSame(1, $this->countGuardCalls('api/v1/src/Services/SalesService.php'));
        self::assertSame(3, $this->countGuardCalls('api/v1/src/Handlers/StockAdjustmentHandler.php'));
    }

    public function testOpeningBalanceKeepsReconciledStateOnlyAfterJournalPosts(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/api/v1/src/Services/InventoryOpeningBalanceService.php');
        self::assertNotFalse($service);
        self::assertLessThan(
            strpos($service, 'if (!$jeId)'),
            strpos($service, 'gl_reconciled        = 0')
        );
        self::assertGreaterThan(
            strpos($service, 'if (!$jeId)'),
            strpos($service, 'SET gl_reconciled = 1, last_gl_posting_date = NOW()')
        );

        $handler = file_get_contents(dirname(__DIR__, 2) . '/api/v1/src/Handlers/OpeningBalanceHandler.php');
        self::assertNotFalse($handler);
        self::assertGreaterThan(0, strpos($handler, 'gl_reconciled = 0'));
        self::assertGreaterThan(0, strpos($handler, 'SET gl_reconciled        = 1'));
    }

    public function testOpeningBalancePostingFailureLeavesStockUnreconciled(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/api/v1/src/Services/InventoryOpeningBalanceService.php');
        self::assertNotFalse($source);

        $initialUnreconciled = strpos($source, 'gl_reconciled        = 0');
        $failedJournalCheck = strpos($source, 'if (!$jeId) {');
        $markReconciled = strpos($source, 'SET gl_reconciled = 1, last_gl_posting_date = NOW()');

        self::assertNotFalse($initialUnreconciled);
        self::assertNotFalse($failedJournalCheck);
        self::assertNotFalse($markReconciled);
        self::assertLessThan($failedJournalCheck, $initialUnreconciled);
        self::assertLessThan($markReconciled, $failedJournalCheck);
        self::assertStringContainsString(
            "throw new \\RuntimeException('فشل إنشاء القيد المحاسبي للرصيد الافتتاحي.')",
            substr($source, $failedJournalCheck, $markReconciled - $failedJournalCheck)
        );
    }

    private function countGuardCalls(string $relativePath): int
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertNotFalse($source, "Could not read {$relativePath}");
        return substr_count($source, 'BranchInventoryReconciliationService::markUnreconciled(');
    }

    private function readFlag(int $productId): mixed
    {
        $stmt = $this->db->prepare('SELECT gl_reconciled FROM branch_products WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchColumn();
    }

    private function readQuantity(int $productId): mixed
    {
        $stmt = $this->db->prepare('SELECT quantity FROM branch_products WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchColumn();
    }

    private function readQuantityCost(int $productId): mixed
    {
        $stmt = $this->db->prepare('SELECT quantity_cost FROM branch_products WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchColumn();
    }
}
