<?php

declare(strict_types=1);

use App\Handlers\SalesHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SalesPaymentIdempotencyKeyTest extends TestCase
{
    public function testSalePaymentRejectsMissingIdempotencyKey(): void
    {
        $response = $this->requestPayment([]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(
            'Idempotency-Key',
            (string) $response->getBody()
        );
    }

    public function testSalePaymentRejectsAnOversizedIdempotencyKey(): void
    {
        $response = $this->requestPayment(['Idempotency-Key' => str_repeat('k', 129)]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(
            'أطول من الحد المسموح',
            (string) $response->getBody()
        );
    }

    public function testSalePaymentRejectsReusingAKeyForDifferentData(): void
    {
        $db = $this->createDatabase();
        $db->exec('CREATE TABLE payment_idempotency_keys (
            tenant_id INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL,
            payment_id INTEGER NOT NULL,
            response_json TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            PRIMARY KEY (tenant_id, idempotency_key)
        )');
        $db->prepare(
            'INSERT INTO payment_idempotency_keys
                (tenant_id, idempotency_key, payment_id, response_json, expires_at)
             VALUES (1, ?, 9, ?, ?)'
        )->execute([
            'existing-payment-key',
            json_encode([
                'status' => 'success',
                'data' => ['payment_id' => 9],
                '_request_fingerprint' => str_repeat('0', 64),
            ], JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s', time() + 3600),
        ]);

        $response = $this->requestPayment(['Idempotency-Key' => 'existing-payment-key'], $db);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString(
            'مستخدم لبيانات دفعة مختلفة',
            (string) $response->getBody()
        );
    }

    private function requestPayment(array $headers, ?PDO $db = null): \Psr\Http\Message\ResponseInterface
    {
        $db = $db ?? $this->createDatabase();
        $handler = new SalesHandler($db);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales/add-payment')
            ->withAttribute('tenant_id', 1)
            ->withParsedBody([
                'sale_id' => 1,
                'amount' => 10,
                'payment_date' => '2026-10-15',
                'payment_method_id' => 1,
            ]);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $handler->addSalesPayment($request, (new ResponseFactory())->createResponse());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        return $db;
    }
}
