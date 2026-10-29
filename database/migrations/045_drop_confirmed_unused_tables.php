<?php

declare(strict_types=1);

/**
 * Drops empty, unused schema scaffolds after checking runtime dependencies.
 */
final class DropConfirmedUnusedTables
{
    public const REQUIRES_BACKUP_CONFIRMATION = true;

    private const TABLES = [
        'documents',
        'payment_transactions',
        'user_preferences',
        'account_device_associations',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $existingTables = [];
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $existingTables[] = $table;
            $this->assertTableIsEmpty($table);
        }

        if ($existingTables === []) {
            return;
        }

        $this->assertNoIncomingForeignKeys($existingTables);
        $this->assertNoSchemaObjectReferences();

        $tables = implode(', ', array_map(
            static fn (string $table): string => '`' . $table . '`',
            $existingTables
        ));
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $tables);

        foreach ($existingTables as $table) {
            if ($this->tableExists($table)) {
                throw new RuntimeException("Failed to remove unused table {$table}.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 045 is irreversible because the removed table definitions and data are not reconstructed.'
        );
    }

    private function assertTableIsEmpty(string $table): void
    {
        $row = $this->pdo->query(
            'SELECT 1 FROM `' . $table . '` LIMIT 1'
        )->fetchColumn();
        if ($row !== false) {
            throw new RuntimeException(
                "Refusing to remove {$table}; it contains data that requires review."
            );
        }
    }

    private function assertNoIncomingForeignKeys(array $tables): void
    {
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME IN (' . $placeholders . ')
               AND TABLE_NAME NOT IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge($tables, $tables));
        $references = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($references !== []) {
            $reference = $references[0];
            throw new RuntimeException(
                "Refusing to remove {$reference['REFERENCED_TABLE_NAME']}; "
                . "{$reference['TABLE_NAME']}.{$reference['CONSTRAINT_NAME']} references it."
            );
        }
    }

    private function assertNoSchemaObjectReferences(): void
    {
        $definitions = [
            [
                'object' => 'view',
                'rows' => $this->pdo->query(
                    'SELECT TABLE_NAME AS object_name, VIEW_DEFINITION AS definition
                     FROM information_schema.VIEWS
                     WHERE TABLE_SCHEMA = DATABASE()'
                )->fetchAll(PDO::FETCH_ASSOC),
            ],
            [
                'object' => 'trigger',
                'rows' => $this->pdo->query(
                    'SELECT TRIGGER_NAME AS object_name, ACTION_STATEMENT AS definition
                     FROM information_schema.TRIGGERS
                     WHERE TRIGGER_SCHEMA = DATABASE()'
                )->fetchAll(PDO::FETCH_ASSOC),
            ],
            [
                'object' => 'routine',
                'rows' => $this->pdo->query(
                    'SELECT ROUTINE_NAME AS object_name, ROUTINE_DEFINITION AS definition
                     FROM information_schema.ROUTINES
                     WHERE ROUTINE_SCHEMA = DATABASE()'
                )->fetchAll(PDO::FETCH_ASSOC),
            ],
            [
                'object' => 'event',
                'rows' => $this->pdo->query(
                    'SELECT EVENT_NAME AS object_name, EVENT_DEFINITION AS definition
                     FROM information_schema.EVENTS
                     WHERE EVENT_SCHEMA = DATABASE()'
                )->fetchAll(PDO::FETCH_ASSOC),
            ],
        ];

        foreach ($definitions as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['definition'] === null) {
                    throw new RuntimeException(
                        "Cannot verify whether {$group['object']} {$row['object_name']} references a target table."
                    );
                }

                foreach (self::TABLES as $table) {
                    if (preg_match(
                        '/(?<![A-Za-z0-9_])' . preg_quote($table, '/') . '(?![A-Za-z0-9_])/i',
                        (string) $row['definition']
                    ) === 1) {
                        throw new RuntimeException(
                            "Refusing to remove {$table}; {$group['object']} {$row['object_name']} references it."
                        );
                    }
                }
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() !== 0;
    }
}
