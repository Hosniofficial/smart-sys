<?php

declare(strict_types=1);

use App\Services\JwtBlacklistService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class JwtBlacklistServiceTest extends TestCase
{
    public function testBlacklistLookupIgnoresExpiredRowsWithoutPruningOnRequestPath(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $db->exec('CREATE TABLE jwt_blacklist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_id TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL
        )');

        $secret = 'unit-test-secret-with-sufficient-length';
        $now = time();
        $token = JWT::encode([
            'jti' => 'active-revoked-token',
            'iat' => $now - 60,
            'exp' => $now + 3600,
        ], $secret, 'HS256');
        $stmt = $db->prepare('INSERT INTO jwt_blacklist (token_id, expires_at) VALUES (?, ?)');
        $stmt->execute(['active-revoked-token', date('Y-m-d H:i:s', $now + 3600)]);
        $stmt->execute(['expired-token-row', date('Y-m-d H:i:s', $now - 60)]);

        $service = new JwtBlacklistService($db, $secret);

        self::assertTrue($service->isBlacklisted($token));
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM jwt_blacklist')->fetchColumn());
        self::assertSame(1, $service->cleanupExpiredTokens());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM jwt_blacklist')->fetchColumn());
    }
}
