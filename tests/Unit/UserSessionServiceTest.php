<?php

declare(strict_types=1);

use App\Services\UserSessionService;
use PHPUnit\Framework\TestCase;

final class UserSessionServiceTest extends TestCase
{
    public function testChangingPasswordIncrementsVersionAndRevokesEveryRefreshToken(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                password TEXT NOT NULL,
                token_version INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT
            )'
        );
        $db->exec(
            'CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                is_revoked INTEGER NOT NULL DEFAULT 0
            )'
        );
        $db->exec(
            "INSERT INTO users (id, tenant_id, password, token_version) VALUES (4, 8, 'old-hash', 0)"
        );
        $db->exec(
            'INSERT INTO refresh_tokens (user_id, is_revoked) VALUES (4, 0), (4, 0)'
        );

        (new UserSessionService($db))->changePassword(4, 8, 'new-password-123');

        $user = $db->query('SELECT password, token_version FROM users WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $user['token_version']);
        self::assertTrue(password_verify('new-password-123', $user['password']));
        self::assertFalse(password_verify('old-hash', $user['password']));
        self::assertSame(2, (int) $db->query(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = 4 AND is_revoked = 1'
        )->fetchColumn());
        self::assertTrue((new UserSessionService($db))->isCurrentTokenVersion(4, 8, 1));
        self::assertFalse((new UserSessionService($db))->isCurrentTokenVersion(4, 8, 0));
    }

    public function testPasswordAndRevocationsRollbackTogetherWhenRefreshTokensTableIsUnavailable(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                password TEXT NOT NULL,
                token_version INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT
            )'
        );
        $db->exec(
            "INSERT INTO users (id, tenant_id, password, token_version) VALUES (4, 8, 'old-hash', 0)"
        );

        try {
            (new UserSessionService($db))->changePassword(4, 8, 'new-password-123');
            self::fail('Expected the refresh-token update to fail.');
        } catch (PDOException) {
            $user = $db->query('SELECT password, token_version FROM users WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
            self::assertSame('old-hash', $user['password']);
            self::assertSame(0, (int) $user['token_version']);
        }
    }
}
