<?php

declare(strict_types=1);

/**
 * Adds the remaining unambiguous foreign keys explicitly requested in the schema review.
 */
final class AddRemainingRequestedForeignKeys
{
    private const REFERENCES = [
        ['suppliers', 'branch_id', 'branches', 'SET NULL'],
        ['product_units', 'tenant_id', 'tenants', 'RESTRICT'],
        ['sales_items', 'tenant_id', 'tenants', 'RESTRICT'],
        ['rate_limiting_tracking', 'rule_id', 'rate_limiting_rules', 'RESTRICT'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function up(): void
    {
        $this->alignSupplierBranchType();
        foreach (self::REFERENCES as [$table, $column, $parent, $action]) {
            $this->assertReferenceIsValid($table, $column, $parent, $action);
        }

        foreach (self::REFERENCES as [$table, $column, $parent, $action]) {
            $existingTarget = $this->foreignKeyTarget($table, $column);
            if ($existingTarget === $parent) {
                continue;
            }
            if ($existingTarget !== null) {
                throw new RuntimeException(
                    "Unexpected foreign key on {$table}.{$column} referencing {$existingTarget}."
                );
            }

            $this->pdo->exec(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier('fk043_' . $table . '_' . $column),
                $this->quoteIdentifier($column),
                $this->quoteIdentifier($parent),
                $action
            ));
        }

        foreach (self::REFERENCES as [$table, $column, $parent]) {
            if ($this->foreignKeyTarget($table, $column) !== $parent) {
                throw new RuntimeException("Failed to add foreign key for {$table}.{$column}.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Migration 043 is forward-only to preserve referential integrity.');
    }

    private function alignSupplierBranchType(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT source.COLUMN_TYPE, source.IS_NULLABLE, source.COLUMN_DEFAULT,
                    source.EXTRA, source.COLUMN_COMMENT, target.COLUMN_TYPE AS TARGET_TYPE
             FROM information_schema.COLUMNS source
             JOIN information_schema.COLUMNS target
               ON target.TABLE_SCHEMA = source.TABLE_SCHEMA
              AND target.TABLE_NAME = ?
              AND target.COLUMN_NAME = ?
             WHERE source.TABLE_SCHEMA = DATABASE()
               AND source.TABLE_NAME = ?
               AND source.COLUMN_NAME = ?'
        );
        $statement->execute(['branches', 'id', 'suppliers', 'branch_id']);
        $column = $statement->fetch(PDO::FETCH_ASSOC);
        if ($column === false) {
            throw new RuntimeException('Missing suppliers.branch_id or branches.id.');
        }

        $sourceType = strtolower((string) $column['COLUMN_TYPE']);
        $targetType = strtolower((string) $column['TARGET_TYPE']);
        if ($sourceType === $targetType) {
            return;
        }
        if (!preg_match('/^int(?:\(\d+\))? unsigned$/', $sourceType)
            || !preg_match('/^int(?:\(\d+\))?$/', $targetType)
            || $column['IS_NULLABLE'] !== 'YES'
            || !($column['COLUMN_DEFAULT'] === null
                || strtoupper((string) $column['COLUMN_DEFAULT']) === 'NULL')
            || (string) $column['EXTRA'] !== ''
        ) {
            throw new RuntimeException(
                "Unexpected type or metadata for suppliers.branch_id ({$sourceType}) and branches.id ({$targetType})."
            );
        }

        $this->pdo->exec(
            'ALTER TABLE `suppliers`
             MODIFY COLUMN `branch_id` INT NULL DEFAULT NULL COMMENT '
            . $this->pdo->quote((string) $column['COLUMN_COMMENT'])
        );
    }

    private function assertReferenceIsValid(
        string $table,
        string $column,
        string $parent,
        string $action
    ): void {
        $source = $this->columnMetadata($table, $column);
        $target = $this->columnMetadata($parent, 'id');
        if ($source === null || $target === null) {
            throw new RuntimeException("Missing column for {$table}.{$column} -> {$parent}.id.");
        }
        if (strtolower((string) $source['COLUMN_TYPE']) !== strtolower((string) $target['COLUMN_TYPE'])) {
            throw new RuntimeException(
                "Incompatible reference types for {$table}.{$column} and {$parent}.id."
            );
        }
        if ($action === 'SET NULL' && $source['IS_NULLABLE'] !== 'YES') {
            throw new RuntimeException("ON DELETE SET NULL requires nullable {$table}.{$column}.");
        }

        $existingTarget = $this->foreignKeyTarget($table, $column);
        if ($existingTarget !== null && $existingTarget !== $parent) {
            throw new RuntimeException(
                "Unexpected foreign key on {$table}.{$column} referencing {$existingTarget}."
            );
        }

        $orphans = $this->pdo->query(sprintf(
            'SELECT child.id, child.%s AS reference_id
             FROM %s child
             LEFT JOIN %s parent ON parent.id = child.%s
             WHERE child.%s IS NOT NULL AND parent.id IS NULL
             LIMIT 1',
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($parent),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($column)
        ))->fetch(PDO::FETCH_ASSOC);
        if ($orphans !== false) {
            throw new RuntimeException(
                "Orphan reference in {$table}.{$column}: row {$orphans['id']} references missing {$parent} {$orphans['reference_id']}."
            );
        }
    }

    private function columnMetadata(string $table, string $column): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        $metadata = $statement->fetch(PDO::FETCH_ASSOC);

        return $metadata === false ? null : $metadata;
    }

    private function foreignKeyTarget(string $table, string $column): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1'
        );
        $statement->execute([$table, $column]);
        $target = $statement->fetchColumn();

        return $target === false ? null : (string) $target;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
