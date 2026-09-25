<?php

declare(strict_types=1);

use App\Services\AccountingPeriodCloseService;
use PHPUnit\Framework\TestCase;

final class AccountingPeriodCloseServiceTest extends TestCase
{
    private PDO $db;
    private AccountingPeriodCloseService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE accounting_periods (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, start_date TEXT NOT NULL,
            end_date TEXT NOT NULL, status TEXT NOT NULL, closed_by INTEGER NULL,
            closed_at TEXT NULL, updated_at TEXT NULL
        )");
        $this->db->exec("CREATE TABLE branch_products (
            tenant_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
            gl_reconciled INTEGER NULL
        )");
        $this->db->exec("CREATE TABLE inventory_transactions (
            tenant_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
            branch_from INTEGER NULL, branch_to INTEGER NULL, movement_date TEXT NOT NULL
        )");
        $this->db->exec("CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, entry_date TEXT NULL,
            status TEXT NULL, reference_type TEXT NULL, reference_id INTEGER NULL
        )");
        $this->db->exec("CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, action TEXT,
            entity TEXT, entity_id INTEGER, details TEXT, tenant_id INTEGER,
            ip_address TEXT, created_at TEXT
        )");
        $this->db->exec("INSERT INTO accounting_periods
            (id, tenant_id, start_date, end_date, status) VALUES
            (10, 7, '2026-09-01 00:00:00', '2026-09-30 00:00:00', 'open')");
        $this->service = new AccountingPeriodCloseService();
    }

    public function testBlocksCloseWhenUnreconciledInventoryExistsInPeriod(): void
    {
        $this->seedInventory(false, '2026-09-15 10:00:00');

        $result = $this->close();

        self::assertSame(409, $result['http_status']);
        self::assertSame(1, $result['blockers']['unreconciled_inventory_count']);
        self::assertSame('open', $this->periodStatus());
    }

    public function testClosesWhenInventoryIsReconciledAndNoJournalIsPending(): void
    {
        $this->seedInventory(true, '2026-09-15 10:00:00');
        $this->db->exec("INSERT INTO journal_entries (id, tenant_id, entry_date, status)
            VALUES (1, 7, '2026-09-20', 'posted')");

        $result = $this->close();

        self::assertSame(200, $result['http_status']);
        self::assertFalse($result['overridden']);
        self::assertSame('closed', $this->periodStatus());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testIgnoresUnreconciledMovementsOutsideThePeriod(): void
    {
        $this->seedInventory(false, '2026-08-31 23:59:59');
        $this->db->prepare('INSERT INTO inventory_transactions
            (tenant_id, product_id, branch_to, movement_date) VALUES (7, 5, 3, ?)')
            ->execute(['2026-10-01 00:00:00']);

        $result = $this->close();

        self::assertSame(200, $result['http_status']);
        self::assertSame('closed', $this->periodStatus());
    }

    public function testBlocksCloseWhenPendingJournalExists(): void
    {
        $this->db->exec("INSERT INTO journal_entries (id, tenant_id, entry_date, status)
            VALUES (2, 7, '2026-09-22', 'draft')");

        $result = $this->close();

        self::assertSame(409, $result['http_status']);
        self::assertSame(1, $result['blockers']['pending_journal_count']);
        self::assertSame('open', $this->periodStatus());
    }

    public function testAuthorizedOverrideClosesAndWritesAuditInSameTransaction(): void
    {
        $this->seedInventory(false, '2026-09-15 10:00:00');

        $result = $this->close(true, true, 'Approved by finance controller');

        self::assertSame(200, $result['http_status']);
        self::assertTrue($result['overridden']);
        self::assertSame('closed', $this->periodStatus());
        $audit = $this->db->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('accounting_period_close_override', $audit['action']);
        self::assertSame('accounting_period', $audit['entity']);
        self::assertSame('Approved by finance controller', json_decode($audit['details'], true)['reason']);
        self::assertSame(7, (int) $audit['tenant_id']);
        self::assertSame(42, (int) $audit['user_id']);
    }

    public function testRejectsUnauthorizedOverrideWithoutClosing(): void
    {
        $this->seedInventory(false, '2026-09-15 10:00:00');

        $result = $this->close(true, false, 'Unauthorized attempt');

        self::assertSame(403, $result['http_status']);
        self::assertSame('open', $this->periodStatus());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testAuditFailureRollsBackPeriodClosure(): void
    {
        $this->seedInventory(false, '2026-09-15 10:00:00');
        $this->db->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON audit_log
            BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");

        try {
            $this->close(true, true, 'Approved override');
            self::fail('Expected audit failure to abort closure.');
        } catch (PDOException $e) {
            self::assertStringContainsString('audit unavailable', $e->getMessage());
        }

        self::assertSame('open', $this->periodStatus());
    }

    private function seedInventory(bool $reconciled, string $movementDate): void
    {
        $this->db->prepare('INSERT INTO branch_products (tenant_id, branch_id, product_id, gl_reconciled) VALUES (7, 3, 5, ?)')
            ->execute([$reconciled ? 1 : 0]);
        $this->db->prepare('INSERT INTO inventory_transactions
            (tenant_id, product_id, branch_to, movement_date) VALUES (7, 5, 3, ?)')
            ->execute([$movementDate]);
    }

    private function close(bool $override = false, bool $canOverride = false, string $reason = ''): array
    {
        return $this->service->close($this->db, 7, 10, 42, $override, $canOverride, $reason, '127.0.0.1');
    }

    private function periodStatus(): string
    {
        return (string) $this->db->query('SELECT status FROM accounting_periods WHERE id = 10')->fetchColumn();
    }
}
