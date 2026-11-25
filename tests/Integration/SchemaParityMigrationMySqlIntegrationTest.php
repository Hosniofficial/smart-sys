<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2)
    . '/database/migrations/051_reconcile_email_token_and_notification_json_constraints.php';

use PHPUnit\Framework\TestCase;

final class SchemaParityMigrationMySqlIntegrationTest extends TestCase
{
    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_MIGRATION_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_MYSQL_MIGRATION_TESTS=1 to create an isolated temporary MySQL schema.'
            );
        }

        \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            self::fail('Refusing migration integration tests unless DB_HOST is local.');
        }

        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
        self::$schema = 'smartsys_schema_parity_it_' . bin2hex(random_bytes(8));
        self::$server = new mysqli($host, $username, $password, '', $port);
        self::$server->set_charset('utf8mb4');
        self::$server->query(
            'CREATE DATABASE `' . self::$schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::$server->select_db(self::$schema);

        try {
            self::$db = new PDO(
                "mysql:host={$host};port={$port};dbname=" . self::$schema . ';charset=utf8mb4',
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (Throwable $error) {
            self::dropTemporarySchema();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropTemporarySchema();
    }

    protected function setUp(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec('DROP TABLE IF EXISTS notifications');
        self::$db->exec('DROP TABLE IF EXISTS email_verification_tokens');
        self::$db->exec(
            'CREATE TABLE email_verification_tokens (
                token TEXT NULL,
                token_hash VARCHAR(255) NOT NULL,
                UNIQUE KEY token_hash (token_hash)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::$db->exec(
            'CREATE TABLE notifications (
                data LONGTEXT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function testRejectsDuplicateTokenPrefixesBeforeChangingEitherConstraint(): void
    {
        $this->insertToken(str_repeat('x', 255) . 'a', str_repeat('a', 64));
        $this->insertToken(str_repeat('x', 255) . 'b', str_repeat('b', 64));

        try {
            (new ReconcileEmailTokenAndNotificationJsonConstraints(self::$db))->up();
            self::fail('Expected duplicate token prefixes to stop the migration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('duplicate token prefixes', $error->getMessage());
        }

        self::assertSame(0, $this->tokenIndexCount());
        self::assertSame(0, $this->notificationJsonCheckCount());
    }

    public function testRejectsInvalidNotificationJsonBeforeChangingEitherConstraint(): void
    {
        self::$db->prepare('INSERT INTO notifications (data) VALUES (?)')
            ->execute(['{invalid-json']);

        try {
            (new ReconcileEmailTokenAndNotificationJsonConstraints(self::$db))->up();
            self::fail('Expected invalid notification JSON to stop the migration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('invalid data exists', $error->getMessage());
        }

        self::assertSame(0, $this->tokenIndexCount());
        self::assertSame(0, $this->notificationJsonCheckCount());
    }

    public function testAddsExpectedConstraintsAndIsIdempotent(): void
    {
        $migration = new ReconcileEmailTokenAndNotificationJsonConstraints(self::$db);
        $migration->up();
        $migration->up();

        $index = self::$db->query(
            "SELECT NON_UNIQUE, SUB_PART, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_verification_tokens'
               AND INDEX_NAME = 'token'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $index['NON_UNIQUE']);
        self::assertSame(255, (int) $index['SUB_PART']);
        self::assertSame('BTREE', strtoupper((string) $index['INDEX_TYPE']));
        self::assertSame(1, $this->notificationJsonCheckCount());
    }

    public function testReplacesAnExistingTokenIndexWithTheExpectedPrefix(): void
    {
        self::$db->exec(
            'ALTER TABLE `email_verification_tokens`
             ADD UNIQUE INDEX `token` (`token`(100)) USING BTREE'
        );

        (new ReconcileEmailTokenAndNotificationJsonConstraints(self::$db))->up();

        $index = self::$db->query(
            "SELECT NON_UNIQUE, SUB_PART, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_verification_tokens'
               AND INDEX_NAME = 'token'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $index['NON_UNIQUE']);
        self::assertSame(255, (int) $index['SUB_PART']);
        self::assertSame('BTREE', strtoupper((string) $index['INDEX_TYPE']));
        self::assertSame(0, $this->temporaryTokenIndexCount());
    }

    private function insertToken(string $token, string $tokenHash): void
    {
        self::$db->prepare(
            'INSERT INTO email_verification_tokens (token, token_hash) VALUES (?, ?)'
        )->execute([$token, $tokenHash]);
    }

    private function tokenIndexCount(): int
    {
        return (int) self::$db->query(
            "SELECT COUNT(DISTINCT INDEX_NAME)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_verification_tokens'
               AND INDEX_NAME IN ('token', 'smartsys_tmp_token_prefix_uniq')"
        )->fetchColumn();
    }

    private function temporaryTokenIndexCount(): int
    {
        return (int) self::$db->query(
            "SELECT COUNT(DISTINCT INDEX_NAME)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_verification_tokens'
               AND INDEX_NAME = 'smartsys_tmp_token_prefix_uniq'"
        )->fetchColumn();
    }

    private function notificationJsonCheckCount(): int
    {
        $checks = self::$db->query(
            "SELECT cc.CHECK_CLAUSE
             FROM information_schema.TABLE_CONSTRAINTS tc
             JOIN information_schema.CHECK_CONSTRAINTS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
               AND tc.TABLE_NAME = 'notifications'
               AND tc.CONSTRAINT_TYPE = 'CHECK'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $matching = array_filter(
            $checks,
            static fn(string $check): bool => strtolower((string) preg_replace(
                '/[\s`()]/',
                '',
                $check
            )) === 'json_validdata'
        );

        return count($matching);
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
