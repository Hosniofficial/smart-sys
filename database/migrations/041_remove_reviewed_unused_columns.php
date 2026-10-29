<?php

declare(strict_types=1);

/**
 * Removes reviewed legacy columns after verifying they contain no data or dependencies.
 */
final class RemoveReviewedUnusedColumns
{
    public const REQUIRES_BACKUP_CONFIRMATION = true;

    private const COLUMNS = [
        'account_device_associations' => [
            'first_associated_at',
            'last_associated_at',
            'association_count',
            'blocked_reason',
        ],
        'cashier_sessions' => ['close_reason'],
        'device_fingerprints' => ['associated_accounts', 'last_seen'],
        'product_activation_log' => ['event_description'],
        'subscriptions' => ['cancelled_at'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function up(): void
    {
        $columnsByTable = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    continue;
                }

                $this->assertColumnIsUnused($table, $column);
                $columnsByTable[$table][] = $column;
            }
        }

        foreach ($columnsByTable as $table => $columns) {
            $dropColumns = array_map(
                fn (string $column): string => 'DROP COLUMN ' . $this->quoteIdentifier($column),
                $columns
            );
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s %s',
                $this->quoteIdentifier($table),
                implode(', ', $dropColumns)
            ));
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->columnExists($table, $column)) {
                    throw new RuntimeException("Failed to remove reviewed column {$table}.{$column}.");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Migration 041 is irreversible because removed columns may contain historical data.');
    }

    private function assertColumnIsUnused(string $table, string $column): void
    {
        $metadata = $this->pdo->prepare(
            'SELECT EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $metadata->execute([$table, $column]);
        $extra = (string) $metadata->fetchColumn();
        if (preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', $extra) === 1) {
            throw new RuntimeException("Refusing to remove generated column {$table}.{$column}.");
        }

        $nonNullValues = (int) $this->pdo->query(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column)
        ))->fetchColumn();
        if ($nonNullValues !== 0) {
            throw new RuntimeException(
                "Refusing to remove {$table}.{$column}; {$nonNullValues} non-NULL values exist."
            );
        }

        $index = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $index->execute([$table, $column]);
        if ((int) $index->fetchColumn() !== 0) {
            throw new RuntimeException("Refusing to remove indexed column {$table}.{$column}.");
        }

        $foreignKey = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $foreignKey->execute([$table, $column]);
        if ((int) $foreignKey->fetchColumn() !== 0) {
            throw new RuntimeException("Refusing to remove foreign-key column {$table}.{$column}.");
        }

        $this->assertNoSchemaObjectReferences($table, $column);
    }

    private function assertNoSchemaObjectReferences(string $table, string $column): void
    {
        $definitions = [];
        foreach ([
            [
                'object' => 'view',
                'query' => 'SELECT TABLE_NAME, VIEW_DEFINITION
                            FROM information_schema.VIEWS
                            WHERE TABLE_SCHEMA = DATABASE()',
            ],
            [
                'object' => 'trigger',
                'query' => 'SELECT TRIGGER_NAME, ACTION_STATEMENT
                            FROM information_schema.TRIGGERS
                            WHERE TRIGGER_SCHEMA = DATABASE()',
            ],
            [
                'object' => 'routine',
                'query' => 'SELECT ROUTINE_NAME, ROUTINE_DEFINITION
                            FROM information_schema.ROUTINES
                            WHERE ROUTINE_SCHEMA = DATABASE()',
            ],
            [
                'object' => 'event',
                'query' => 'SELECT EVENT_NAME, EVENT_DEFINITION
                            FROM information_schema.EVENTS
                            WHERE EVENT_SCHEMA = DATABASE()',
            ],
        ] as $query) {
            $statement = $this->pdo->query($query['query']);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $definition]) {
                if ($definition === null) {
                    throw new RuntimeException(
                        "Cannot verify whether {$query['object']} {$name} references {$table}.{$column}."
                    );
                }
                $definitions[] = [
                    'object' => $query['object'],
                    'name' => (string) $name,
                    'definition' => (string) $definition,
                ];
            }
        }

        $checkConstraints = $this->pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS cc
             JOIN information_schema.TABLE_CONSTRAINTS tc
               ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
              AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?'
        );
        $checkConstraints->execute([$table]);
        foreach ($checkConstraints->fetchAll(PDO::FETCH_NUM) as [$name, $definition]) {
            $definitions[] = [
                'object' => 'check constraint',
                'name' => (string) $name,
                'definition' => (string) $definition,
            ];
        }

        $columnPattern = '/(?<![A-Za-z0-9_])' . preg_quote($column, '/') . '(?![A-Za-z0-9_])/i';
        foreach ($definitions as $entry) {
            if (preg_match($columnPattern, $entry['definition']) === 1) {
                throw new RuntimeException(
                    "Refusing to remove {$table}.{$column}; {$entry['object']} {$entry['name']} references it."
                );
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() !== 0;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
