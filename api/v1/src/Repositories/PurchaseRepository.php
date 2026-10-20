<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * PurchaseRepository
 *
 * Centralises data-access queries for the `purchases` table.
 */
class PurchaseRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Find a single purchase by ID and tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get net completed, non-draft payments for a purchase after supplier refunds.
     */
    public function getTotalPaid(int $purchaseId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(
                 CASE WHEN type = 'payment_for_return' THEN -amount ELSE amount END
             ), 0)
             FROM payments
             WHERE purchase_id = ? AND tenant_id = ?
               AND status = 'completed' AND is_draft = 0"
        );
        $stmt->execute([$purchaseId, $tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Get total_amount for a purchase.
     */
    public function getTotalAmount(int $purchaseId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            "SELECT total_amount FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$purchaseId, $tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Update paid_amount and status for a purchase.
     */
    public function updateBalance(int $purchaseId, int $tenantId, float $paidAmount, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE purchases
             SET paid_amount = ?, status = ?, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?"
        );
        return $stmt->execute([$paidAmount, $status, $purchaseId, $tenantId]);
    }

    /**
     * Update journal_entry_id for a purchase.
     */
    public function setJournalEntryId(int $purchaseId, int $tenantId, int $journalEntryId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE purchases SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
        );
        return $stmt->execute([$journalEntryId, $purchaseId, $tenantId]);
    }
}
