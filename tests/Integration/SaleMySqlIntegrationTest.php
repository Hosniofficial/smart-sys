<?php

declare(strict_types=1);

use App\Services\SaleCreationService;
use App\Services\ReturnService;
use App\Handlers\CustomersHandler;
use App\Handlers\ReturnsHandler;
use App\Handlers\SalesHandler;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\TenantMiddleware;
use App\Exceptions\InsufficientStockException;
use App\Security\Permissions;
use App\Services\JwtBlacklistService;
use DI\Container;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SaleMySqlIntegrationTest extends TestCase
{
    private const JWT_SECRET = 'sales-mysql-integration-test-secret';

    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;
    private static int $tenantId;
    private static int $userId;
    private static int $branchId;
    private static int $costCenterId;
    private static int $paymentMethodId;
    private static int $productId;
    private static int $unitId;
    private static ?int $salesPaymentRoleId = null;
    private static ?int $salesPaymentPermissionId = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_SALES_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_MYSQL_SALES_TESTS=1 to create an isolated temporary MySQL schema.'
            );
        }

        \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            self::fail('Refusing MySQL integration tests unless DB_HOST is local.');
        }

        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
        self::$schema = 'smartsys_sales_it_' . bin2hex(random_bytes(8));
        self::$server = new mysqli($host, $username, $password, '', $port);
        self::$server->set_charset('utf8mb4');
        self::$server->query(
            'CREATE DATABASE `' . self::$schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::$server->select_db(self::$schema);

        try {
            MySqlSchemaDumpImporter::import(
                self::$server,
                dirname(__DIR__, 2) . '/database/production/smartsys_production_schema_20261003.sql'
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
            require_once dirname(__DIR__, 2) . '/database/migrations/029_add_return_number_uniqueness.php';
            (new AddReturnNumberUniqueness(self::$db))->up();
            self::seedFixture();
        } catch (Throwable $error) {
            self::dropTemporarySchema();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropTemporarySchema();
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
                throw new RuntimeException('Temporary MySQL sales schema was not removed.');
            }
        }
        self::$schema = null;
        if (self::$server instanceof mysqli) {
            self::$server->close();
            self::$server = null;
        }
    }

    private static function seedFixture(): void
    {
        $db = self::$db;
        $db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
                   VALUES ('sales-mysql-it', 'Sales MySQL integration test', 0, 'EGP', 30, '{}', 1)");
        $planId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO tenants (company_name, plan_id, status) VALUES (?, ?, 'active')")
            ->execute(['Sales MySQL integration tenant', $planId]);
        self::$tenantId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO cost_centers (tenant_id, name, code) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'Sales integration center', 'IT-SALES-' . bin2hex(random_bytes(4))]);
        self::$costCenterId = (int) $db->lastInsertId();

        $account = $db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance) VALUES (?, ?, ?, ?, 0, 0)'
        );
        $account->execute([self::$tenantId, 'IT-SALES-BANK', 'Integration bank', 'asset']);
        $bankAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-SALES-INV', 'Integration inventory', 'asset']);
        $inventoryAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-SALES-COGS', 'Integration COGS', 'expense']);
        $cogsAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '4001', 'Integration revenue', 'revenue']);

        $db->prepare(
            'INSERT INTO branches (tenant_id, name, account_id, cost_center_id, active) VALUES (?, ?, ?, ?, 1)'
        )->execute([self::$tenantId, 'Sales integration branch', $bankAccountId, self::$costCenterId]);
        self::$branchId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO users (username, password, name, status, tenant_id, branch_id, email)
             VALUES (?, 'test-only', 'Sales integration operator', 'active', ?, ?, ?)"
        )->execute([
            'sales-it-' . bin2hex(random_bytes(4)),
            self::$tenantId,
            self::$branchId,
            'sales-it-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ]);
        self::$userId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES ('Integration bank', 'bank', ?, ?, 1, 'bank')"
        )->execute([$bankAccountId, self::$tenantId]);
        self::$paymentMethodId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'pos.sessions.enabled', '0']);
        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'company.currency', 'EGP']);

        $db->prepare('INSERT INTO units (name, code, tenant_id) VALUES (?, ?, ?)')
            ->execute(['Sales integration carton', 'IT-CARTON-' . bin2hex(random_bytes(4)), self::$tenantId]);
        self::$unitId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO products
                (tenant_id, name, sale_price, purchase_price, product_code, unit_id, active, product_type)
             VALUES (?, 'Sales integration product', 50, 0, ?, ?, 1, 'stock')"
        )->execute([self::$tenantId, 'IT-PRODUCT-' . bin2hex(random_bytes(4)), self::$unitId]);
        self::$productId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO product_units (product_id, unit_id, conversion_factor, tenant_id, is_main_unit)
             VALUES (?, ?, 5, ?, 1)'
        )->execute([self::$productId, self::$unitId, self::$tenantId]);
        $db->prepare(
            "INSERT INTO product_branch_gl_mapping
                (tenant_id, product_id, branch_id, inventory_gl_account_id, purchase_gl_account_id,
                 cogs_gl_account_id, activation_status)
             VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH')"
        )->execute([
            self::$tenantId,
            self::$productId,
            self::$branchId,
            $inventoryAccountId,
            $inventoryAccountId,
            $cogsAccountId,
        ]);
        self::setStock(20);
    }

    private static function setStock(int $quantity): void
    {
        $stmt = self::$db->prepare(
            'INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, quantity_cost, gl_reconciled)
             VALUES (?, ?, ?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), quantity_cost = 0, gl_reconciled = 1'
        );
        $stmt->execute([self::$tenantId, self::$branchId, self::$productId, $quantity]);
    }

    public function testSaleCreationPersistsCanonicalUnitFactorAndPostsStockAndJournal(): void
    {
        $sale = (new SaleCreationService(self::$db, self::$tenantId, self::$userId))->createSale(
            $this->saleData('SALE-FACTOR-' . bin2hex(random_bytes(4)), 2)
        );

        $item = self::$db->prepare(
            'SELECT conversion_factor, quantity FROM sales_items WHERE sale_id = ? AND tenant_id = ?'
        );
        $item->execute([$sale['sale_id'], self::$tenantId]);
        $savedItem = $item->fetch();
        self::assertEquals(5, (float) $savedItem['conversion_factor']);
        self::assertEquals(2, (float) $savedItem['quantity']);

        $stock = self::$db->prepare(
            'SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
        );
        $stock->execute([self::$tenantId, self::$branchId, self::$productId]);
        self::assertEquals(10, (float) $stock->fetchColumn());

        $movement = self::$db->prepare(
            "SELECT quantity, movement_type, journal_entry_id FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ?"
        );
        $movement->execute([self::$tenantId, $sale['sale_id']]);
        $savedMovement = $movement->fetch();
        self::assertEquals(10, (float) $savedMovement['quantity']);
        self::assertSame('out', $savedMovement['movement_type']);
        self::assertGreaterThan(0, (int) $savedMovement['journal_entry_id']);

        $journal = self::$db->prepare(
            "SELECT COUNT(*) FROM journal_entries
             WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ? AND status = 'posted'"
        );
        $journal->execute([self::$tenantId, $sale['sale_id']]);
        self::assertSame(1, (int) $journal->fetchColumn());
    }

    public function testPayDebtRoutePostsAndAllocatesPaymentInFifoOrder(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'pay-debt-fifo-' . bin2hex(random_bytes(8));
        $response = $this->requestPayDebt([
            'customer_id' => $fixture['customer_id'],
            'amount' => 120,
            'payment_method_id' => self::$paymentMethodId,
            'payment_date' => '2026-10-16',
            'branch_id' => self::$branchId,
        ], $key);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('success', $body['status']);
        self::assertSame([$fixture['first_sale_id'], $fixture['second_sale_id']], $body['data']['allocated_sale_ids']);

        $allocations = self::$db->prepare(
            'SELECT sale_id, allocated_amount FROM customer_payment_allocations
             WHERE tenant_id = ? AND payment_id = ? ORDER BY sale_id'
        );
        $allocations->execute([self::$tenantId, $body['data']['payment_id']]);
        self::assertEquals([
            $fixture['first_sale_id'] => 100.0,
            $fixture['second_sale_id'] => 20.0,
        ], $allocations->fetchAll(PDO::FETCH_KEY_PAIR));

        $payment = self::$db->prepare(
            "SELECT journal_entry_id FROM payments
             WHERE id = ? AND tenant_id = ? AND customer_id = ? AND type = 'debt_payment'"
        );
        $payment->execute([$body['data']['payment_id'], self::$tenantId, $fixture['customer_id']]);
        $journalEntryId = (int) $payment->fetchColumn();
        self::assertSame((int) $body['data']['journal_entry_id'], $journalEntryId);
        self::assertJournalBalanced($journalEntryId);
        self::assertSame('paid', $this->saleStatus($fixture['first_sale_id']));
        self::assertSame('partial', $this->saleStatus($fixture['second_sale_id']));
    }

    public function testPayDebtRouteReturnsStoredResponseForMatchingIdempotencyRetry(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'pay-debt-retry-' . bin2hex(random_bytes(8));
        $payload = [
            'customer_id' => $fixture['customer_id'],
            'amount' => 10,
            'payment_method_id' => self::$paymentMethodId,
            'payment_date' => '2026-10-16',
            'branch_id' => self::$branchId,
        ];

        $first = $this->requestPayDebt($payload, $key);
        $retry = $this->requestPayDebt($payload, $key);

        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getBody());
        self::assertSame(
            json_decode((string) $first->getBody(), true, 512, JSON_THROW_ON_ERROR),
            json_decode((string) $retry->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(1, $this->countCustomerDebtPayments($fixture['customer_id']));
    }

    public function testPayDebtRouteRejectsDifferentPayloadForUsedIdempotencyKey(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'pay-debt-conflict-' . bin2hex(random_bytes(8));
        $payload = [
            'customer_id' => $fixture['customer_id'],
            'amount' => 10,
            'payment_method_id' => self::$paymentMethodId,
            'payment_date' => '2026-10-16',
            'branch_id' => self::$branchId,
        ];

        self::assertSame(200, $this->requestPayDebt($payload, $key)->getStatusCode());
        $payload['amount'] = 11;
        $conflict = $this->requestPayDebt($payload, $key);

        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        self::assertSame(1, $this->countCustomerDebtPayments($fixture['customer_id']));
    }

    public function testPayDebtRouteRollsBackWhenAmountExceedsOutstandingDebt(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'pay-debt-excess-' . bin2hex(random_bytes(8));
        $response = $this->requestPayDebt([
            'customer_id' => $fixture['customer_id'],
            'amount' => 251,
            'payment_method_id' => self::$paymentMethodId,
            'payment_date' => '2026-10-16',
            'branch_id' => self::$branchId,
        ], $key);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->countCustomerDebtPayments($fixture['customer_id']));
        $reservation = self::$db->prepare(
            'SELECT COUNT(*) FROM payment_idempotency_keys WHERE tenant_id = ? AND idempotency_key = ?'
        );
        $reservation->execute([self::$tenantId, $key]);
        self::assertSame(0, (int) $reservation->fetchColumn());
    }

    public function testPayDebtRouteRejectsInvalidAndRoundedToZeroAmounts(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        foreach (['not-a-number', INF, 0.004] as $index => $amount) {
            $key = 'pay-debt-invalid-amount-' . $index . '-' . bin2hex(random_bytes(6));
            $response = $this->requestPayDebt([
                'customer_id' => $fixture['customer_id'],
                'amount' => $amount,
                'payment_method_id' => self::$paymentMethodId,
                'payment_date' => '2026-10-16',
                'branch_id' => self::$branchId,
            ], $key);

            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
            $reservation = self::$db->prepare(
                'SELECT COUNT(*) FROM payment_idempotency_keys WHERE tenant_id = ? AND idempotency_key = ?'
            );
            $reservation->execute([self::$tenantId, $key]);
            self::assertSame(0, (int) $reservation->fetchColumn());
        }
        self::assertSame(0, $this->countCustomerDebtPayments($fixture['customer_id']));
    }

    public function testPayDebtRouteRollsBackPaymentAndReservationWhenJournalPostingFails(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        self::$db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES ('Unlinked integration method', 'other', NULL, ?, 1, 'other')"
        )->execute([self::$tenantId]);
        $unlinkedPaymentMethodId = (int) self::$db->lastInsertId();
        $key = 'pay-debt-journal-failure-' . bin2hex(random_bytes(8));

        $response = $this->requestPayDebt([
            'customer_id' => $fixture['customer_id'],
            'amount' => 10,
            'payment_method_id' => $unlinkedPaymentMethodId,
            'payment_date' => '2026-10-16',
            'branch_id' => self::$branchId,
        ], $key);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->countCustomerDebtPayments($fixture['customer_id']));
        $allocation = self::$db->prepare(
            'SELECT COUNT(*) FROM customer_payment_allocations
             WHERE tenant_id = ? AND sale_id IN (?, ?)'
        );
        $allocation->execute([
            self::$tenantId,
            $fixture['first_sale_id'],
            $fixture['second_sale_id'],
        ]);
        self::assertSame(0, (int) $allocation->fetchColumn());
        $reservation = self::$db->prepare(
            'SELECT COUNT(*) FROM payment_idempotency_keys WHERE tenant_id = ? AND idempotency_key = ?'
        );
        $reservation->execute([self::$tenantId, $key]);
        self::assertSame(0, (int) $reservation->fetchColumn());
    }

    public function testPayDebtRouteRollsBackAllRecordsWhenAllocationFails(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'pay-debt-allocation-failure-' . bin2hex(random_bytes(8));
        $tables = ['payments', 'journal_entries', 'journal_entry_lines', 'customer_payment_allocations'];
        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = (int) self::$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }

        self::$db->exec(
            "CREATE TRIGGER fail_pay_debt_allocation
             BEFORE INSERT ON customer_payment_allocations
             FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration pay-debt allocation failure'"
        );
        try {
            $response = $this->requestPayDebt([
                'customer_id' => $fixture['customer_id'],
                'amount' => 10,
                'payment_method_id' => self::$paymentMethodId,
                'payment_date' => '2026-10-16',
                'branch_id' => self::$branchId,
            ], $key);

            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
            foreach ($tables as $table) {
                self::assertSame(
                    $countsBefore[$table],
                    (int) self::$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
                    "A failed pay-debt allocation must roll back {$table}."
                );
            }
            $reservation = self::$db->prepare(
                'SELECT COUNT(*) FROM payment_idempotency_keys WHERE tenant_id = ? AND idempotency_key = ?'
            );
            $reservation->execute([self::$tenantId, $key]);
            self::assertSame(0, (int) $reservation->fetchColumn());
        } finally {
            self::$db->exec('DROP TRIGGER IF EXISTS fail_pay_debt_allocation');
        }
    }

    public function testCustomerPaymentRoutePostsAndReturnsCachedResponseForMatchingRetry(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'customer-payment-retry-' . bin2hex(random_bytes(8));
        $payload = $this->customerPaymentPayload($fixture['customer_id'], 120);

        $first = $this->requestCustomerPayment($fixture['customer_id'], $payload, $key);
        $retry = $this->requestCustomerPayment($fixture['customer_id'], $payload, $key);

        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getBody());
        $firstBody = json_decode((string) $first->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $retryBody = json_decode((string) $retry->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($firstBody, $retryBody);
        self::assertSame([$fixture['first_sale_id'], $fixture['second_sale_id']], $firstBody['data']['allocated_sale_ids']);
        self::assertSame(1, $this->countCustomerPaymentRecords($fixture['customer_id']));
        self::assertSame(1, $this->countCustomerPaymentJournals($fixture['customer_id']));
    }

    public function testCustomerPaymentRouteRejectsDifferentPayloadForUsedKey(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'customer-payment-conflict-' . bin2hex(random_bytes(8));
        $payload = $this->customerPaymentPayload($fixture['customer_id'], 10);

        self::assertSame(201, $this->requestCustomerPayment($fixture['customer_id'], $payload, $key)->getStatusCode());
        $payload['amount'] = 11;
        $conflict = $this->requestCustomerPayment($fixture['customer_id'], $payload, $key);

        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        self::assertSame(1, $this->countCustomerPaymentRecords($fixture['customer_id']));
    }

    public function testCustomerPaymentRouteRejectsInvalidAndRoundedToZeroAmounts(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        foreach (['not-a-number', INF, 0.004] as $index => $amount) {
            $key = 'customer-payment-invalid-amount-' . $index . '-' . bin2hex(random_bytes(6));
            $payload = $this->customerPaymentPayload($fixture['customer_id'], 1);
            $payload['amount'] = $amount;
            $response = $this->requestCustomerPayment($fixture['customer_id'], $payload, $key);

            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
            $reservation = self::$db->prepare(
                'SELECT COUNT(*) FROM payment_idempotency_keys WHERE tenant_id = ? AND idempotency_key = ?'
            );
            $reservation->execute([self::$tenantId, $key]);
            self::assertSame(0, (int) $reservation->fetchColumn());
        }
        self::assertSame(0, $this->countCustomerPaymentRecords($fixture['customer_id']));
    }

    public function testCustomerPaymentRouteRollsBackAllRecordsWhenAllocationFails(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $key = 'customer-payment-allocation-failure-' . bin2hex(random_bytes(8));
        $tables = ['payments', 'journal_entries', 'journal_entry_lines', 'customer_payment_allocations'];
        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = (int) self::$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }

        self::$db->exec(
            "CREATE TRIGGER fail_customer_payment_allocation
             BEFORE INSERT ON customer_payment_allocations
             FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration allocation failure'"
        );
        try {
            $response = $this->requestCustomerPayment(
                $fixture['customer_id'],
                $this->customerPaymentPayload($fixture['customer_id'], 10),
                $key
            );
            self::assertSame(500, $response->getStatusCode(), (string) $response->getBody());
            foreach ($tables as $table) {
                self::assertSame(
                    $countsBefore[$table],
                    (int) self::$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
                    "A failed allocation must roll back {$table}."
                );
            }
            $reservation = self::$db->prepare(
                'SELECT COUNT(*) FROM payment_idempotency_keys WHERE tenant_id = ? AND idempotency_key = ?'
            );
            $reservation->execute([self::$tenantId, $key]);
            self::assertSame(0, (int) $reservation->fetchColumn());
        } finally {
            self::$db->exec('DROP TRIGGER IF EXISTS fail_customer_payment_allocation');
        }
    }

    public function testConcurrentCustomerPaymentsWithSameKeyAcrossCustomersCreateOnlyOnePayment(): void
    {
        $firstCustomer = $this->createCustomerDebtFixture();
        $secondCustomer = $this->createCustomerDebtFixture();
        $gate = 'customer_payment_gate_' . bin2hex(random_bytes(8));
        $key = 'customer-payment-concurrent-' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE customer_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );

        $workers = [];
        try {
            foreach ([
                'first' => $firstCustomer['customer_id'],
                'second' => $secondCustomer['customer_id'],
            ] as $workerName => $customerId) {
                $input = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'branch_id' => self::$branchId,
                    'payment_method_id' => self::$paymentMethodId,
                    'customer_id' => $customerId,
                    'amount' => 10,
                    'payment_date' => '2026-10-16',
                    'idempotency_key' => $key,
                    'gate' => $gate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/CustomerPaymentMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent customer payment worker.');
                fwrite($pipes[0], $input);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $results = [];
            foreach ($workers as $workerIndex => $worker) {
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $workers[$workerIndex]['process'] = null;
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Customer payment worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Customer payment worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['status'] === 201
            ), json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['status'] === 409
            ), json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            self::assertSame(1, $this->countCustomerPaymentsForCustomers([
                $firstCustomer['customer_id'],
                $secondCustomer['customer_id'],
            ]));
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    proc_close($worker['process']);
                }
                if (is_resource($worker['stdout'])) {
                    fclose($worker['stdout']);
                }
                if (is_resource($worker['stderr'])) {
                    fclose($worker['stderr']);
                }
            }
            self::$db->exec('DROP TABLE IF EXISTS customer_payment_test_barrier');
        }
    }

    public function testConcurrentPayDebtRetriesWithSameKeyCreateOnlyOnePayment(): void
    {
        $fixture = $this->createCustomerDebtFixture();
        $gate = 'customer_debt_gate_' . bin2hex(random_bytes(8));
        $key = 'pay-debt-concurrent-' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE sales_pay_debt_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );

        $workers = [];
        try {
            foreach (['first', 'second'] as $workerName) {
                $input = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'branch_id' => self::$branchId,
                    'payment_method_id' => self::$paymentMethodId,
                    'customer_id' => $fixture['customer_id'],
                    'amount' => 10,
                    'payment_date' => '2026-10-16',
                    'idempotency_key' => $key,
                    'gate' => $gate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SalePayDebtMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent MySQL pay-debt worker.');
                fwrite($pipes[0], $input);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $results = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Pay-debt worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Pay-debt worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertSame([200, 200], array_column($results, 'status'));
            self::assertSame($results[0]['body'], $results[1]['body']);
            self::assertSame(1, $this->countCustomerDebtPayments($fixture['customer_id']));
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    proc_close($worker['process']);
                }
                if (is_resource($worker['stdout'])) {
                    fclose($worker['stdout']);
                }
                if (is_resource($worker['stderr'])) {
                    fclose($worker['stderr']);
                }
            }
            self::$db->exec('DROP TABLE IF EXISTS sales_pay_debt_test_barrier');
        }
    }

    public function testConcurrentSalesCannotOversellTheLastBaseUnits(): void
    {
        $gate = 'sale_stock_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE sale_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        self::setStock(5);

        $workers = [];
        try {
            foreach (['first', 'second'] as $worker) {
                $payload = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'branch_id' => self::$branchId,
                    'cost_center_id' => self::$costCenterId,
                    'payment_method_id' => self::$paymentMethodId,
                    'product_id' => self::$productId,
                    'unit_id' => self::$unitId,
                    'sale_price' => 250,
                    'quantity' => 1,
                    'invoice_number' => $gate . '-' . $worker,
                    'gate' => $gate,
                    'worker' => $worker,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SaleMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent MySQL sale worker.');
                fwrite($pipes[0], $payload);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $results = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Worker did not return a result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 'created'));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 'rejected'));
            $rejected = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['status'] === 'rejected'
            ))[0];
            self::assertStringContainsString('أقل من الكمية المطلوبة', $rejected['message']);

            $stock = self::$db->prepare(
                'SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
            );
            $stock->execute([self::$tenantId, self::$branchId, self::$productId]);
            self::assertEquals(0, (float) $stock->fetchColumn());

            $sales = self::$db->prepare(
                'SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND invoice_number LIKE ?'
            );
            $sales->execute([self::$tenantId, $gate . '-%']);
            self::assertSame(1, (int) $sales->fetchColumn());
            $movements = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(quantity), 0) FROM inventory_transactions
                 WHERE tenant_id = ? AND movement_type = 'out' AND reference_type = 'sale'
                   AND reference_id IN (SELECT id FROM sales WHERE tenant_id = ? AND invoice_number LIKE ?)"
            );
            $movements->execute([self::$tenantId, self::$tenantId, $gate . '-%']);
            [$movementCount, $movementQuantity] = $movements->fetch(PDO::FETCH_NUM);
            self::assertSame(1, (int) $movementCount);
            self::assertEquals(5, (float) $movementQuantity);
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    proc_close($worker['process']);
                }
                if (is_resource($worker['stdout'])) {
                    fclose($worker['stdout']);
                }
                if (is_resource($worker['stderr'])) {
                    fclose($worker['stderr']);
                }
            }
        }
    }

    public function testSaleReturnRestoresTrackedSerialUsingTheValidInventoryStatus(): void
    {
        self::setStock(0);
        self::$db->prepare('UPDATE products SET has_serial_number = 1 WHERE id = ? AND tenant_id = ?')
            ->execute([self::$productId, self::$tenantId]);
        self::$db->prepare(
            "INSERT INTO product_serials (tenant_id, product_id, branch_id, serial_number, status)
             VALUES (?, ?, ?, 'IT-RETURN-SERIAL', 'sold')"
        )->execute([self::$tenantId, self::$productId, self::$branchId]);

        self::$db->beginTransaction();
        try {
            (new ReturnService(self::$db, self::$tenantId, self::$userId))->updateStock(
                self::$productId,
                self::$unitId,
                1,
                self::$branchId,
                9001,
                'sale',
                null,
                null,
                'IT-RETURN-SERIAL'
            );
            self::$db->commit();
        } catch (Throwable $error) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $error;
        }

        $serial = self::$db->prepare(
            'SELECT status, transaction_id FROM product_serials
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ? AND serial_number = ?'
        );
        $serial->execute([self::$tenantId, self::$productId, self::$branchId, 'IT-RETURN-SERIAL']);
        $savedSerial = $serial->fetch();
        self::assertSame('in_stock', $savedSerial['status']);
        self::assertNull($savedSerial['transaction_id']);

        $stock = self::$db->prepare(
            'SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
        );
        $stock->execute([self::$tenantId, self::$branchId, self::$productId]);
        self::assertEquals(5, (float) $stock->fetchColumn());
    }

    public function testPurchaseReturnCannotReduceStockBelowZero(): void
    {
        self::setStock(0);
        self::$db->prepare(
            "INSERT INTO product_serials (tenant_id, product_id, branch_id, serial_number, status)
             VALUES (?, ?, ?, 'IT-PURCHASE-RETURN-SERIAL', 'in_stock')"
        )->execute([self::$tenantId, self::$productId, self::$branchId]);

        self::$db->beginTransaction();
        try {
            (new ReturnService(self::$db, self::$tenantId, self::$userId))->updateStock(
                self::$productId,
                self::$unitId,
                1,
                self::$branchId,
                9002,
                'purchase',
                null,
                null,
                'IT-PURCHASE-RETURN-SERIAL'
            );
            self::fail('A purchase return must not make branch inventory negative.');
        } catch (InsufficientStockException $error) {
            self::assertSame(self::$productId, $error->productId);
            self::assertEquals(0, $error->availableQty);
            self::assertEquals(5, $error->requestedQty);
        } finally {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
        }

        $stock = self::$db->prepare(
            'SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
        );
        $stock->execute([self::$tenantId, self::$branchId, self::$productId]);
        self::assertEquals(0, (float) $stock->fetchColumn());
        self::assertSame('in_stock', self::$db->query(
            "SELECT status FROM product_serials WHERE serial_number = 'IT-PURCHASE-RETURN-SERIAL'"
        )->fetchColumn());
    }

    public function testPurchaseReturnMarksSerialDamagedAndLinksItsMovement(): void
    {
        self::setStock(5);
        self::$db->prepare(
            "INSERT INTO product_serials (tenant_id, product_id, branch_id, serial_number, status)
             VALUES (?, ?, ?, 'IT-PURCHASE-RETURN-OK', 'in_stock')"
        )->execute([self::$tenantId, self::$productId, self::$branchId]);

        self::$db->beginTransaction();
        try {
            (new ReturnService(self::$db, self::$tenantId, self::$userId))->updateStock(
                self::$productId,
                self::$unitId,
                1,
                self::$branchId,
                9003,
                'purchase',
                null,
                null,
                'IT-PURCHASE-RETURN-OK'
            );
            self::$db->commit();
        } catch (Throwable $error) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $error;
        }

        $serial = self::$db->prepare(
            'SELECT status, transaction_id FROM product_serials
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ? AND serial_number = ?'
        );
        $serial->execute([self::$tenantId, self::$productId, self::$branchId, 'IT-PURCHASE-RETURN-OK']);
        $savedSerial = $serial->fetch();
        self::assertSame('damaged', $savedSerial['status']);
        self::assertGreaterThan(0, (int) $savedSerial['transaction_id']);

        $movement = self::$db->prepare(
            "SELECT id, movement_type, quantity FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'return' AND reference_id = 9003"
        );
        $movement->execute([self::$tenantId]);
        $savedMovement = $movement->fetch();
        self::assertSame((int) $savedMovement['id'], (int) $savedSerial['transaction_id']);
        self::assertSame('out', $savedMovement['movement_type']);
        self::assertEquals(1, (float) $savedMovement['quantity']);
    }

    public function testSaleReturnRejectsSerialNotRecordedAsSoldWithoutChangingStock(): void
    {
        self::setStock(0);
        self::$db->prepare('UPDATE products SET has_serial_number = 1 WHERE id = ? AND tenant_id = ?')
            ->execute([self::$productId, self::$tenantId]);

        self::$db->beginTransaction();
        try {
            (new ReturnService(self::$db, self::$tenantId, self::$userId))->updateStock(
                self::$productId,
                self::$unitId,
                1,
                self::$branchId,
                9004,
                'sale',
                null,
                null,
                'IT-UNKNOWN-SERIAL'
            );
            self::fail('A sale return must reject a serial number not sold in this branch.');
        } catch (DomainException $error) {
            self::assertStringContainsString('غير مسجل كمباع', $error->getMessage());
        } finally {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
        }

        $stock = self::$db->prepare(
            'SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
        );
        $stock->execute([self::$tenantId, self::$branchId, self::$productId]);
        self::assertEquals(0, (float) $stock->fetchColumn());
        $movements = self::$db->prepare(
            "SELECT COUNT(*) FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'return' AND reference_id = 9004"
        );
        $movements->execute([self::$tenantId]);
        self::assertSame(0, (int) $movements->fetchColumn());
    }

    public function testSaleReturnValuesInventoryAtOriginalSaleDateCost(): void
    {
        self::setStock(0);
        self::$db->prepare(
            'UPDATE products SET purchase_price = 0, has_serial_number = 0 WHERE id = ? AND tenant_id = ?'
        )->execute([self::$productId, self::$tenantId]);

        $supplierAccount = self::$db->prepare(
            "INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, 'Sales return test payable', 'liability', 0, 0)"
        );
        $supplierAccount->execute([self::$tenantId, 'IT-SALES-AP-' . bin2hex(random_bytes(4))]);
        $supplierAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO suppliers (tenant_id, branch_id, name, email, address, account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            self::$tenantId,
            self::$branchId,
            'Sales return costing supplier',
            'sales-return-cost-' . bin2hex(random_bytes(4)) . '@example.invalid',
            'Integration test',
            $supplierAccountId,
        ]);
        $supplierId = (int) self::$db->lastInsertId();

        $purchaseStmt = self::$db->prepare(
            "INSERT INTO purchases
                (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, status, branch_id)
             VALUES (?, ?, ?, ?, 10, 'approved', ?)"
        );
        $insertPurchaseItem = self::$db->prepare(
            'INSERT INTO purchase_items
                (tenant_id, purchase_id, product_id, quantity, price, cost, total, branch_id, unit_id)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        foreach ([
            ['IT-RETURN-COST-BEFORE', '2026-01-01 00:00:00', 10],
            ['IT-RETURN-COST-AFTER', '2026-02-01 00:00:00', 30],
        ] as [$invoiceNumber, $invoiceDate, $unitCost]) {
            $purchaseStmt->execute([
                self::$tenantId,
                $supplierId,
                $invoiceNumber,
                $invoiceDate,
                self::$branchId,
            ]);
            $purchaseId = (int) self::$db->lastInsertId();
            $insertPurchaseItem->execute([
                self::$tenantId,
                $purchaseId,
                self::$productId,
                $unitCost,
                $unitCost,
                $unitCost,
                self::$branchId,
                self::$unitId,
            ]);
        }

        self::$db->prepare(
            "INSERT INTO sales
                (tenant_id, sale_date, total_amount, net_total_amount, paid_amount, status,
                 user_id, branch_id, invoice_number, payment_method_id, cost_center_id)
             VALUES (?, '2026-01-15 12:00:00', 250, 250, 250, 'paid', ?, ?, ?, ?, ?)"
        )->execute([
            self::$tenantId,
            self::$userId,
            self::$branchId,
            'IT-RETURN-COST-SALE-' . bin2hex(random_bytes(4)),
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        $saleId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            "INSERT INTO returns
                (return_number, invoice_id, invoice_number, return_type, return_date,
                 created_by, tenant_id, sale_id, branch_id)
             VALUES (?, ?, ?, 'sale', '2026-10-05', ?, ?, ?, ?)"
        )->execute([
            'IT-RETURN-COST-' . bin2hex(random_bytes(4)),
            $saleId,
            'IT-RETURN-COST-SOURCE',
            self::$userId,
            self::$tenantId,
            $saleId,
            self::$branchId,
        ]);
        $returnId = (int) self::$db->lastInsertId();

        self::$db->beginTransaction();
        try {
            (new ReturnService(self::$db, self::$tenantId, self::$userId))->updateStock(
                self::$productId,
                self::$unitId,
                1,
                self::$branchId,
                $returnId,
                'sale'
            );
            self::$db->commit();
        } catch (Throwable $error) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $error;
        }

        $valuation = self::$db->prepare(
            'SELECT quantity_cost FROM branch_products
             WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
        );
        $valuation->execute([self::$tenantId, self::$branchId, self::$productId]);
        self::assertEquals(50, (float) $valuation->fetchColumn());

        $movement = self::$db->prepare(
            "SELECT unit_cost, total_cost FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'return' AND reference_id = ?"
        );
        $movement->execute([self::$tenantId, $returnId]);
        $savedMovement = $movement->fetch();
        self::assertEquals(50, (float) $savedMovement['unit_cost']);
        self::assertEquals(50, (float) $savedMovement['total_cost']);
    }

    public function testReturnApiLifecycleSettlesCreditAndSupplierCashRefundAtomically(): void
    {
        $accountIds = [];
        $account = self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        foreach ([
            '1101' => ['Integration receivables', 'asset'],
            '2101' => ['Integration payables', 'liability'],
            '4002' => ['Integration sales returns', 'expense'],
            '5002' => ['Integration purchase returns', 'revenue'],
            '5103' => ['Integration COGS', 'expense'],
        ] as $code => [$name, $type]) {
            $account->execute([self::$tenantId, $code, $name, $type]);
            $accountIds[$code] = (int) self::$db->lastInsertId();
        }

        self::$db->prepare('INSERT INTO customers (tenant_id, branch_id, name, account_id) VALUES (?, ?, ?, ?)')
            ->execute([self::$tenantId, self::$branchId, 'Return integration customer', $accountIds['1101']]);
        $customerId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO suppliers (tenant_id, branch_id, name, email, address, account_id) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            self::$tenantId,
            self::$branchId,
            'Return integration supplier',
            'return-supplier@example.invalid',
            'Integration test',
            $accountIds['2101'],
        ]);
        $supplierId = (int) self::$db->lastInsertId();

        self::$db->prepare(
            "INSERT INTO roles (name, display_name, is_system_role, tenant_id)
             VALUES ('return_integration_operator', 'Return integration operator', 0, ?)"
        )->execute([self::$tenantId]);
        $roleId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            "INSERT INTO permissions (name, description, category, tenant_id)
             VALUES ('return.create', 'Create returns in integration test', 'return_management', ?)"
        )->execute([self::$tenantId]);
        $permissionId = (int) self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO users_role (user_id, role_id, tenant_id) VALUES (?, ?, ?)')
            ->execute([self::$userId, $roleId, self::$tenantId]);
        self::$db->prepare('UPDATE users SET role_id = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$roleId, self::$userId, self::$tenantId]);
        self::$db->prepare(
            "INSERT INTO subscriptions
                (tenant_id, plan_id, start_date, end_date, status, payment_status, auto_renew, user_id)
             SELECT ?, id, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active', 'paid', 0, ?
             FROM plans WHERE code = 'sales-mysql-it'"
        )->execute([self::$tenantId, self::$userId]);

        $cashAccountId = (int) self::$db->query(
            "SELECT id FROM accounts WHERE tenant_id = " . self::$tenantId . " AND code = 'IT-SALES-BANK' LIMIT 1"
        )->fetchColumn();
        $cashMethod = self::$db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES ('Return integration cash', 'cash', ?, ?, 1, 'cash')"
        );
        $cashMethod->execute([$cashAccountId, self::$tenantId]);
        $cashMethodId = (int) self::$db->lastInsertId();

        self::assertSame(401, $this->requestReturn($this->returnPayload(
            'purchase',
            1,
            $cashMethodId
        ), false)->getStatusCode());
        self::assertSame(403, $this->requestReturn($this->returnPayload(
            'purchase',
            1,
            $cashMethodId
        ))->getStatusCode());
        self::$db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
        )->execute([$roleId, $permissionId, self::$tenantId]);
        self::$db->prepare(
            'INSERT INTO permissions (name, description, category, tenant_id) VALUES (?, ?, ?, ?)'
        )->execute([
            Permissions::RETURN_APPROVE,
            'Approve sale return payouts in integration tests',
            'return_management',
            self::$tenantId,
        ]);
        $approvePermissionId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
        )->execute([$roleId, $approvePermissionId, self::$tenantId]);

        $sale = self::$db->prepare(
            "INSERT INTO sales
                (tenant_id, customer_id, total_amount, net_total_amount, paid_amount, status, user_id,
                 total_items, branch_id, invoice_number, payment_method_id, tax_rate, tax_amount, cost_center_id)
             VALUES (?, ?, 250, 250, 0, 'completed', ?, 1, ?, ?, ?, 0, 0, ?)"
        );
        $sale->execute([
            self::$tenantId,
            $customerId,
            self::$userId,
            self::$branchId,
            'IT-RETURN-SALE-' . bin2hex(random_bytes(4)),
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        $saleId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO sales_items
                (sale_id, product_id, unit_id, conversion_factor, quantity, sale_price, purchase_price,
                 net_total, tenant_id, created_by)
             VALUES (?, ?, ?, 5, 1, 50, 50, 250, ?, ?)'
        )->execute([$saleId, self::$productId, self::$unitId, self::$tenantId, self::$userId]);

        $sale->execute([
            self::$tenantId,
            $customerId,
            self::$userId,
            self::$branchId,
            'IT-CONCURRENT-RETURN-' . bin2hex(random_bytes(4)),
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        $concurrentSaleId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO sales_items
                (sale_id, product_id, unit_id, conversion_factor, quantity, sale_price, purchase_price,
                 net_total, tenant_id, created_by)
             VALUES (?, ?, ?, 5, 1, 50, 50, 250, ?, ?)'
        )->execute([$concurrentSaleId, self::$productId, self::$unitId, self::$tenantId, self::$userId]);

        $gate = 'sale_return_concurrency_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE sale_return_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $workers = [];
        try {
            foreach (['first', 'second'] as $workerName) {
                $workerPayload = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => $roleId,
                    'sale_id' => $concurrentSaleId,
                    'product_id' => self::$productId,
                    'unit_id' => self::$unitId,
                    'payment_method_id' => self::$paymentMethodId,
                    'cost_center_id' => self::$costCenterId,
                    'return_date' => date('Y-m-d'),
                    'gate' => $gate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SaleReturnMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent return worker.');
                fwrite($pipes[0], $workerPayload);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $returnResults = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Return worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Return worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $returnResults[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertEqualsCanonicalizing(
                [200, 422],
                array_column($returnResults, 'status'),
                json_encode($returnResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            self::assertCount(1, array_filter(
                $returnResults,
                static fn (array $result): bool => $result['status'] === 200
            ));
            $failedReturn = array_values(array_filter(
                $returnResults,
                static fn (array $result): bool => $result['status'] === 422
            ))[0];
            self::assertStringContainsString(
                'كمية الإرجاع تتجاوز الكمية المتبقية',
                (string) ($failedReturn['body']['message'] ?? ''),
                json_encode($returnResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $concurrentReturns = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(ri.quantity), 0)
                 FROM returns r
                 JOIN return_items ri ON ri.return_id = r.id AND ri.tenant_id = r.tenant_id
                 WHERE r.tenant_id = ? AND r.return_type = 'sale' AND r.sale_id = ?"
            );
            $concurrentReturns->execute([self::$tenantId, $concurrentSaleId]);
            [$returnCount, $returnedQuantity] = $concurrentReturns->fetch(PDO::FETCH_NUM);
            self::assertSame(1, (int) $returnCount);
            self::assertEquals(1, (float) $returnedQuantity);
            self::assertSame('returned', self::$db->query(
                'SELECT status FROM sales WHERE id = ' . $concurrentSaleId . ' AND tenant_id = ' . self::$tenantId
            )->fetchColumn());

            $separateSaleIds = [];
            foreach (['left', 'right'] as $suffix) {
                $separateAccountCode = 'IT-RET-RACE-' . strtoupper($suffix) . '-' . bin2hex(random_bytes(3));
                $account->execute([
                    self::$tenantId,
                    $separateAccountCode,
                    'Return number race receivable ' . $suffix,
                    'asset',
                ]);
                $separateAccountId = (int) self::$db->lastInsertId();
                self::$db->prepare(
                    'INSERT INTO customers (tenant_id, branch_id, name, account_id) VALUES (?, ?, ?, ?)'
                )->execute([
                    self::$tenantId,
                    self::$branchId,
                    'Return number race customer ' . $suffix,
                    $separateAccountId,
                ]);
                $separateCustomerId = (int) self::$db->lastInsertId();
                $sale->execute([
                    self::$tenantId,
                    $separateCustomerId,
                    self::$userId,
                    self::$branchId,
                    'IT-RETURN-NUMBER-' . strtoupper($suffix) . '-' . bin2hex(random_bytes(4)),
                    self::$paymentMethodId,
                    self::$costCenterId,
                ]);
                $separateSaleIds[] = (int) self::$db->lastInsertId();
                self::$db->prepare(
                    'INSERT INTO sales_items
                        (sale_id, product_id, unit_id, conversion_factor, quantity, sale_price, purchase_price,
                         net_total, tenant_id, created_by)
                     VALUES (?, ?, ?, 5, 1, 50, 50, 250, ?, ?)'
                )->execute([
                    end($separateSaleIds),
                    self::$productId,
                    self::$unitId,
                    self::$tenantId,
                    self::$userId,
                ]);
            }

            $numberRaceGate = 'return_number_concurrency_' . bin2hex(random_bytes(8));
            foreach (['left', 'right'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => $roleId,
                    'sale_id' => $separateSaleIds[$index],
                    'product_id' => self::$productId,
                    'unit_id' => self::$unitId,
                    'payment_method_id' => self::$paymentMethodId,
                    'cost_center_id' => self::$costCenterId,
                    'return_date' => date('Y-m-d'),
                    'gate' => $numberRaceGate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SaleReturnMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start a return-number worker.');
                fwrite($pipes[0], $workerPayload);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $numberRaceResults = [];
            $firstResultIndex = count($workers) - 2;
            foreach ([$firstResultIndex, $firstResultIndex + 1] as $workerIndex) {
                $worker = $workers[$workerIndex];
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $workers[$workerIndex]['process'] = null;
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Return-number worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Return-number worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $numberRaceResults[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame(
                [200, 200],
                array_column($numberRaceResults, 'status'),
                json_encode($numberRaceResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $returnNumbers = self::$db->prepare(
                'SELECT return_number FROM returns
                 WHERE tenant_id = ? AND sale_id IN (?, ?)
                 ORDER BY sale_id'
            );
            $returnNumbers->execute([self::$tenantId, $separateSaleIds[0], $separateSaleIds[1]]);
            $numbers = $returnNumbers->fetchAll(PDO::FETCH_COLUMN);
            self::assertCount(2, $numbers);
            self::assertCount(2, array_unique($numbers), 'Concurrent returns for separate sales must have distinct numbers.');

            $liquidityAccountCode = 'IT-RET-LIQUIDITY-' . bin2hex(random_bytes(4));
            $account->execute([
                self::$tenantId,
                $liquidityAccountCode,
                'Sale return concurrency liquidity account',
                'asset',
            ]);
            $liquidityAccountId = (int) self::$db->lastInsertId();
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$liquidityAccountId, $cashMethodId, self::$tenantId]);
            $liquidityMethodId = $cashMethodId;

            $liquiditySaleIds = [];
            $liquiditySaleInsert = self::$db->prepare(
                "INSERT INTO sales
                    (tenant_id, customer_id, total_amount, net_total_amount, paid_amount, status, user_id,
                     total_items, branch_id, invoice_number, payment_method_id, tax_rate, tax_amount, cost_center_id)
                 VALUES (?, NULL, 60, 60, 60, 'completed', ?, 1, ?, ?, ?, 0, 0, ?)"
            );
            foreach (['first', 'second'] as $suffix) {
                $liquiditySaleInsert->execute([
                    self::$tenantId,
                    self::$userId,
                    self::$branchId,
                    'IT-RET-LIQ-' . strtoupper($suffix) . '-' . bin2hex(random_bytes(4)),
                    $liquidityMethodId,
                    self::$costCenterId,
                ]);
                $liquiditySaleIds[] = (int) self::$db->lastInsertId();
                self::$db->prepare(
                    'INSERT INTO sales_items
                        (sale_id, product_id, unit_id, conversion_factor, quantity, sale_price, purchase_price,
                         net_total, tenant_id, created_by)
                     VALUES (?, ?, ?, 5, 1, 12, 12, 60, ?, ?)'
                )->execute([
                    end($liquiditySaleIds),
                    self::$productId,
                    self::$unitId,
                    self::$tenantId,
                    self::$userId,
                ]);
                self::$db->prepare(
                    "INSERT INTO payments
                        (tenant_id, payment_date, sale_id, amount, payment_method_id, created_by, type, status)
                     VALUES (?, CURDATE(), ?, 60, ?, ?, 'sale', 'completed')"
                )->execute([
                    self::$tenantId,
                    end($liquiditySaleIds),
                    $liquidityMethodId,
                    self::$userId,
                ]);
            }

            $equityCode = 'IT-RET-LIQ-EQUITY-' . bin2hex(random_bytes(4));
            $account->execute([
                self::$tenantId,
                $equityCode,
                'Sale return concurrency opening balance',
                'equity',
            ]);
            $equityAccountId = (int) self::$db->lastInsertId();
            self::$db->prepare(
                "INSERT INTO journal_entries
                    (tenant_id, description, created_by, status, entry_date, reference_type, reference_id, cost_center_id)
                 VALUES (?, 'Sale return concurrency opening liquidity', ?, 'posted', ?, 'sale', ?, ?)"
            )->execute([
                self::$tenantId,
                self::$userId,
                date('Y-m-d'),
                $liquiditySaleIds[0],
                self::$costCenterId,
            ]);
            $openingJournalId = (int) self::$db->lastInsertId();
            $openingLine = self::$db->prepare(
                'INSERT INTO journal_entry_lines
                    (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $openingLine->execute([
                $openingJournalId,
                $liquidityAccountId,
                100,
                0,
                'Available cash for concurrent sale return test',
                self::$tenantId,
                self::$costCenterId,
            ]);
            $openingLine->execute([
                $openingJournalId,
                $equityAccountId,
                0,
                100,
                'Opening balance offset',
                self::$tenantId,
                self::$costCenterId,
            ]);

            $liquidityGate = 'sale_return_liquidity_' . bin2hex(random_bytes(8));
            foreach (['first', 'second'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => $roleId,
                    'sale_id' => $liquiditySaleIds[$index],
                    'product_id' => self::$productId,
                    'unit_id' => self::$unitId,
                    'payment_method_id' => $liquidityMethodId,
                    'cost_center_id' => self::$costCenterId,
                    'return_date' => date('Y-m-d'),
                    'paid_amount' => 60,
                    'refund_mode' => 'cash',
                    'unit_price' => 12,
                    'item_amount' => 60,
                    'gate' => $liquidityGate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SaleReturnMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start a sale return liquidity worker.');
                fwrite($pipes[0], $workerPayload);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $liquidityResults = [];
            $firstLiquidityWorkerIndex = count($workers) - 2;
            foreach ([$firstLiquidityWorkerIndex, $firstLiquidityWorkerIndex + 1] as $workerIndex) {
                $worker = $workers[$workerIndex];
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $workers[$workerIndex]['process'] = null;
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Sale return liquidity worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Sale return liquidity worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $liquidityResults[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertEqualsCanonicalizing(
                [200, 402],
                array_column($liquidityResults, 'status'),
                json_encode($liquidityResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $rejectedLiquidityResult = array_values(array_filter(
                $liquidityResults,
                static fn (array $result): bool => $result['status'] === 402
            ))[0];
            self::assertSame(
                'insufficient_liquidity',
                $rejectedLiquidityResult['body']['error_code'] ?? null,
                json_encode($rejectedLiquidityResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            self::assertIsArray($rejectedLiquidityResult['body']['details'] ?? null);
            self::assertSame(
                $liquidityAccountId,
                (int) ($rejectedLiquidityResult['body']['details']['account_id'] ?? 0)
            );
            $liquidityReturns = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(paid_amount), 0)
                 FROM returns
                 WHERE tenant_id = ? AND sale_id IN (?, ?) AND return_type = 'sale'"
            );
            $liquidityReturns->execute([
                self::$tenantId,
                $liquiditySaleIds[0],
                $liquiditySaleIds[1],
            ]);
            [$liquidityReturnCount, $liquidityRefundTotal] = $liquidityReturns->fetch(PDO::FETCH_NUM);
            self::assertSame(1, (int) $liquidityReturnCount);
            self::assertEquals(60, (float) $liquidityRefundTotal);
            $savedLiquidityReturn = self::$db->prepare(
                'SELECT id, journal_entry_id FROM returns
                 WHERE tenant_id = ? AND sale_id IN (?, ?) AND return_type = ?'
            );
            $savedLiquidityReturn->execute([
                self::$tenantId,
                $liquiditySaleIds[0],
                $liquiditySaleIds[1],
                'sale',
            ]);
            $savedLiquidityReturnRow = $savedLiquidityReturn->fetch(PDO::FETCH_ASSOC);
            self::assertNotFalse($savedLiquidityReturnRow);
            self::assertJournalBalanced((int) $savedLiquidityReturnRow['journal_entry_id']);
            $refundPayments = self::$db->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE tenant_id = ? AND return_id = ? AND type = 'return_payment' AND amount = 60"
            );
            $refundPayments->execute([self::$tenantId, (int) $savedLiquidityReturnRow['id']]);
            self::assertSame(1, (int) $refundPayments->fetchColumn());
            $cashOutflows = self::$db->prepare(
                "SELECT COUNT(*) FROM cash_transactions
                 WHERE tenant_id = ? AND return_id IN (
                    SELECT id FROM returns WHERE tenant_id = ? AND sale_id IN (?, ?)
                 ) AND type = 'return_payment' AND amount = 60"
            );
            $cashOutflows->execute([
                self::$tenantId,
                self::$tenantId,
                $liquiditySaleIds[0],
                $liquiditySaleIds[1],
            ]);
            self::assertSame(1, (int) $cashOutflows->fetchColumn());
            $liquidityJournalBalance = self::$db->prepare(
                "SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ?"
            );
            $liquidityJournalBalance->execute([$liquidityAccountId, self::$tenantId]);
            self::assertEquals(40, (float) $liquidityJournalBalance->fetchColumn());

            self::setStock(30);
            $payableJournalId = null;
            self::$db->prepare(
                "INSERT INTO journal_entries
                    (tenant_id, description, created_by, status, entry_date, reference_type, cost_center_id)
                 VALUES (?, 'Supplier payable for concurrent purchase returns', ?, 'posted', ?, 'manual', ?)"
            )->execute([
                self::$tenantId,
                self::$userId,
                date('Y-m-d'),
                self::$costCenterId,
            ]);
            $payableJournalId = (int) self::$db->lastInsertId();
            $payableLine = self::$db->prepare(
                'INSERT INTO journal_entry_lines
                    (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $payableLine->execute([
                $payableJournalId,
                $accountIds['2101'],
                0,
                100,
                'Supplier opening payable',
                self::$tenantId,
                self::$costCenterId,
            ]);
            $payableLine->execute([
                $payableJournalId,
                $equityAccountId,
                100,
                0,
                'Supplier payable opening offset',
                self::$tenantId,
                self::$costCenterId,
            ]);
            $initialSupplierPayable = self::$db->prepare(
                'SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ?'
            );
            $initialSupplierPayable->execute([$accountIds['2101'], self::$tenantId]);
            self::assertEquals(100, (float) $initialSupplierPayable->fetchColumn());

            $concurrentPurchaseIds = [];
            $purchaseInsert = self::$db->prepare(
                "INSERT INTO purchases
                    (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, paid_amount, user_id,
                     total_items, status, branch_id, payment_method_id, tax_rate, tax_amount, cost_center_id)
                 VALUES (?, ?, ?, CURDATE(), 80, 80, ?, 1, 'paid', ?, ?, 0, 0, ?)"
            );
            foreach (['first', 'second'] as $suffix) {
                $purchaseInsert->execute([
                    self::$tenantId,
                    $supplierId,
                    'IT-CONCURRENT-PURCHASE-RETURN-' . strtoupper($suffix) . '-' . bin2hex(random_bytes(4)),
                    self::$userId,
                    self::$branchId,
                    self::$paymentMethodId,
                    self::$costCenterId,
                ]);
                $concurrentPurchaseIds[] = (int) self::$db->lastInsertId();
                self::$db->prepare(
                    'INSERT INTO purchase_items
                        (tenant_id, purchase_id, product_id, quantity, price, unit_id, cost, total, subtotal, branch_id)
                     VALUES (?, ?, ?, 1, 80, ?, 80, 80, 80, ?)'
                )->execute([
                    self::$tenantId,
                    end($concurrentPurchaseIds),
                    self::$productId,
                    self::$unitId,
                    self::$branchId,
                ]);
                self::$db->prepare(
                    "INSERT INTO payments
                        (tenant_id, payment_date, purchase_id, amount, payment_method_id, created_by, type, status)
                     VALUES (?, CURDATE(), ?, 80, ?, ?, 'supplier_payment', 'completed')"
                )->execute([
                    self::$tenantId,
                    end($concurrentPurchaseIds),
                    $cashMethodId,
                    self::$userId,
                ]);
            }

            $supplierReturnGate = 'purchase_return_settlement_' . bin2hex(random_bytes(8));
            foreach (['first', 'second'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => $roleId,
                    'return_type' => 'purchase',
                    'invoice_id' => $concurrentPurchaseIds[$index],
                    'product_id' => self::$productId,
                    'unit_id' => self::$unitId,
                    'payment_method_id' => $cashMethodId,
                    'cost_center_id' => self::$costCenterId,
                    'return_date' => date('Y-m-d'),
                    'refund_mode' => 'auto',
                    'unit_price' => 80,
                    'item_amount' => 80,
                    'gate' => $supplierReturnGate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SaleReturnMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start a purchase return settlement worker.');
                fwrite($pipes[0], $workerPayload);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $supplierReturnResults = [];
            $firstSupplierReturnWorkerIndex = count($workers) - 2;
            foreach ([$firstSupplierReturnWorkerIndex, $firstSupplierReturnWorkerIndex + 1] as $workerIndex) {
                $worker = $workers[$workerIndex];
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = proc_close($worker['process']);
                $workers[$workerIndex]['process'] = null;
                $resultLine = null;
                foreach (preg_split('/\R/', (string) $stdout) as $line) {
                    if (str_starts_with($line, 'WORKER_RESULT:')) {
                        $resultLine = substr($line, strlen('WORKER_RESULT:'));
                    }
                }
                self::assertSame(0, $exitCode, "Purchase return worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Purchase return worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $supplierReturnResults[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame(
                [200, 200],
                array_column($supplierReturnResults, 'status'),
                json_encode($supplierReturnResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $supplierReturns = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(grand_total), 0), COALESCE(SUM(paid_amount), 0)
                 FROM returns
                 WHERE tenant_id = ? AND purchase_id IN (?, ?) AND return_type = 'purchase'"
            );
            $supplierReturns->execute([
                self::$tenantId,
                $concurrentPurchaseIds[0],
                $concurrentPurchaseIds[1],
            ]);
            [$supplierReturnCount, $supplierReturnGrandTotal, $supplierRefundTotal] = $supplierReturns->fetch(PDO::FETCH_NUM);
            self::assertSame(2, (int) $supplierReturnCount);
            self::assertEquals(160, (float) $supplierReturnGrandTotal);
            self::assertEquals(60, (float) $supplierRefundTotal);
            $supplierReturnAmounts = self::$db->prepare(
                "SELECT paid_amount FROM returns
                 WHERE tenant_id = ? AND purchase_id IN (?, ?) AND return_type = 'purchase'
                 ORDER BY paid_amount"
            );
            $supplierReturnAmounts->execute([
                self::$tenantId,
                $concurrentPurchaseIds[0],
                $concurrentPurchaseIds[1],
            ]);
            self::assertEquals([0.0, 60.0], array_map(
                'floatval',
                $supplierReturnAmounts->fetchAll(PDO::FETCH_COLUMN)
            ));
            $supplierReturnJournals = self::$db->prepare(
                'SELECT journal_entry_id FROM returns
                 WHERE tenant_id = ? AND purchase_id IN (?, ?) AND return_type = ?'
            );
            $supplierReturnJournals->execute([
                self::$tenantId,
                $concurrentPurchaseIds[0],
                $concurrentPurchaseIds[1],
                'purchase',
            ]);
            foreach ($supplierReturnJournals->fetchAll(PDO::FETCH_COLUMN) as $journalEntryId) {
                self::assertJournalBalanced((int) $journalEntryId);
            }
            $supplierRefundPayments = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(amount), 0)
                 FROM payments
                 WHERE tenant_id = ? AND purchase_id IN (?, ?) AND type = 'payment_for_return'"
            );
            $supplierRefundPayments->execute([
                self::$tenantId,
                $concurrentPurchaseIds[0],
                $concurrentPurchaseIds[1],
            ]);
            [$supplierRefundPaymentCount, $supplierRefundPaymentTotal] = $supplierRefundPayments->fetch(PDO::FETCH_NUM);
            self::assertSame(2, (int) $supplierRefundPaymentCount);
            self::assertEquals(60, (float) $supplierRefundPaymentTotal);
            $supplierReceipts = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(amount), 0)
                 FROM cash_transactions
                 WHERE tenant_id = ? AND return_id IN (
                    SELECT id FROM returns WHERE tenant_id = ? AND purchase_id IN (?, ?)
                 ) AND type = 'return_receipt'"
            );
            $supplierReceipts->execute([
                self::$tenantId,
                self::$tenantId,
                $concurrentPurchaseIds[0],
                $concurrentPurchaseIds[1],
            ]);
            [$supplierReceiptCount, $supplierReceiptTotal] = $supplierReceipts->fetch(PDO::FETCH_NUM);
            self::assertSame(1, (int) $supplierReceiptCount);
            self::assertEquals(60, (float) $supplierReceiptTotal);
            $supplierLedgerBalance = self::$db->prepare(
                'SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ?'
            );
            $supplierLedgerBalance->execute([$accountIds['2101'], self::$tenantId]);
            self::assertEquals(0, (float) $supplierLedgerBalance->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    foreach ([$worker['stdout'], $worker['stderr']] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($worker['process']);
                }
            }
            self::$db->exec('DROP TABLE IF EXISTS sale_return_test_barrier');
        }

        $purchase = self::$db->prepare(
            "INSERT INTO purchases
                (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, paid_amount, user_id,
                 total_items, status, branch_id, payment_method_id, tax_rate, tax_amount, cost_center_id)
             VALUES (?, ?, ?, '2026-01-01 12:00:00', 250, 250, ?, 1, 'paid', ?, ?, 0, 0, ?)"
        );
        $purchase->execute([
            self::$tenantId,
            $supplierId,
            'IT-RETURN-PURCHASE-' . bin2hex(random_bytes(4)),
            self::$userId,
            self::$branchId,
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        $purchaseId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO purchase_items
                (tenant_id, purchase_id, product_id, quantity, price, unit_id, cost, total, subtotal, branch_id)
             VALUES (?, ?, ?, 1, 250, ?, 250, 250, 250, ?)'
        )->execute([self::$tenantId, $purchaseId, self::$productId, self::$unitId, self::$branchId]);
        self::$db->prepare(
            "INSERT INTO payments
                (tenant_id, payment_date, purchase_id, amount, payment_method_id, created_by, type, status)
             VALUES (?, CURDATE(), ?, 250, ?, ?, 'supplier_payment', 'completed')"
        )->execute([self::$tenantId, $purchaseId, self::$paymentMethodId, self::$userId]);

        $rollbackTables = ['returns', 'return_items', 'payments', 'cash_transactions', 'journal_entries', 'inventory_transactions'];
        $rollbackCounts = [];
        foreach ($rollbackTables as $table) {
            $rollbackCounts[$table] = (int) self::$db->query(
                "SELECT COUNT(*) FROM {$table} WHERE tenant_id = " . self::$tenantId
            )->fetchColumn();
        }

        self::setStock(0);
        $failedPurchaseReturn = $this->requestReturn($this->returnPayload(
            'purchase',
            $purchaseId,
            $cashMethodId
        ));
        self::assertSame(400, $failedPurchaseReturn->getStatusCode(), (string) $failedPurchaseReturn->getBody());
        self::assertSame(0, (int) self::$db->query(
            'SELECT COUNT(*) FROM returns WHERE tenant_id = ' . self::$tenantId . ' AND purchase_id = ' . $purchaseId
        )->fetchColumn());
        foreach ($rollbackTables as $table) {
            self::assertSame($rollbackCounts[$table], (int) self::$db->query(
                "SELECT COUNT(*) FROM {$table} WHERE tenant_id = " . self::$tenantId
            )->fetchColumn(), "Failed return must roll back {$table}.");
        }

        self::setStock(20);
        $saleReturnResponse = $this->requestReturn($this->returnPayload(
            'sale',
            $saleId,
            self::$paymentMethodId,
            'credit_note'
        ));
        self::assertSame(200, $saleReturnResponse->getStatusCode(), (string) $saleReturnResponse->getBody());
        $saleReturnId = (int) json_decode((string) $saleReturnResponse->getBody(), true)['data']['return_id'];

        $saleReturn = self::$db->prepare(
            'SELECT paid_amount, is_cash, journal_entry_id FROM returns WHERE id = ? AND tenant_id = ?'
        );
        $saleReturn->execute([$saleReturnId, self::$tenantId]);
        $savedSaleReturn = $saleReturn->fetch();
        self::assertEquals(0, (float) $savedSaleReturn['paid_amount']);
        self::assertSame(0, (int) $savedSaleReturn['is_cash']);
        self::assertGreaterThan(0, (int) $savedSaleReturn['journal_entry_id']);
        self::assertEquals(250, (float) self::$db->query(
            'SELECT allocated_amount FROM return_credit_allocations
             WHERE tenant_id = ' . self::$tenantId . ' AND return_id = ' . $saleReturnId . ' AND sale_id = ' . $saleId
        )->fetchColumn());
        self::assertSame('returned', self::$db->query(
            'SELECT status FROM sales WHERE id = ' . $saleId . ' AND tenant_id = ' . self::$tenantId
        )->fetchColumn());
        self::assertSame(0, (int) self::$db->query(
            'SELECT COUNT(*) FROM cash_transactions WHERE tenant_id = ' . self::$tenantId . ' AND return_id = ' . $saleReturnId
        )->fetchColumn());
        self::assertJournalBalanced((int) $savedSaleReturn['journal_entry_id']);

        $purchaseReturnResponse = $this->requestReturn($this->returnPayload(
            'purchase',
            $purchaseId,
            $cashMethodId,
            'cash'
        ));
        self::assertSame(200, $purchaseReturnResponse->getStatusCode(), (string) $purchaseReturnResponse->getBody());
        $purchaseReturnId = (int) json_decode((string) $purchaseReturnResponse->getBody(), true)['data']['return_id'];

        $purchaseReturn = self::$db->prepare(
            'SELECT paid_amount, is_cash, journal_entry_id FROM returns WHERE id = ? AND tenant_id = ?'
        );
        $purchaseReturn->execute([$purchaseReturnId, self::$tenantId]);
        $savedPurchaseReturn = $purchaseReturn->fetch();
        self::assertEquals(250, (float) $savedPurchaseReturn['paid_amount']);
        self::assertSame(1, (int) $savedPurchaseReturn['is_cash']);
        self::assertGreaterThan(0, (int) $savedPurchaseReturn['journal_entry_id']);
        self::assertSame(1, (int) self::$db->query(
            "SELECT COUNT(*) FROM payments
             WHERE tenant_id = " . self::$tenantId . ' AND return_id = ' . $purchaseReturnId .
            " AND type = 'payment_for_return' AND amount = 250 AND status = 'completed'"
        )->fetchColumn());
        self::assertSame(1, (int) self::$db->query(
            "SELECT COUNT(*) FROM cash_transactions
             WHERE tenant_id = " . self::$tenantId . ' AND return_id = ' . $purchaseReturnId .
            " AND type = 'return_receipt' AND amount = 250 AND status IN ('completed', 'approved')"
        )->fetchColumn());
        $purchaseBalance = self::$db->prepare(
            'SELECT paid_amount, status FROM purchases WHERE id = ? AND tenant_id = ?'
        );
        $purchaseBalance->execute([$purchaseId, self::$tenantId]);
        $savedPurchaseBalance = $purchaseBalance->fetch();
        self::assertEquals(0, (float) $savedPurchaseBalance['paid_amount']);
        self::assertSame('returned', $savedPurchaseBalance['status']);
        self::assertJournalBalanced((int) $savedPurchaseReturn['journal_entry_id']);

        $movement = self::$db->prepare(
            "SELECT movement_type, quantity FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'return' AND reference_id = ?"
        );
        $movement->execute([self::$tenantId, $purchaseReturnId]);
        $savedMovement = $movement->fetch();
        self::assertSame('out', $savedMovement['movement_type']);
        self::assertEquals(1, (float) $savedMovement['quantity']);
        self::assertEquals(20, (float) self::$db->query(
            'SELECT quantity FROM branch_products WHERE tenant_id = ' . self::$tenantId .
            ' AND branch_id = ' . self::$branchId . ' AND product_id = ' . self::$productId
        )->fetchColumn());
    }

    private function requestReturn(array $payload, bool $authenticate = true): \Psr\Http\Message\ResponseInterface
    {
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set('config.security', ['jwt' => ['secret' => self::JWT_SECRET]]);
        $container->set(ReturnsHandler::class, new ReturnsHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/returns.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware(self::$db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(
            new JwtBlacklistService(self::$db, self::JWT_SECRET),
            null,
            $container
        ));

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/returns')
            ->withParsedBody($payload);
        if ($authenticate) {
            $token = JWT::encode([
                'user_id' => self::$userId,
                'tenant_id' => self::$tenantId,
                'role_id' => (int) self::$db->query(
                    'SELECT role_id FROM users WHERE id = ' . self::$userId
                )->fetchColumn(),
                'exp' => time() + 3600,
                'jti' => bin2hex(random_bytes(8)),
            ], self::JWT_SECRET, 'HS256');
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $app->handle($request);
    }

    /**
     * @return array{customer_id:int,first_sale_id:int,second_sale_id:int}
     */
    private function createCustomerDebtFixture(): array
    {
        $account = self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $account->execute([
            self::$tenantId,
            'IT-AR-' . bin2hex(random_bytes(5)),
            'Customer debt integration receivable',
            'asset',
        ]);
        $accountId = (int) self::$db->lastInsertId();

        self::$db->prepare(
            'INSERT INTO customers (tenant_id, branch_id, name, account_id) VALUES (?, ?, ?, ?)'
        )->execute([
            self::$tenantId,
            self::$branchId,
            'Pay debt integration customer ' . bin2hex(random_bytes(4)),
            $accountId,
        ]);
        $customerId = (int) self::$db->lastInsertId();

        $insertSale = self::$db->prepare(
            "INSERT INTO sales
                (tenant_id, customer_id, sale_date, total_amount, net_total_amount, paid_amount, status,
                 user_id, total_items, branch_id, invoice_number, payment_method_id, tax_rate,
                 tax_amount, cost_center_id)
             VALUES (?, ?, ?, ?, ?, 0, 'pending_payment', ?, 1, ?, ?, ?, 0, 0, ?)"
        );
        $insertSale->execute([
            self::$tenantId,
            $customerId,
            '2026-10-01 09:00:00',
            100,
            100,
            self::$userId,
            self::$branchId,
            'IT-PAY-DEBT-1-' . bin2hex(random_bytes(4)),
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        $firstSaleId = (int) self::$db->lastInsertId();

        $insertSale->execute([
            self::$tenantId,
            $customerId,
            '2026-10-02 09:00:00',
            150,
            150,
            self::$userId,
            self::$branchId,
            'IT-PAY-DEBT-2-' . bin2hex(random_bytes(4)),
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        $secondSaleId = (int) self::$db->lastInsertId();

        $this->ensureSalesPaymentPermission();

        return [
            'customer_id' => $customerId,
            'first_sale_id' => $firstSaleId,
            'second_sale_id' => $secondSaleId,
        ];
    }

    private function ensureSalesPaymentPermission(): void
    {
        if (self::$salesPaymentRoleId === null) {
            self::$db->prepare(
                'INSERT INTO roles (name, display_name, tenant_id) VALUES (?, ?, ?)'
            )->execute([
                'sales_payment_it_' . bin2hex(random_bytes(4)),
                'Sales payment integration test',
                self::$tenantId,
            ]);
            self::$salesPaymentRoleId = (int) self::$db->lastInsertId();

            self::$db->prepare(
                'INSERT INTO permissions (name, description, category, tenant_id)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                Permissions::SALE_PAYMENT_CREATE,
                'Create sales payments in integration tests',
                'sales',
                self::$tenantId,
            ]);
            self::$salesPaymentPermissionId = (int) self::$db->lastInsertId();

            self::$db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
            )->execute([
                self::$salesPaymentRoleId,
                self::$salesPaymentPermissionId,
                self::$tenantId,
            ]);
            self::$db->prepare(
                'INSERT INTO users_role (user_id, role_id, tenant_id) VALUES (?, ?, ?)'
            )->execute([self::$userId, self::$salesPaymentRoleId, self::$tenantId]);
        }
    }

    private function requestPayDebt(array $payload, string $idempotencyKey): \Psr\Http\Message\ResponseInterface
    {
        $this->ensureSalesPaymentPermission();
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set(SalesHandler::class, new SalesHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/sales.php')($group, $container);
        });

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales/pay-debt')
            ->withParsedBody($payload)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->withAttribute('tenant_id', self::$tenantId)
            ->withAttribute('user', [
                'id' => self::$userId,
                'tenant_id' => self::$tenantId,
                'role' => 'sales_payment_it',
            ]);

        return $app->handle($request);
    }

    private function requestCustomerPayment(
        int $customerId,
        array $payload,
        string $idempotencyKey
    ): \Psr\Http\Message\ResponseInterface {
        $this->ensureSalesPaymentPermission();
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set(CustomersHandler::class, new CustomersHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/customers.php')($group, $container);
        });
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/customers/' . $customerId . '/payments')
            ->withParsedBody($payload)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->withAttribute('tenant_id', self::$tenantId)
            ->withAttribute('user', [
                'id' => self::$userId,
                'tenant_id' => self::$tenantId,
                'role' => 'sales_payment_it',
            ]);

        return $app->handle($request);
    }

    private function customerPaymentPayload(int $customerId, float $amount): array
    {
        return [
            'amount' => $amount,
            'payment_method_id' => self::$paymentMethodId,
            'payment_date' => '2026-10-16',
            'branch_id' => self::$branchId,
            'notes' => 'Customer payment integration test',
        ];
    }

    private function countCustomerPaymentRecords(int $customerId): int
    {
        $statement = self::$db->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE tenant_id = ? AND customer_id = ? AND type = 'customer'"
        );
        $statement->execute([self::$tenantId, $customerId]);

        return (int) $statement->fetchColumn();
    }

    private function countCustomerPaymentJournals(int $customerId): int
    {
        $statement = self::$db->prepare(
            "SELECT COUNT(*)
             FROM journal_entries je
             JOIN payments p ON p.id = je.reference_id AND p.tenant_id = je.tenant_id
             WHERE je.tenant_id = ? AND je.reference_type = 'customer_payment'
               AND p.customer_id = ? AND p.type = 'customer'"
        );
        $statement->execute([self::$tenantId, $customerId]);

        return (int) $statement->fetchColumn();
    }

    private function countCustomerPaymentsForCustomers(array $customerIds): int
    {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $statement = self::$db->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE tenant_id = ? AND customer_id IN ({$placeholders}) AND type = 'customer'"
        );
        $statement->execute(array_merge([self::$tenantId], $customerIds));

        return (int) $statement->fetchColumn();
    }

    private function countCustomerDebtPayments(int $customerId): int
    {
        $statement = self::$db->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE tenant_id = ? AND customer_id = ? AND type = 'debt_payment'"
        );
        $statement->execute([self::$tenantId, $customerId]);

        return (int) $statement->fetchColumn();
    }

    private function saleStatus(int $saleId): string
    {
        $statement = self::$db->prepare('SELECT status FROM sales WHERE id = ? AND tenant_id = ?');
        $statement->execute([$saleId, self::$tenantId]);

        return (string) $statement->fetchColumn();
    }

    private function returnPayload(
        string $returnType,
        int $invoiceId,
        int $paymentMethodId,
        string $refundMode = 'auto'
    ): array {
        return [
            'return_type' => $returnType,
            'return_date' => date('Y-m-d'),
            'invoice_id' => $invoiceId,
            'paid_amount' => 0,
            'payment_method_id' => $paymentMethodId,
            'cost_center_id' => self::$costCenterId,
            'refund_mode' => $refundMode,
            'items' => [[
                'product_id' => self::$productId,
                'unit_id' => self::$unitId,
                'quantity' => 1,
                'unit_price' => 250,
                'subtotal' => 250,
            ]],
        ];
    }

    private static function assertJournalBalanced(int $journalEntryId): void
    {
        $statement = self::$db->prepare(
            'SELECT COALESCE(SUM(debit_amount), 0) AS debit, COALESCE(SUM(credit_amount), 0) AS credit
             FROM journal_entry_lines WHERE journal_entry_id = ?'
        );
        $statement->execute([$journalEntryId]);
        $totals = $statement->fetch();
        self::assertEquals((float) $totals['debit'], (float) $totals['credit']);
        self::assertGreaterThan(0, (float) $totals['debit']);
    }

    private function saleData(string $invoiceNumber, int $quantity): array
    {
        return [
            'tenant_id' => self::$tenantId,
            'branch_id' => self::$branchId,
            'cost_center_id' => self::$costCenterId,
            'payment_method_id' => self::$paymentMethodId,
            'paid_amount' => $quantity * 250,
            'invoice_number' => $invoiceNumber,
            'items' => [[
                'product_id' => self::$productId,
                'unit_id' => self::$unitId,
                'quantity' => $quantity,
                'sale_price' => 250,
                'conversion_factor' => 0.01,
            ]],
        ];
    }
}
