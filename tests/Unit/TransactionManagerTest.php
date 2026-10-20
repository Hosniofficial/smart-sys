<?php

declare(strict_types=1);

use App\Services\Transaction\TransactionManager;
use PHPUnit\Framework\TestCase;

final class TransactionManagerTest extends TestCase
{
    private string|false $previousLogLevel;

    protected function setUp(): void
    {
        $this->previousLogLevel = getenv('LOG_LEVEL');
        putenv('LOG_LEVEL=emergency');
    }

    protected function tearDown(): void
    {
        if ($this->previousLogLevel === false) {
            putenv('LOG_LEVEL');
        } else {
            putenv('LOG_LEVEL=' . $this->previousLogLevel);
        }
    }

    public function testNestedSuccessCommitsOnlyAfterTheOuterCallback(): void
    {
        $db = $this->database();
        $manager = $this->manager($db);

        $result = $manager->execute(function () use ($db, $manager): string {
            $db->exec("INSERT INTO records (value) VALUES ('outer')");
            $innerResult = $manager->execute(function () use ($db): string {
                $db->exec("INSERT INTO records (value) VALUES ('inner')");
                return 'inner result';
            }, 'inner operation');

            self::assertSame('inner result', $innerResult);
            self::assertTrue($db->inTransaction());
            self::assertSame(1, $manager->getDepth());
            self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM records')->fetchColumn());

            return 'outer result';
        }, 'outer operation');

        self::assertSame('outer result', $result);
        self::assertFalse($db->inTransaction());
        self::assertSame(0, $manager->getDepth());
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM records')->fetchColumn());
    }

    public function testNestedFailureRollsBackAtTheOuterBoundaryAndResetsDepth(): void
    {
        $db = $this->database();
        $manager = $this->manager($db);

        try {
            $manager->execute(function () use ($db, $manager): void {
                $db->exec("INSERT INTO records (value) VALUES ('outer')");
                $manager->execute(function () use ($db): void {
                    $db->exec("INSERT INTO records (value) VALUES ('inner')");
                    throw new RuntimeException('nested callback failed');
                }, 'inner operation');
            }, 'outer operation');
            self::fail('The nested callback exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('nested callback failed', $exception->getMessage());
        }

        self::assertFalse($db->inTransaction());
        self::assertSame(0, $manager->getDepth());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM records')->fetchColumn());
    }

    public function testCommitFailureRollsBackAndResetsDepthOnce(): void
    {
        $db = $this->database();
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('CREATE TABLE parent_records (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE child_records (
            parent_id INTEGER,
            FOREIGN KEY (parent_id) REFERENCES parent_records(id)
                DEFERRABLE INITIALLY DEFERRED
        )');
        $manager = $this->manager($db);

        try {
            $manager->execute(function () use ($db): void {
                $db->exec('INSERT INTO child_records (parent_id) VALUES (999)');
            }, 'deferred constraint operation');
            self::fail('The deferred foreign key should make commit fail.');
        } catch (PDOException) {
            // SQLite reports the deferred constraint violation when commit is attempted.
        }

        self::assertFalse($db->inTransaction(), 'The failed commit must be rolled back.');
        self::assertSame(0, $manager->getDepth());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM child_records')->fetchColumn());
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE records (value TEXT NOT NULL)');

        return $db;
    }

    private function manager(PDO $db): TransactionManager
    {
        return new TransactionManager($db, 'transaction_manager_test_' . bin2hex(random_bytes(4)));
    }
}
