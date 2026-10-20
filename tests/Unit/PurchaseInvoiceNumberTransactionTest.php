<?php

declare(strict_types=1);

use App\Services\PurchaseService;
use PHPUnit\Framework\TestCase;

final class PurchaseInvoiceNumberTransactionTest extends TestCase
{
    public function testInvoiceNumberGenerationRequiresTransactionScope(): void
    {
        $db = new PDO('sqlite::memory:');
        $service = new PurchaseService($db, 1);

        $this->expectException(LogicException::class);
        $service->generateInvoiceNumber('2026-10-16');
    }
}
