<?php

declare(strict_types=1);

use App\Handlers\NotificationHandler;
use PHPUnit\Framework\TestCase;

final class NotificationExpiryAlertTest extends TestCase
{
    public function testExpiryAlertBindsProductAndConfiguredThreshold(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([42, 15])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $db = $this->createMock(PDO::class);
        $db->expects(self::once())
            ->method('prepare')
            ->with(self::logicalAnd(
                self::stringContains('JOIN product_expiry b'),
                self::stringContains('DATE_ADD(CURDATE(), INTERVAL ? DAY)'),
                self::logicalNot(self::stringContains('product_batches'))
            ))
            ->willReturn($statement);

        (new NotificationHandler($db))->sendExpiryAlert(42, 15);
    }
}
