<?php

declare(strict_types=1);

use App\Services\OwnerContributionService;
use PHPUnit\Framework\TestCase;

final class OwnerContributionIdempotencyTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            type TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            cost_center_id INTEGER
        )');
        $this->db->exec('CREATE TABLE owner_contributions (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            contribution_type TEXT NOT NULL,
            amount NUMERIC NOT NULL,
            target_account_id INTEGER NOT NULL,
            related_gl_account_id INTEGER NOT NULL,
            interest_rate NUMERIC,
            due_date TEXT,
            repayment_terms TEXT,
            repayment_schedule TEXT,
            journal_entry_id INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (tenant_id, idempotency_key)
        )');

        $this->db->exec("INSERT INTO accounts (id, tenant_id, type) VALUES
            (10, 1, 'asset'),
            (20, 1, 'equity')");
        $this->db->exec("INSERT INTO journal_entries (id, tenant_id, cost_center_id)
            VALUES (50, 1, 7)");
        $this->db->exec("INSERT INTO owner_contributions (
            id, tenant_id, contribution_type, amount, target_account_id,
            related_gl_account_id, interest_rate, due_date, repayment_terms,
            repayment_schedule, journal_entry_id, idempotency_key, status, created_at
        ) VALUES (
            30, 1, 'capital', 1000, 10, 20, NULL, NULL, NULL,
            NULL, 50, 'request-1', 'recorded', '2026-10-16 10:00:00'
        )");
    }

    public function testMatchingRetryReturnsExistingContribution(): void
    {
        $service = new OwnerContributionService($this->db, 1, 5);

        $result = $service->recordContribution(
            'capital',
            1000,
            10,
            20,
            costCenterId: 7,
            idempotencyKey: 'request-1'
        );

        self::assertSame(30, $result['id']);
        self::assertSame(50, $result['journal_entry_id']);
        self::assertTrue($result['duplicate']);
    }

    public function testReusingKeyForDifferentContributionIsRejected(): void
    {
        $service = new OwnerContributionService($this->db, 1, 5);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('مفتاح منع التكرار مستخدم لبيانات مساهمة مختلفة.');

        $service->recordContribution(
            'capital',
            1250,
            10,
            20,
            costCenterId: 7,
            idempotencyKey: 'request-1'
        );
    }

    public function testReusingKeyWithDifferentCostCenterIsRejected(): void
    {
        $service = new OwnerContributionService($this->db, 1, 5);
        $this->expectException(DomainException::class);

        $service->recordContribution(
            'capital',
            1000,
            10,
            20,
            costCenterId: 8,
            idempotencyKey: 'request-1'
        );
    }
}
