<?php

declare(strict_types=1);

/** Reconcile token uniqueness and notification JSON validation across existing schemas. */
final class ReconcileEmailTokenAndNotificationJsonConstraints
{
    public const REQUIRES_BACKUP_CONFIRMATION = true;

    private const TOKEN_INDEX = 'token';
    private const TEMP_TOKEN_INDEX = 'smartsys_tmp_token_prefix_uniq';
    private const NOTIFICATION_CHECK = 'chk_notifications_data_json';

    public function __construct(private PDO $pdo)
    {
    }

    public function up(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Migration 051 requires a MySQL-compatible database.');
        }

        $notificationDataType = $this->columnType('notifications', 'data');
        $this->columnType('email_verification_tokens', 'token');

        $hasNotificationCheck = $this->hasNotificationJsonCheck();
        $tokenIndex = $this->indexRows(self::TOKEN_INDEX);
        $temporaryIndex = $this->indexRows(self::TEMP_TOKEN_INDEX);
        $tokenIndexIsCorrect = $this->isExpectedTokenIndex($tokenIndex);
        $temporaryIndexIsCorrect = $this->isExpectedTokenIndex($temporaryIndex);

        if ($temporaryIndex !== [] && !$temporaryIndexIsCorrect) {
            throw new RuntimeException(
                'Migration 051 found an unexpected temporary token index; manual review is required.'
            );
        }

        if (!$hasNotificationCheck && strtolower($notificationDataType) !== 'json') {
            $invalidJson = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM notifications
                 WHERE data IS NOT NULL AND JSON_VALID(data) = 0'
            )->fetchColumn();
            if ($invalidJson > 0) {
                throw new RuntimeException(
                    'Migration 051 cannot add notification JSON validation while invalid data exists.'
                );
            }
        }

        if (!$tokenIndexIsCorrect) {
            $duplicatePrefixes = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT LEFT(token, 255) AS token_prefix
                    FROM email_verification_tokens
                    WHERE token IS NOT NULL
                    GROUP BY LEFT(token, 255)
                    HAVING COUNT(*) > 1
                 ) duplicate_token_prefixes'
            )->fetchColumn();
            if ($duplicatePrefixes > 0) {
                throw new RuntimeException(
                    'Migration 051 cannot enforce token uniqueness while duplicate token prefixes exist.'
                );
            }
        }

        if (!$hasNotificationCheck && strtolower($notificationDataType) !== 'json') {
            $this->assertConstraintNameAvailable(self::NOTIFICATION_CHECK);
            $this->pdo->exec(
                'ALTER TABLE `notifications`
                 ADD CONSTRAINT `' . self::NOTIFICATION_CHECK . '` CHECK (JSON_VALID(`data`))'
            );
        }

        $this->ensureTokenIndex($tokenIndex, $temporaryIndex, $tokenIndexIsCorrect, $temporaryIndexIsCorrect);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 051 is forward-only because it reconciles constraints that may predate the migration.'
        );
    }

    private function columnType(string $table, string $column): string
    {
        $statement = $this->pdo->prepare(
            'SELECT DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        $type = $statement->fetchColumn();
        if ($type === false) {
            throw new RuntimeException("Migration 051 requires {$table}.{$column}.");
        }

        return (string) $type;
    }

    private function hasNotificationJsonCheck(): bool
    {
        $checks = $this->pdo->query(
            "SELECT cc.CHECK_CLAUSE
             FROM information_schema.TABLE_CONSTRAINTS tc
             JOIN information_schema.CHECK_CONSTRAINTS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
               AND tc.TABLE_NAME = 'notifications'
               AND tc.CONSTRAINT_TYPE = 'CHECK'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($checks as $check) {
            if ($this->normalizeCheck((string) $check) === 'json_validdata') {
                return true;
            }
        }

        return false;
    }

    private function normalizeCheck(string $check): string
    {
        return strtolower((string) preg_replace('/[\s`()]/', '', $check));
    }

    private function assertConstraintNameAvailable(string $name): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?'
        );
        $statement->execute(['notifications', $name]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new RuntimeException(
                'Migration 051 found its notification constraint name in use by a different constraint.'
            );
        }
    }

    private function indexRows(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX'
        );
        $statement->execute(['email_verification_tokens', $name]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isExpectedTokenIndex(array $rows): bool
    {
        return count($rows) === 1
            && (int) $rows[0]['NON_UNIQUE'] === 0
            && (int) $rows[0]['SEQ_IN_INDEX'] === 1
            && $rows[0]['COLUMN_NAME'] === 'token'
            && (int) $rows[0]['SUB_PART'] === 255
            && strtoupper((string) $rows[0]['INDEX_TYPE']) === 'BTREE';
    }

    private function ensureTokenIndex(
        array $tokenIndex,
        array $temporaryIndex,
        bool $tokenIndexIsCorrect,
        bool $temporaryIndexIsCorrect
    ): void {
        if ($tokenIndexIsCorrect) {
            if ($temporaryIndexIsCorrect) {
                $this->pdo->exec(
                    'ALTER TABLE `email_verification_tokens` DROP INDEX `' . self::TEMP_TOKEN_INDEX . '`'
                );
            }
            return;
        }

        if (!$temporaryIndexIsCorrect) {
            $this->pdo->exec(
                'ALTER TABLE `email_verification_tokens`
                 ADD UNIQUE INDEX `' . self::TEMP_TOKEN_INDEX . '` (`token`(255)) USING BTREE'
            );
        }

        if ($tokenIndex !== []) {
            $this->pdo->exec(
                'ALTER TABLE `email_verification_tokens` DROP INDEX `' . self::TOKEN_INDEX . '`'
            );
        }

        $this->pdo->exec(
            'ALTER TABLE `email_verification_tokens`
             ADD UNIQUE INDEX `' . self::TOKEN_INDEX . '` (`token`(255)) USING BTREE'
        );
        $this->pdo->exec(
            'ALTER TABLE `email_verification_tokens` DROP INDEX `' . self::TEMP_TOKEN_INDEX . '`'
        );
    }
}
