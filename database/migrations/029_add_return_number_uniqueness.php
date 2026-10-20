<?php

declare(strict_types=1);

/**
 * Enforce tenant-scoped uniqueness for return numbers.
 * Existing duplicates stop the migration so they can be reviewed before indexing.
 */
final class AddReturnNumberUniqueness
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
                SELECT tenant_id, return_number
                FROM returns
                GROUP BY tenant_id, return_number
                HAVING COUNT(*) > 1
            ) duplicate_return_numbers"
        )->fetchColumn();
        if ($duplicateCount > 0) {
            throw new RuntimeException(
                'Cannot add return number index: duplicate tenant/return-number pairs already exist.'
            );
        }

        if ($this->indexExists('uq_returns_tenant_return_number')) {
            return;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                'CREATE UNIQUE INDEX uq_returns_tenant_return_number
                 ON returns (tenant_id, return_number)'
            );
        } else {
            $this->pdo->exec(
                'ALTER TABLE returns
                 ADD UNIQUE INDEX uq_returns_tenant_return_number (tenant_id, return_number)'
            );
        }
    }

    public function down(): void
    {
        if (!$this->indexExists('uq_returns_tenant_return_number')) {
            return;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('DROP INDEX uq_returns_tenant_return_number');
        } else {
            $this->pdo->exec('ALTER TABLE returns DROP INDEX uq_returns_tenant_return_number');
        }
    }

    private function indexExists(string $index): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA index_list(returns)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['name'] === $index) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'returns' AND index_name = ?"
        );
        $stmt->execute([$index]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
