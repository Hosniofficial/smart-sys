<?php

declare(strict_types=1);

use App\Handlers\CashVouchersHandler;
use App\Services\CashVoucherService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class CashVoucherRequestHashTest extends TestCase
{
    public function testRequestHashIsStableForEquivalentFieldOrderAndChangesWithVoucherData(): void
    {
        $service = new CashVoucherService(new PDO('sqlite::memory:'), 4, 8);
        $first = [
            'type' => 'receipt',
            'date' => '2026-10-02',
            'amount' => 120.0,
            'currency' => 'EGP',
            'customer_id' => 14,
            'payment_method_id' => 2,
            'branch_id' => 6,
            'cost_center_id' => 3,
            'notes' => 'Receipt',
        ];

        self::assertSame(
            $service->getVoucherRequestHash($first),
            $service->getVoucherRequestHash(array_reverse($first, true))
        );
        self::assertNotSame(
            $service->getVoucherRequestHash($first),
            $service->getVoucherRequestHash(array_merge($first, ['amount' => 121.0]))
        );
        self::assertSame(
            $service->getVoucherRequestHash($first),
            $service->getVoucherRequestHash(array_merge($first, ['account_id' => 1001]))
        );
    }

    public function testIdempotentReplayRequiresMatchingStoredRequestHash(): void
    {
        $handler = new CashVouchersHandler(new PDO('sqlite::memory:'));
        $method = new ReflectionMethod(CashVouchersHandler::class, 'respondWithIdempotentVoucher');
        $method->setAccessible(true);

        $success = $method->invoke(
            $handler,
            new Response(),
            ['id' => 22, 'reference' => 'CV-20261002-22', 'request_hash' => str_repeat('a', 64)],
            str_repeat('a', 64)
        );
        self::assertSame(200, $success->getStatusCode());
        self::assertSame(
            'success',
            json_decode((string) $success->getBody(), true)['status']
        );

        $conflict = $method->invoke(
            $handler,
            new Response(),
            ['id' => 22, 'reference' => 'CV-20261002-22', 'request_hash' => str_repeat('a', 64)],
            str_repeat('b', 64)
        );
        self::assertSame(409, $conflict->getStatusCode());
    }
}
