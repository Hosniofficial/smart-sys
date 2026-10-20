<?php

declare(strict_types=1);

/** Mark the canonical shared finance role as a system role without changing its permissions. */
final class MarkFinanceOfficerAsSystemRole
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $stmt = $this->pdo->query(
            "SELECT id
             FROM roles
             WHERE tenant_id IS NULL
               AND LOWER(name) = 'finance_officer'"
        );
        $roleIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($roleIds) !== 1) {
            throw new RuntimeException(
                'Expected exactly one shared global finance_officer role; no changes were made.'
            );
        }

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $update = $this->pdo->prepare(
                'UPDATE roles SET is_system_role = 1 WHERE id = ? AND tenant_id IS NULL'
            );
            $update->execute([$roleIds[0]]);

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        echo "Marked the shared finance_officer role as a system role; permissions were not changed.\n";
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 020 is forward-only; reverting the system-role flag requires an explicit review.'
        );
    }
}
