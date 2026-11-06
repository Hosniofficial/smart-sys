<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * SaleRepository
 *
 * Centralises data-access queries for the `sales` table.
 * Handlers and services should use this class instead of writing
 * raw SQL against the sales table directly.
 */
class SaleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // Read

    /**
     * Find a single sale by ID and tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get the grand total (net_total_amount + tax_amount) for a sale.
     */
    public function getGrandTotal(int $saleId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            "SELECT ROUND(net_total_amount + IFNULL(tax_amount, 0), 2)
             FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$saleId, $tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Get total completed payments for a sale.
     */
    public function getTotalPaid(int $saleId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(
                CASE WHEN type = 'return_payment' THEN -amount ELSE amount END
             ), 0)
             FROM payments
             WHERE sale_id = ? AND tenant_id = ? AND status = 'completed'"
        );
        $stmt->execute([$saleId, $tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Get total returned amount for a sale.
     */
    public function getTotalReturned(int $saleId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(grand_total), 0)
             FROM returns
             WHERE sale_id = ? AND tenant_id = ? AND return_type = 'sale'"
        );
        $stmt->execute([$saleId, $tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    // Write

    /**
     * Update paid_amount and status for a sale.
     */
    public function updateBalance(int $saleId, int $tenantId, float $paidAmount, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE sales
             SET paid_amount = ?, status = ?, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?"
        );
        return $stmt->execute([$paidAmount, $status, $saleId, $tenantId]);
    }

    /**
     * Update journal_entry_id for a sale.
     */
    public function setJournalEntryId(int $saleId, int $tenantId, int $journalEntryId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE sales SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
        );
        return $stmt->execute([$journalEntryId, $saleId, $tenantId]);
    }
}
