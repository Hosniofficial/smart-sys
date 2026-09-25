<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class AccountingPeriodCloseService
{
    public function close(
        PDO $db,
        int $tenantId,
        int $periodId,
        ?int $userId,
        bool $overrideRequested = false,
        bool $canOverride = false,
        string $overrideReason = '',
        ?string $ipAddress = null
    ): array {
        if ($overrideRequested && !$canOverride) {
            return ['http_status' => 403, 'message' => 'لا تملك صلاحية تجاوز فحوصات إقفال الفترة.'];
        }
        if ($overrideRequested && trim($overrideReason) === '') {
            return ['http_status' => 400, 'message' => 'سبب التجاوز مطلوب.'];
        }

        $db->beginTransaction();
        try {
            $lockClause = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $periodStmt = $db->prepare(
                "SELECT id, start_date, end_date FROM accounting_periods
                 WHERE id = ? AND tenant_id = ? AND status = 'open' LIMIT 1{$lockClause}"
            );
            $periodStmt->execute([$periodId, $tenantId]);
            $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                $db->rollBack();
                return ['http_status' => 404, 'message' => 'الدورة غير موجودة أو مغلقة مسبقاً'];
            }

            $blockers = $this->findBlockers($db, $tenantId, $period['start_date'], $period['end_date']);
            $hasBlockers = $blockers['unreconciled_inventory_count'] > 0
                || $blockers['pending_journal_count'] > 0;

            if ($hasBlockers && !$overrideRequested) {
                $db->rollBack();
                return [
                    'http_status' => 409,
                    'message' => 'لا يمكن إغلاق الفترة قبل تسوية المخزون وترحيل القيود المعلّقة.',
                    'blockers' => $blockers,
                ];
            }

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $update = $db->prepare(
                "UPDATE accounting_periods
                 SET status = 'closed', closed_by = ?, closed_at = ?, updated_at = ?
                 WHERE id = ? AND tenant_id = ? AND status = 'open'"
            );
            $update->execute([$userId, $now, $now, $periodId, $tenantId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('تعذر إغلاق الدورة؛ ربما تغيرت حالتها بالتزامن.');
            }

            $overridden = $hasBlockers && $overrideRequested;
            if ($overridden) {
                $details = json_encode([
                    'reason' => trim($overrideReason),
                    'blockers' => $blockers,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $audit = $db->prepare(
                    'INSERT INTO audit_log
                     (user_id, action, entity, entity_id, details, tenant_id, ip_address, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $audit->execute([
                    $userId,
                    'accounting_period_close_override',
                    'accounting_period',
                    $periodId,
                    $details,
                    $tenantId,
                    $ipAddress,
                    $now,
                ]);
            }

            $db->commit();
            return [
                'http_status' => 200,
                'message' => 'تم إغلاق الدورة المحاسبية',
                'overridden' => $overridden,
                'blockers' => $overridden ? $blockers : null,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{unreconciled_inventory_count:int,unreconciled_inventory:array,pending_journal_count:int,pending_journals:array} */
    public function findBlockers(PDO $db, int $tenantId, string $startDate, string $endDate): array
    {
        $start = (new DateTimeImmutable($startDate))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $endExclusive = (new DateTimeImmutable($endDate))->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
        $startDay = substr($start, 0, 10);
        $endDay = substr($endExclusive, 0, 10);

        $inventoryStmt = $db->prepare(
            'SELECT bp.branch_id, bp.product_id
             FROM branch_products bp
             WHERE bp.tenant_id = ? AND COALESCE(bp.gl_reconciled, 0) = 0
               AND EXISTS (
                   SELECT 1 FROM inventory_transactions it
                   WHERE it.tenant_id = bp.tenant_id
                     AND it.product_id = bp.product_id
                     AND (it.branch_from = bp.branch_id OR it.branch_to = bp.branch_id)
                     AND it.movement_date >= ? AND it.movement_date < ?
               )
             ORDER BY bp.branch_id, bp.product_id'
        );
        $inventoryStmt->execute([$tenantId, $start, $endExclusive]);
        $unreconciled = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

        $journalStmt = $db->prepare(
            "SELECT id, entry_date, status, reference_type, reference_id
             FROM journal_entries
             WHERE tenant_id = ? AND (status = 'draft' OR status IS NULL)
               AND entry_date >= ? AND entry_date < ?
             ORDER BY entry_date, id"
        );
        $journalStmt->execute([$tenantId, $startDay, $endDay]);
        $pending = $journalStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'unreconciled_inventory_count' => count($unreconciled),
            'unreconciled_inventory' => $unreconciled,
            'pending_journal_count' => count($pending),
            'pending_journals' => $pending,
        ];
    }
}
