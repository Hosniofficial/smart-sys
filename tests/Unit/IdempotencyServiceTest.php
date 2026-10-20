<?php

declare(strict_types=1);

use App\Services\IdempotencyService;
use PHPUnit\Framework\TestCase;

final class IdempotencyServiceTest extends TestCase
{
    public function testClaimReservesKeyAndReturnsStoredResponseForDuplicateClaim(): void
    {
        $db = $this->createDatabase();
        $service = new IdempotencyService($db);
        $fingerprint = str_repeat('a', 64);

        $db->beginTransaction();
        self::assertNull($service->claimStrict(3, 'payment-key', $fingerprint));

        $response = [
            'status' => 'success',
            'data' => ['payment_id' => 72],
            '_request_fingerprint' => $fingerprint,
        ];
        $service->storeStrict(3, 'payment-key', 72, $response);

        self::assertSame($response, $service->claimStrict(3, 'payment-key', $fingerprint));
        $db->commit();
    }

    public function testClaimRequiresAnActiveTransaction(): void
    {
        $service = new IdempotencyService($this->createDatabase());

        $this->expectException(LogicException::class);
        $service->claimStrict(3, 'payment-key', str_repeat('a', 64));
    }

    public function testClaimReplacesAnExpiredReservation(): void
    {
        $db = $this->createDatabase();
        $db->prepare(
            'INSERT INTO payment_idempotency_keys
                (tenant_id, idempotency_key, payment_id, response_json, expires_at)
             VALUES (3, ?, 72, ?, ?)'
        )->execute([
            'payment-key',
            json_encode(['status' => 'success'], JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s', time() - 60),
        ]);
        $db->beginTransaction();

        $claimed = (new IdempotencyService($db))->claimStrict(
            3,
            'payment-key',
            str_repeat('a', 64)
        );

        self::assertNull($claimed);
        $db->commit();
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('
            CREATE TABLE payment_idempotency_keys (
                tenant_id INTEGER NOT NULL,
                idempotency_key TEXT NOT NULL,
                payment_id INTEGER NOT NULL,
                response_json TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                UNIQUE (tenant_id, idempotency_key)
            )
        ');

        return $db;
    }
}
