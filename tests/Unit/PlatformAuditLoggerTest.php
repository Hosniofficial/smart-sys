<?php

declare(strict_types=1);

use App\Services\PlatformAuditLogger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PlatformAuditLoggerTest extends TestCase
{
    public function testRecordsAuthenticatedPlatformActionWithTenantAndDetails(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT,
                entity TEXT,
                entity_id INTEGER,
                details TEXT,
                tenant_id INTEGER,
                ip_address TEXT,
                created_at TEXT
            )
        ");
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/admin/tenants/12/status')
            ->withAttribute('user', ['id' => 7]);

        (new PlatformAuditLogger($db))->record(
            $request,
            'tenant_status_changed',
            12,
            ['from_status' => 'active', 'to_status' => 'inactive', 'reason' => 'Requested pause']
        );

        $row = $db->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('7', (string) $row['user_id']);
        self::assertSame('tenant_status_changed', $row['action']);
        self::assertSame('platform_admin', $row['entity']);
        self::assertSame('12', (string) $row['entity_id']);
        self::assertNull($row['tenant_id']);
        self::assertSame('127.0.0.1', $row['ip_address']);
        self::assertSame(
            ['from_status' => 'active', 'to_status' => 'inactive', 'reason' => 'Requested pause'],
            json_decode($row['details'], true)
        );
    }

    public function testRejectsAuditEventWithoutAuthenticatedActor(): void
    {
        $db = new PDO('sqlite::memory:');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/admin/tenants/12/status');

        $this->expectException(RuntimeException::class);
        (new PlatformAuditLogger($db))->record($request, 'tenant_status_changed', 12, []);
    }
}
