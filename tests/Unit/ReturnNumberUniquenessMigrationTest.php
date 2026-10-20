<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/029_add_return_number_uniqueness.php';

use PHPUnit\Framework\TestCase;

final class ReturnNumberUniquenessMigrationTest extends TestCase
{
    public function testAddsTenantScopedUniqueIndexIdempotently(): void
    {
        $db = $this->database();
        $migration = new AddReturnNumberUniqueness($db);

        $migration->up();
        $migration->up();
        $db->exec("INSERT INTO returns (tenant_id, return_number) VALUES (4, 'SR-261016-001')");
        $db->exec("INSERT INTO returns (tenant_id, return_number) VALUES (5, 'SR-261016-001')");

        $this->expectException(PDOException::class);
        $db->exec("INSERT INTO returns (tenant_id, return_number) VALUES (4, 'SR-261016-001')");
    }

    public function testRefusesExistingDuplicateNumbersBeforeAddingIndex(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO returns (tenant_id, return_number) VALUES (4, 'PR-261016-001')");
        $db->exec("INSERT INTO returns (tenant_id, return_number) VALUES (4, 'PR-261016-001')");

        try {
            (new AddReturnNumberUniqueness($db))->up();
            self::fail('The migration must refuse pre-existing duplicate return numbers.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('duplicate tenant/return-number pairs', $error->getMessage());
        }

        $indexes = $db->query('PRAGMA index_list(returns)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertNotContains('uq_returns_tenant_return_number', $indexes);
    }

    public function testCanRemoveTheUniqueIndex(): void
    {
        $db = $this->database();
        $migration = new AddReturnNumberUniqueness($db);
        $migration->up();
        $migration->down();
        $migration->down();

        $indexes = $db->query('PRAGMA index_list(returns)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertNotContains('uq_returns_tenant_return_number', $indexes);
    }

    private function database(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE returns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                return_number TEXT NOT NULL
            )'
        );
        return $db;
    }
}
