<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/032_create_repair_orders.php';

use App\Handlers\RepairOrdersHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class RepairOrdersMySqlIntegrationTest extends TestCase
{
    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;
    private static int $tenantId;
    private static int $userId;
    private static int $branchId;
    private static int $customerId;
    private static int $stockProductId;
    private static int $serviceProductId;
    private static int $unitId;
    private static RepairOrdersHandler $handler;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_REPAIR_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_MYSQL_REPAIR_TESTS=1 to create an isolated temporary MySQL schema.'
            );
        }

        \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            self::fail('Refusing repair integration tests unless DB_HOST is local.');
        }

        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
        self::$schema = 'smartsys_repair_it_' . bin2hex(random_bytes(8));
        self::$server = new mysqli($host, $username, $password, '', $port);
        self::$server->set_charset('utf8mb4');
        self::$server->query(
            'CREATE DATABASE `' . self::$schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::$server->select_db(self::$schema);

        try {
            MySqlSchemaDumpImporter::import(
                self::$server,
                __DIR__ . '/Fixtures/mysql_schema.sql'
            );
            self::$db = new PDO(
                "mysql:host={$host};port={$port};dbname=" . self::$schema . ';charset=utf8mb4',
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            (new CreateRepairOrders(self::$db))->up();
            self::seedFixture();
            self::$handler = new RepairOrdersHandler(self::$db);
        } catch (Throwable $error) {
            self::dropTemporarySchema();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropTemporarySchema();
    }

    public function testRepairOrderLifecycleCreatesSingleInvoiceConsumesStockAndDeliversDevice(): void
    {
        $created = $this->invoke('create', 'POST', '/repairs', [
            'branch_id' => self::$branchId,
            'customer_id' => self::$customerId,
            'device_type' => 'Phone',
            'brand' => 'Integration',
            'model' => 'Repair Test',
            'serial_number' => 'IT-' . bin2hex(random_bytes(4)),
            'issue_description' => 'Screen is damaged',
            'condition_received' => 'Screen cracked',
            'estimated_cost' => 175,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdData = $this->responseData($created);
        $orderId = (int) $createdData['id'];
        self::assertGreaterThan(0, $orderId);

        $this->assertUpdate($orderId, ['status' => 'diagnosing']);
        $this->assertUpdate($orderId, [
            'status' => 'in_repair',
            'diagnosis' => 'Screen replacement required',
            'labor_product_id' => self::$serviceProductId,
            'labor_amount' => 75,
            'parts' => [[
                'product_id' => self::$stockProductId,
                'unit_id' => self::$unitId,
                'quantity' => 2,
                'sale_price' => 100,
            ]],
        ]);
        $this->assertUpdate($orderId, [
            'status' => 'ready',
            'resolution' => 'Screen replaced and tested',
        ]);
        $prematureDelivery = $this->invoke(
            'update',
            'PATCH',
            '/repairs/' . $orderId,
            ['status' => 'delivered'],
            ['id' => (string) $orderId]
        );
        self::assertSame(409, $prematureDelivery->getStatusCode(), (string) $prematureDelivery->getBody());

        $invoice = $this->invoke(
            'createInvoice',
            'POST',
            '/repairs/' . $orderId . '/invoice',
            null,
            ['id' => (string) $orderId]
        );
        self::assertSame(201, $invoice->getStatusCode(), (string) $invoice->getBody());
        $invoiceData = $this->responseData($invoice);
        $saleId = (int) $invoiceData['sale_id'];
        self::assertGreaterThan(0, $saleId);

        $retry = $this->invoke(
            'createInvoice',
            'POST',
            '/repairs/' . $orderId . '/invoice',
            null,
            ['id' => (string) $orderId]
        );
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getBody());
        self::assertTrue($this->responseData($retry)['already_created']);

        $order = self::$db->prepare(
            'SELECT status, invoice_id FROM repair_orders WHERE id = ? AND tenant_id = ?'
        );
        $order->execute([$orderId, self::$tenantId]);
        $savedOrder = $order->fetch();
        self::assertSame('ready', $savedOrder['status']);
        self::assertSame($saleId, (int) $savedOrder['invoice_id']);

        $salesCount = self::$db->prepare(
            "SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND notes = ?"
        );
        $salesCount->execute([self::$tenantId, 'صيانة: ' . $createdData['order_number']]);
        self::assertSame(1, (int) $salesCount->fetchColumn());

        $items = self::$db->prepare(
            'SELECT product_id, quantity FROM sales_items WHERE sale_id = ? AND tenant_id = ? ORDER BY id'
        );
        $items->execute([$saleId, self::$tenantId]);
        $savedItems = $items->fetchAll();
        self::assertCount(2, $savedItems);
        self::assertEquals(2, (float) $savedItems[0]['quantity']);
        self::assertEquals(1, (float) $savedItems[1]['quantity']);

        $postedJournal = self::$db->prepare(
            "SELECT COUNT(*) FROM journal_entries
             WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ? AND status = 'posted'"
        );
        $postedJournal->execute([self::$tenantId, $saleId]);
        self::assertSame(1, (int) $postedJournal->fetchColumn());

        $stock = self::$db->prepare(
            'SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
        );
        $stock->execute([self::$tenantId, self::$branchId, self::$stockProductId]);
        self::assertEquals(3, (float) $stock->fetchColumn());

        $this->assertUpdate($orderId, ['status' => 'delivered']);
        $delivered = self::$db->prepare(
            'SELECT status, delivered_by, delivered_at FROM repair_orders WHERE id = ? AND tenant_id = ?'
        );
        $delivered->execute([$orderId, self::$tenantId]);
        $deliveredOrder = $delivered->fetch();
        self::assertSame('delivered', $deliveredOrder['status']);
        self::assertSame(self::$userId, (int) $deliveredOrder['delivered_by']);
        self::assertNotEmpty($deliveredOrder['delivered_at']);
    }

    private static function seedFixture(): void
    {
        $db = self::$db;
        $db->exec(
            "INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
             VALUES ('repair-mysql-it', 'Repair MySQL integration test', 0, 'EGP', 30, '{}', 1)"
        );
        $planId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO tenants (company_name, plan_id, status) VALUES (?, ?, 'active')")
            ->execute(['Repair integration tenant', $planId]);
        self::$tenantId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO cost_centers (tenant_id, name, code) VALUES (?, ?, ?)')
            ->execute([
                self::$tenantId,
                'Repair integration center',
                'IT-REPAIR-' . bin2hex(random_bytes(4)),
            ]);
        $costCenterId = (int) $db->lastInsertId();

        $account = $db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $account->execute([self::$tenantId, 'IT-REPAIR-BANK', 'Repair integration cash', 'asset']);
        $cashAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-REPAIR-INV', 'Repair integration inventory', 'asset']);
        $inventoryAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-REPAIR-COGS', 'Repair integration COGS', 'expense']);
        $cogsAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '1101', 'Repair integration receivable', 'asset']);
        $receivableAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '4001', 'Repair integration revenue', 'revenue']);

        $db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES ('Repair integration credit', 'credit', ?, ?, 1, 'other')"
        )->execute([$cashAccountId, self::$tenantId]);

        $db->prepare(
            'INSERT INTO branches (tenant_id, name, account_id, cost_center_id, active) VALUES (?, ?, ?, ?, 1)'
        )->execute([self::$tenantId, 'Repair integration branch', $cashAccountId, $costCenterId]);
        self::$branchId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO users (username, password, name, status, tenant_id, branch_id, email)
             VALUES (?, 'test-only', 'Repair integration operator', 'active', ?, ?, ?)"
        )->execute([
            'repair-it-' . bin2hex(random_bytes(4)),
            self::$tenantId,
            self::$branchId,
            'repair-it-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ]);
        self::$userId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO customers (tenant_id, branch_id, name, phone, account_id, active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([
            self::$tenantId,
            self::$branchId,
            'Repair integration customer',
            '01000000000',
            $receivableAccountId,
        ]);
        self::$customerId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'pos.sessions.enabled', '0']);
        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'company.currency', 'EGP']);
        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'company.tax_rate', '0']);
        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'ar_account', (string) $receivableAccountId]);

        $db->prepare('INSERT INTO units (name, code, tenant_id) VALUES (?, ?, ?)')
            ->execute([
                'Repair integration unit',
                'IT-REPAIR-UNIT-' . bin2hex(random_bytes(4)),
                self::$tenantId,
            ]);
        self::$unitId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO products
                (tenant_id, name, sale_price, purchase_price, product_code, unit_id, active, product_type)
             VALUES (?, 'Repair integration part', 100, 10, ?, ?, 1, 'stock')"
        )->execute([
            self::$tenantId,
            'IT-REPAIR-PART-' . bin2hex(random_bytes(4)),
            self::$unitId,
        ]);
        self::$stockProductId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO products
                (tenant_id, name, sale_price, purchase_price, product_code, unit_id, active, product_type)
             VALUES (?, 'Repair integration service', 75, 0, ?, ?, 1, 'service')"
        )->execute([
            self::$tenantId,
            'IT-REPAIR-SERVICE-' . bin2hex(random_bytes(4)),
            self::$unitId,
        ]);
        self::$serviceProductId = (int) $db->lastInsertId();

        foreach ([self::$stockProductId, self::$serviceProductId] as $productId) {
            $db->prepare(
                'INSERT INTO product_units (product_id, unit_id, conversion_factor, tenant_id, is_main_unit)
                 VALUES (?, ?, 1, ?, 1)'
            )->execute([$productId, self::$unitId, self::$tenantId]);
        }
        $db->prepare(
            "INSERT INTO product_branch_gl_mapping
                (tenant_id, product_id, branch_id, inventory_gl_account_id, purchase_gl_account_id,
                 cogs_gl_account_id, activation_status)
             VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH')"
        )->execute([
            self::$tenantId,
            self::$stockProductId,
            self::$branchId,
            $inventoryAccountId,
            $inventoryAccountId,
            $cogsAccountId,
        ]);
        $db->prepare(
            'INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, quantity_cost, gl_reconciled)
             VALUES (?, ?, ?, 5, 50, 1)'
        )->execute([self::$tenantId, self::$branchId, self::$stockProductId]);
    }

    private function assertUpdate(int $orderId, array $payload): void
    {
        $response = $this->invoke(
            'update',
            'PATCH',
            '/repairs/' . $orderId,
            $payload,
            ['id' => (string) $orderId]
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function invoke(
        string $method,
        string $httpMethod,
        string $path,
        ?array $payload = null,
        array $args = []
    ): Response {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, $path)
            ->withAttribute('tenant_id', self::$tenantId)
            ->withAttribute('user', [
                'id' => self::$userId,
                'tenant_id' => self::$tenantId,
                'branch_id' => self::$branchId,
                'role' => 'cashier',
            ]);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        return self::$handler->{$method}($request, new Response(), $args);
    }

    private function responseData(Response $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    private static function dropTemporarySchema(): void
    {
        self::$db = null;
        if (self::$schema !== null && self::$server instanceof mysqli) {
            $schema = self::$schema;
            self::$server->query('DROP DATABASE IF EXISTS `' . $schema . '`');
            $stmt = self::$server->prepare(
                'SELECT COUNT(schema_name) FROM information_schema.schemata WHERE schema_name = ?'
            );
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $stmt->bind_result($remainingSchemas);
            $stmt->fetch();
            $stmt->close();
            if ((int) $remainingSchemas !== 0) {
                throw new RuntimeException('Temporary MySQL repair schema was not removed.');
            }
        }
        self::$schema = null;
        if (self::$server instanceof mysqli) {
            self::$server->close();
            self::$server = null;
        }
    }
}
