<?php

declare(strict_types=1);

use App\Handlers\PurchasesHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class PurchaseCreateIdempotencyResponseTest extends TestCase
{
    public function testMatchingRetryReturnsExistingPurchase(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();

        $result = $this->invokeExistingResponse($handler, $response, 3, 7, 'request-key-1', str_repeat('a', 64));

        self::assertNotNull($result);
        self::assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        self::assertSame('success', $body['status']);
        self::assertSame(41, $body['data']['id']);
        self::assertSame('PUR-TEST-041', $body['data']['invoice_number']);
    }

    public function testSameKeyWithDifferentPayloadReturnsConflict(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();

        $result = $this->invokeExistingResponse($handler, $response, 3, 7, 'request-key-1', str_repeat('b', 64));

        self::assertNotNull($result);
        self::assertSame(409, $result->getStatusCode());
        self::assertSame('error', json_decode((string) $result->getBody(), true)['status']);
    }

    public function testAnotherUserCannotReplayExistingPurchaseResponse(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();

        $result = $this->invokeExistingResponse($handler, $response, 3, 8, 'request-key-1', str_repeat('a', 64));

        self::assertNotNull($result);
        self::assertSame(409, $result->getStatusCode());
    }

    public function testUnknownKeyDoesNotReturnAnExistingPurchase(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();

        self::assertNull($this->invokeExistingResponse(
            $handler,
            $response,
            3,
            7,
            'new-request-key',
            str_repeat('c', 64)
        ));
    }

    public function testInvoicePaymentRetryReturnsMatchingResponseWithoutExposingFingerprint(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();
        $cached = [
            'status' => 'success',
            'data' => ['payment_id' => 72],
            '_request_fingerprint' => str_repeat('a', 64),
        ];

        $result = $this->invokeExistingInvoicePaymentResponse($handler, $response, $cached, str_repeat('a', 64));

        self::assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        self::assertSame('success', $body['status']);
        self::assertSame(72, $body['data']['payment_id']);
        self::assertArrayNotHasKey('_request_fingerprint', $body);
    }

    public function testInvoicePaymentRetryWithDifferentPayloadReturnsConflict(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();
        $cached = [
            'status' => 'success',
            '_request_fingerprint' => str_repeat('a', 64),
        ];

        $result = $this->invokeExistingInvoicePaymentResponse($handler, $response, $cached, str_repeat('b', 64));

        self::assertSame(409, $result->getStatusCode());
        self::assertSame('error', json_decode((string) $result->getBody(), true)['status']);
    }

    public function testLegacyInvoicePaymentCacheWithoutFingerprintReturnsConflict(): void
    {
        [$handler, $response] = $this->handlerWithExistingPurchase();

        $result = $this->invokeExistingInvoicePaymentResponse(
            $handler,
            $response,
            ['status' => 'success'],
            str_repeat('a', 64)
        );

        self::assertSame(409, $result->getStatusCode());
    }

    private function invokeExistingResponse(
        PurchasesHandler $handler,
        Response $response,
        int $tenantId,
        int $userId,
        string $key,
        string $hash
    ): ?Response {
        $method = new ReflectionMethod(PurchasesHandler::class, 'existingPurchaseCreationResponse');
        $method->setAccessible(true);
        return $method->invoke($handler, $response, $tenantId, $userId, $key, $hash);
    }

    private function invokeExistingInvoicePaymentResponse(
        PurchasesHandler $handler,
        Response $response,
        array $cached,
        string $fingerprint
    ): Response {
        $method = new ReflectionMethod(PurchasesHandler::class, 'existingInvoicePaymentResponse');
        $method->setAccessible(true);
        return $method->invoke($handler, $response, $cached, $fingerprint);
    }

    /** @return array{PurchasesHandler, Response} */
    private function handlerWithExistingPurchase(): array
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            invoice_number TEXT NOT NULL, idempotency_key TEXT NOT NULL, request_hash TEXT NOT NULL
        )');
        $insert = $db->prepare(
            'INSERT INTO purchases (id, tenant_id, user_id, invoice_number, idempotency_key, request_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([41, 3, 7, 'PUR-TEST-041', 'request-key-1', str_repeat('a', 64)]);

        return [new PurchasesHandler($db), new Response()];
    }
}
