<?php

declare(strict_types=1);

/**
 * Enforce tenant-scoped purchase invoice number uniqueness.
 * Existing duplicates stop the migration so they can be reviewed before indexing.
 */
final class AddPurchaseInvoiceNumberUniqueness
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $duplicateCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT tenant_id, invoice_number
                FROM purchases
                WHERE tenant_id IS NOT NULL AND invoice_number IS NOT NULL
                GROUP BY tenant_id, invoice_number
                HAVING COUNT(*) > 1
            ) duplicate_purchase_invoice_numbers"
        )->fetchColumn();
        if ($duplicateCount > 0) {
            throw new RuntimeException(
                'Cannot add purchase invoice number index: duplicate tenant/invoice-number pairs already exist.'
            );
        }

        $indexStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'purchases'
               AND index_name = 'uq_purchases_tenant_invoice_number'"
        );
        $indexStmt->execute();
        if ((int) $indexStmt->fetchColumn() === 0) {
            $this->pdo->exec(
                'ALTER TABLE purchases ADD UNIQUE INDEX uq_purchases_tenant_invoice_number (tenant_id, invoice_number)'
            );
        }
    }

    public function down(): void
    {
        $indexStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'purchases'
               AND index_name = 'uq_purchases_tenant_invoice_number'"
        );
        $indexStmt->execute();
        if ((int) $indexStmt->fetchColumn() > 0) {
            $this->pdo->exec('ALTER TABLE purchases DROP INDEX uq_purchases_tenant_invoice_number');
        }
    }
}
