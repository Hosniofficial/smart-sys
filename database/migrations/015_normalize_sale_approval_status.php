<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Normalize legacy sale-approval statuses to the single canonical value. */
final class NormalizeSaleApprovalStatus
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sales
             SET status = 'pending_approval'
             WHERE LOWER(TRIM(status)) IN ('pending', 'awaiting_approval')"
        );
        $stmt->execute();

        echo sprintf(
            "Normalized %d legacy sale-approval status(es) to pending_approval.\n",
            $stmt->rowCount()
        );
    }

    public function down(): void
    {
        echo "No rollback: the former statuses have ambiguous meanings and are not restored.\n";
    }
}
