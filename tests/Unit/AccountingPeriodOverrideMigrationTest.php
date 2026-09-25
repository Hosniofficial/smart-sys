<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/004_add_accounting_period_close_override.php';

use PHPUnit\Framework\TestCase;

final class AccountingPeriodOverrideMigrationTest extends TestCase
{
    public function testDownRefusesToForgetAnIntentionallyRetainedPermission(): void
    {
        $migration = new AddAccountingPeriodCloseOverride(new PDO('sqlite::memory:'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('intentionally irreversible');

        $migration->down();
    }
}
