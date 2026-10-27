<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceCampaignService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PriceCampaignServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            "CREATE TABLE sales_price_campaigns (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                branch_id INTEGER NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                priority INTEGER NOT NULL
            )"
        );
        $this->db->exec(
            "CREATE TABLE sales_price_campaign_items (
                tenant_id INTEGER NOT NULL,
                campaign_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                unit_id INTEGER NOT NULL,
                campaign_price REAL NOT NULL
            )"
        );
    }

    public function testBranchSpecificCampaignWinsOverGlobalCampaign(): void
    {
        $this->addCampaign(1, null, 'Global', 'active', -100, 75);
        $this->addCampaign(2, 8, 'Branch', 'active', -100, 65);

        $price = PriceCampaignService::findActivePrice($this->db, 4, 8, 20, 3);

        self::assertSame(2, $price['campaign_id']);
        self::assertSame('Branch', $price['campaign_name']);
        self::assertSame(65.0, $price['campaign_price']);
    }

    public function testHigherPriorityWinsAmongCampaignsForSameBranch(): void
    {
        $this->addCampaign(1, 8, 'Lower priority', 'active', 1, 75);
        $this->addCampaign(2, 8, 'Higher priority', 'active', 5, 65);

        $prices = PriceCampaignService::findActivePrices($this->db, 4, 8, [20 => 3]);

        self::assertSame(2, $prices['20:3']['campaign_id']);
        self::assertSame(65.0, $prices['20:3']['campaign_price']);
    }

    public function testIgnoresPausedExpiredAndOtherTenantCampaigns(): void
    {
        $this->addCampaign(1, 8, 'Paused', 'paused', 100, 40);
        $this->addCampaign(2, 8, 'Expired', 'active', 100, 45, '2000-01-01', '2000-01-02');
        $this->addCampaign(3, 8, 'Other tenant', 'active', 100, 50, null, null, 9);
        $this->addCampaign(4, null, 'Current tenant', 'active', 0, 70);

        $price = PriceCampaignService::findActivePrice($this->db, 4, 8, 20, 3);

        self::assertSame(4, $price['campaign_id']);
        self::assertSame(70.0, $price['campaign_price']);
    }

    private function addCampaign(
        int $id,
        ?int $branchId,
        string $name,
        string $status,
        int $priority,
        float $price,
        ?string $startDate = null,
        ?string $endDate = null,
        int $tenantId = 4
    ): void {
        $today = date('Y-m-d');
        $campaign = $this->db->prepare(
            'INSERT INTO sales_price_campaigns
                (id, tenant_id, branch_id, name, status, start_date, end_date, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $campaign->execute([
            $id,
            $tenantId,
            $branchId,
            $name,
            $status,
            $startDate ?? $today,
            $endDate ?? $today,
            $priority,
        ]);
        $item = $this->db->prepare(
            'INSERT INTO sales_price_campaign_items
                (tenant_id, campaign_id, product_id, unit_id, campaign_price)
             VALUES (?, ?, ?, ?, ?)'
        );
        $item->execute([$tenantId, $id, 20, 3, $price]);
    }
}
