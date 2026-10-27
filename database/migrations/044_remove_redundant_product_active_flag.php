<?php

declare(strict_types=1);

/**
 * Removes the unused duplicate products.is_active_product flag.
 */
final class RemoveRedundantProductActiveFlag
{
    private const COLUMN = 'is_active_product';
    private const INDEX = 'idx_is_active_product';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        if (!$this->columnExists()) {
            return;
        }

        $this->assertColumnMetadata();
        $this->assertValuesMatchActive();
        $this->assertColumnHasNoForeignKey();
        $this->assertNoSchemaObjectReferences();
        $this->assertOnlyExpectedIndexExists();

        if ($this->indexExists()) {
            $this->pdo->exec(
                'ALTER TABLE `products` DROP INDEX `' . self::INDEX . '`, DROP COLUMN `' . self::COLUMN . '`'
            );
        } else {
            $this->pdo->exec('ALTER TABLE `products` DROP COLUMN `' . self::COLUMN . '`');
        }

        if ($this->columnExists()) {
            throw new RuntimeException('Failed to remove products.is_active_product.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 044 is irreversible because the removed duplicate flag is not recoverable independently.'
        );
    }

    private function assertColumnMetadata(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['products', self::COLUMN]);
        $column = $statement->fetch(PDO::FETCH_ASSOC);
        if ($column === false
            || strtolower((string) $column['COLUMN_TYPE']) !== 'tinyint(1)'
            || $column['IS_NULLABLE'] !== 'YES'
            || !in_array($column['COLUMN_DEFAULT'], [null, 'NULL', '1', 1], true)
            || (string) $column['EXTRA'] !== ''
            || !in_array(
                (string) $column['COLUMN_COMMENT'],
                ['', 'Product is enabled/disabled for use (Active/Inactive)'],
                true
            )
        ) {
            throw new RuntimeException('Unexpected metadata for products.is_active_product; refusing to remove it.');
        }
    }

    private function assertValuesMatchActive(): void
    {
        $mismatches = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM `products`
             WHERE NOT (`is_active_product` <=> `active`)'
        )->fetchColumn();
        if ($mismatches !== 0) {
            throw new RuntimeException(
                "Refusing to remove products.is_active_product; {$mismatches} rows differ from products.active."
            );
        }
    }

    private function assertColumnHasNoForeignKey(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $statement->execute(['products', self::COLUMN]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException(
                'Refusing to remove products.is_active_product; it is referenced by a foreign key.'
            );
        }
    }

    private function assertOnlyExpectedIndexExists(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute(['products']);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $indexes[$index['INDEX_NAME']][] = $index;
        }

        foreach ($indexes as $name => $columns) {
            foreach ($columns as $column) {
                if ($column['COLUMN_NAME'] !== self::COLUMN) {
                    continue;
                }
                if ($name !== self::INDEX
                    || count($columns) !== 1
                    || (int) $column['SEQ_IN_INDEX'] !== 1
                ) {
                    throw new RuntimeException(
                        'Refusing to remove products.is_active_product; it participates in an unexpected index.'
                    );
                }
            }
        }
    }

    private function assertNoSchemaObjectReferences(): void
    {
        $definitions = array_merge(
            $this->pdo->query(
                'SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN),
            $this->pdo->query(
                'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN),
            $this->pdo->query(
                'SELECT cc.CHECK_CLAUSE
                 FROM information_schema.CHECK_CONSTRAINTS cc
                 JOIN information_schema.TABLE_CONSTRAINTS tc
                   ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
                  AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                 WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = \'products\''
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        foreach ($definitions as $definition) {
            if (preg_match(
                '/(?<![A-Za-z0-9_])' . preg_quote(self::COLUMN, '/') . '(?![A-Za-z0-9_])/i',
                (string) $definition
            ) === 1) {
                throw new RuntimeException(
                    'Refusing to remove products.is_active_product; a view or trigger references it.'
                );
            }
        }
    }

    private function columnExists(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['products', self::COLUMN]);

        return (int) $statement->fetchColumn() !== 0;
    }

    private function indexExists(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute(['products', self::INDEX]);

        return (int) $statement->fetchColumn() !== 0;
    }
}
