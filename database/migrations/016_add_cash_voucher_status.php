<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Add a retained lifecycle status to cash vouchers. */
final class AddCashVoucherStatus
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $column = $this->pdo->query("SHOW COLUMNS FROM cash_vouchers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($column) {
            echo "cash_vouchers.status already exists.\n";
            return;
        }

        $this->pdo->exec(
            "ALTER TABLE cash_vouchers
             ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'posted' AFTER type"
        );
        echo "Added posted/cancelled lifecycle status to cash_vouchers.\n";
    }

    public function down(): void
    {
        echo "No rollback: cancellation status is retained for audit history.\n";
    }
}
