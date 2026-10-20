<?php

declare(strict_types=1);

use App\Services\NotificationAggregatorService;
use PHPUnit\Framework\TestCase;

final class NotificationAggregatorTransactionTest extends TestCase
{
    public function testOrdinarySalesAreNotQueuedAsNotifications(): void
    {
        $db = $this->database();
        $service = new NotificationAggregatorService($db, $this->logger());

        self::assertTrue($service->queueNotification(
            4,
            3,
            'new_order',
            ['order_id' => 12, 'customer_name' => 'Test customer', 'amount' => 100]
        ));
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM notification_batches')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function testNotificationBatchUsesSavepointInsideAnExistingTransaction(): void
    {
        $db = $this->database();
        $service = new NotificationAggregatorService($db, $this->logger());
        $db->beginTransaction();

        self::assertTrue($service->queueNotification(
            4,
            3,
            'low_stock_alert',
            ['product_id' => 6, 'product_name' => 'Test product', 'current_stock' => 1]
        ));
        self::assertTrue($db->inTransaction(), 'Notification batching must not close the caller transaction.');
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM notification_batches')->fetchColumn());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());

        $db->rollBack();

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM notification_batches')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function testNotificationBatchCommitsWhenItOwnsTheTransaction(): void
    {
        $db = $this->database();
        $service = new NotificationAggregatorService($db, $this->logger());

        self::assertTrue($service->queueNotification(
            4,
            3,
            'low_stock_alert',
            ['product_id' => 6, 'product_name' => 'Test product', 'current_stock' => 1]
        ));

        self::assertFalse($db->inTransaction());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM notification_batches')->fetchColumn());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        $db->exec('CREATE TABLE notification_preferences (
            user_id INTEGER, tenant_id INTEGER, notification_type TEXT, delivery_mode TEXT,
            batch_interval_minutes INTEGER, threshold_enabled INTEGER, threshold_value REAL,
            enable_in_app INTEGER, enable_email INTEGER, enable_push INTEGER
        )');
        $db->exec('CREATE TABLE notification_batches (
            batch_id TEXT PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, notification_type TEXT,
            event_count INTEGER, total_amount REAL, status TEXT, scheduled_at TEXT, created_at TEXT,
            updated_at TEXT
        )');
        $db->exec('CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, type TEXT,
            batch_id TEXT, aggregation_key TEXT, aggregated_count INTEGER, priority TEXT,
            title TEXT, message TEXT, data TEXT, is_read INTEGER, created_at TEXT, updated_at TEXT
        )');

        return $db;
    }

    private function logger(): object
    {
        return new class {
            public function debug(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
        };
    }
}
