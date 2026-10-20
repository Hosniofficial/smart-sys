<?php

declare(strict_types=1);

use App\Services\ProductBranchActivationGuard;
use App\Resources\ProductListResource;
use PHPUnit\Framework\TestCase;

final class ProductBranchActivationGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE products (id INTEGER PRIMARY KEY, tenant_id INTEGER, active INTEGER, product_type TEXT)'
        );
        $this->db->exec(
            'CREATE TABLE product_branch_gl_mapping (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER,
                product_id INTEGER,
                branch_id INTEGER,
                activation_status TEXT
            )'
        );
        $this->db->exec("INSERT INTO products (id, tenant_id, active, product_type) VALUES (10, 1, 1, 'stock')");
    }

    public function testAllowsOperationalBranchStatuses(): void
    {
        foreach (['ACTIVE_IN_BRANCH', 'GL_POSTED', 'RECONCILED'] as $status) {
            $this->setMappingStatus($status);
            ProductBranchActivationGuard::assertActiveForStock($this->db, 1, 20, 10);
            $this->db->exec('DELETE FROM product_branch_gl_mapping');
        }

        $this->assertTrue(true);
    }

    public function testRejectsDraftMapping(): void
    {
        $this->setMappingStatus('DRAFT');

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(422);
        ProductBranchActivationGuard::assertActiveForStock($this->db, 1, 20, 10);
    }

    public function testRejectsMissingBranchMapping(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(422);
        ProductBranchActivationGuard::assertActiveForStock($this->db, 1, 20, 10);
    }

    public function testRejectsInactiveProduct(): void
    {
        $this->db->exec('UPDATE products SET active = 0 WHERE id = 10');
        $this->setMappingStatus('ACTIVE_IN_BRANCH');

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(422);
        ProductBranchActivationGuard::assertActiveForStock($this->db, 1, 20, 10);
    }

    public function testRejectsServiceProductFromStockMovement(): void
    {
        $this->db->exec("UPDATE products SET product_type = 'service' WHERE id = 10");
        $this->setMappingStatus('ACTIVE_IN_BRANCH');

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(422);
        ProductBranchActivationGuard::assertActiveForStock($this->db, 1, 20, 10);
    }

    public function testAllowsActiveServiceSaleWithoutBranchMapping(): void
    {
        $this->db->exec("UPDATE products SET product_type = 'service' WHERE id = 10");

        $this->assertFalse(ProductBranchActivationGuard::assertSellableInBranch($this->db, 1, 20, 10));
    }

    public function testStockSaleStillRequiresOperationalBranchMapping(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(422);

        ProductBranchActivationGuard::assertSellableInBranch($this->db, 1, 20, 10);
    }

    public function testBranchScopedStatusDoesNotReuseAnotherBranchesOpeningFlag(): void
    {
        $product = [
            'id' => 10,
            'name' => 'Test product',
            'product_code' => 'SKU-10',
            'barcode' => null,
            'category_id' => null,
            'sale_price' => 10,
            'purchase_price' => 5,
            'min_quantity' => 0,
            'product_type' => 'stock',
            'active' => 1,
            'opening_balance_posted' => 1,
        ];

        $draft = ProductListResource::transform($product, [], [], null, false, true);
        $active = ProductListResource::transform($product, [], [], 'ACTIVE_IN_BRANCH', false, true);
        $unscoped = ProductListResource::transform($product, [], [], null, false, false);

        $this->assertSame('draft', $draft['gl_status']);
        $this->assertSame('active', $active['gl_status']);
        $this->assertSame('posted', $unscoped['gl_status']);
    }

    private function setMappingStatus(string $status): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_branch_gl_mapping (tenant_id, product_id, branch_id, activation_status)
             VALUES (1, 10, 20, ?)'
        );
        $stmt->execute([$status]);
    }
}
