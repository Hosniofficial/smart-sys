<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Add a persisted status for journal entries that have been reversed. */
final class AddReversedJournalEntryStatus
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'status'");
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            throw new RuntimeException('journal_entries.status was not found.');
        }

        $type = (string) ($column['Type'] ?? '');
        if (preg_match('/^enum\((.*)\)$/i', $type, $matches) !== 1) {
            if (preg_match('/^(varchar|char|text)/i', $type) === 1) {
                echo "Journal-entry status is not an enum; no schema change was required.\n";
                return;
            }
            throw new RuntimeException("Unsupported journal_entries.status type: {$type}");
        }

        $values = str_getcsv($matches[1], ',', "'", '\\');
        if (in_array('reversed', $values, true)) {
            echo "Journal-entry status already supports reversed.\n";
            return;
        }
        $values[] = 'reversed';

        $enumValues = implode(', ', array_map(
            static fn (string $value): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'",
            $values
        ));
        $nullable = strtoupper((string) ($column['Null'] ?? 'NO')) === 'YES';
        $nullClause = $nullable ? 'NULL' : 'NOT NULL';
        $default = $column['Default'] ?? null;
        $defaultClause = $default !== null
            ? ' DEFAULT ' . $this->pdo->quote((string) $default)
            : ($nullable ? ' DEFAULT NULL' : '');
        $comment = (string) ($column['Comment'] ?? '');
        $commentClause = $comment !== '' ? ' COMMENT ' . $this->pdo->quote($comment) : '';

        $this->pdo->exec(
            "ALTER TABLE journal_entries MODIFY COLUMN status ENUM({$enumValues}) " .
            "{$nullClause}{$defaultClause}{$commentClause}"
        );

        echo "Added reversed to journal_entries.status.\n";
    }

    public function down(): void
    {
        echo "No rollback: reversed journal-entry statuses are retained for audit history.\n";
    }
}
