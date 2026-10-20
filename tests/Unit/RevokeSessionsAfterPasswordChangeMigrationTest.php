<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../database/migrations/031_revoke_sessions_after_password_change.php';

final class RevokeSessionsAfterPasswordChangeMigrationTest extends TestCase
{
    public function testMigrationAddsAndRemovesTheTokenVersionColumnIdempotently(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $db->exec('INSERT INTO users (id) VALUES (1)');
        $migration = new RevokeSessionsAfterPasswordChange($db);

        $migration->up();
        $migration->up();
        $version = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
        $tokenVersion = array_values(array_filter(
            $version,
            static fn (array $column): bool => $column['name'] === 'token_version'
        ));
        self::assertCount(1, $tokenVersion);
        self::assertSame('0', (string) $db->query(
            'SELECT token_version FROM users LIMIT 1'
        )->fetchColumn());

        $migration->down();
        $columnsAfterRollback = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([], array_values(array_filter(
            $columnsAfterRollback,
            static fn (array $column): bool => $column['name'] === 'token_version'
        )));
    }
}
