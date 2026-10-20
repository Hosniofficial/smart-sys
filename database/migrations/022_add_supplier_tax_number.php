<?php

declare(strict_types=1);

/** Store the supplier tax number collected by supplier management forms. */
final class AddSupplierTaxNumber
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        if (!$this->columnExists()) {
            $this->pdo->exec('ALTER TABLE suppliers ADD COLUMN tax_number VARCHAR(50) NULL');
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 022 is forward-only; supplier tax numbers must be preserved.'
        );
    }

    private function columnExists(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA table_info(suppliers)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ($column['name'] === 'tax_number') {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'suppliers'
               AND column_name = 'tax_number'"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn() > 0;
    }
}
