<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * PaymentRepository
 *
 * Centralises data-access queries for the `payments` table.
 */
class PaymentRepository
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
     * Find a single payment by ID and tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payments WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get total completed payments for a sale.
     */
    public function getTotalPaidForSale(int $saleId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE sale_id = ? AND tenant_id = ? AND status = 'completed'"
        );
        $stmt->execute([$saleId, $tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Get net completed, non-draft payments for a purchase after supplier refunds.
     */
    public function getTotalPaidForPurchase(int $purchaseId, int $tenantId): float
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

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Update journal_entry_id for a payment.
     */
    public function setJournalEntryId(int $paymentId, int $tenantId, int $journalEntryId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE payments SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
        );
        return $stmt->execute([$journalEntryId, $paymentId, $tenantId]);
    }

    /**
     * Insert a payment application record (links payment to invoice).
     */
    public function insertApplication(
        int $tenantId,
        int $paymentId,
        string $referenceType,
        int $referenceId,
        float $amount,
        ?int $createdBy
    ): bool {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO payment_applications
                 (tenant_id, payment_id, reference_type, reference_id, amount, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            return $stmt->execute([
                $tenantId, $paymentId, $referenceType, $referenceId, $amount, $createdBy
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
