<?php

declare(strict_types=1);

use App\Handlers\PurchasesHandler;
use App\Handlers\SuppliersHandler;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\TenantMiddleware;
use App\Services\JwtBlacklistService;
use DI\Container;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class SupplierPaymentMySqlIntegrationTest extends TestCase
{
    private const JWT_SECRET = 'supplier-payment-mysql-integration-test-secret';

    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;
    private static int $tenantId;
    private static int $otherTenantId;
    private static int $userId;
    private static int $roleId;
    private static int $branchId;
    private static int $bankMethodId;
    private static int $cashMethodId;
    private static int $supplierId;
    private static int $otherSupplierId;
    private static int $bankPurchaseId;
    private static int $cashPurchaseId;
    private static int $rollbackPurchaseId;
    private static int $overdrawnPurchaseId;
    private static int $concurrentPurchaseId;
    private static int $idempotencyPurchaseId;
    private static int $secondIdempotencyPurchaseId;
    private static int $invoiceRetryPurchaseId;
    private static int $ownerTargetAccountId;
    private static int $ownerRelatedAccountId;
    private static int $ownerCostCenterId;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_SUPPLIER_PAYMENT_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_MYSQL_SUPPLIER_PAYMENT_TESTS=1 to create an isolated temporary MySQL schema.'
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
        self::$schema = 'smartsys_supplier_payment_it_' . bin2hex(random_bytes(8));
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
            require_once dirname(__DIR__, 2) . '/database/migrations/028_add_purchase_invoice_number_uniqueness.php';
            (new AddPurchaseInvoiceNumberUniqueness(self::$db))->up();
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
                throw new RuntimeException('Temporary MySQL integration schema was not removed.');
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
                   VALUES ('supplier-payment-it', 'Supplier payment integration test', 0, 'EGP', 30, '{}', 1)");
        $planId = (int) $db->lastInsertId();

        $tenantStmt = $db->prepare("INSERT INTO tenants (company_name, plan_id, status) VALUES (?, ?, 'active')");
        $tenantStmt->execute(['Supplier payment integration tenant', $planId]);
        self::$tenantId = (int) $db->lastInsertId();
        $tenantStmt->execute(['Other supplier payment integration tenant', $planId]);
        self::$otherTenantId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO cost_centers (tenant_id, name, code) VALUES (?, ?, ?)')->execute([
            self::$tenantId,
            'Integration cost center',
            'IT-SUPPLIER-PAYMENT',
        ]);
        $costCenterId = (int) $db->lastInsertId();
        self::$ownerCostCenterId = $costCenterId;

        $account = $db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance) VALUES (?, ?, ?, ?, ?, 0)'
        );
        $account->execute([self::$tenantId, 'IT-BANK', 'Integration bank', 'asset', 0]);
        $bankAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-CASH', 'Integration cash', 'asset', 0]);
        $cashAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-AP', 'Integration supplier payable', 'liability', 0]);
        $payableAccountId = (int) $db->lastInsertId();
        $account->execute([self::$otherTenantId, 'IT-OTHER-AP', 'Other supplier payable', 'liability', 0]);
        $otherPayableAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, 'IT-EQUITY', 'Integration opening equity', 'equity', 0]);
        $equityAccountId = (int) $db->lastInsertId();
        self::$ownerTargetAccountId = $bankAccountId;
        self::$ownerRelatedAccountId = $equityAccountId;

        $branchStmt = $db->prepare(
            'INSERT INTO branches (tenant_id, name, account_id, cost_center_id, active) VALUES (?, ?, ?, ?, 1)'
        );
        $branchStmt->execute([self::$tenantId, 'Integration branch', $cashAccountId, $costCenterId]);
        self::$branchId = (int) $db->lastInsertId();
        $branchStmt->execute([self::$otherTenantId, 'Other integration branch', null, null]);
        $otherBranchId = (int) $db->lastInsertId();

        $roleStmt = $db->prepare(
            "INSERT INTO roles (name, display_name, is_system_role, tenant_id) VALUES ('payment_operator', 'Payment operator', 0, ?)"
        );
        $roleStmt->execute([self::$tenantId]);
        self::$roleId = (int) $db->lastInsertId();
        $permission = $db->prepare(
            "INSERT INTO permissions (name, description, category, tenant_id) VALUES ('purchase.payment.create', 'Create purchase payments', 'purchase_management', ?)"
        );
        $permission->execute([self::$tenantId]);
        $permissionId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
        )->execute([self::$roleId, $permissionId, self::$tenantId]);
        $db->prepare(
            "INSERT INTO permissions (name, description, category, tenant_id)
             VALUES ('purchase.create', 'Create purchase invoices', 'purchase_management', ?)"
        )->execute([self::$tenantId]);
        $purchaseCreatePermissionId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
        )->execute([self::$roleId, $purchaseCreatePermissionId, self::$tenantId]);
        $db->prepare(
            "INSERT INTO permissions (name, description, category, tenant_id)
             VALUES ('purchase.edit', 'Edit purchase invoices', 'purchase_management', ?)"
        )->execute([self::$tenantId]);
        $purchaseEditPermissionId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
        )->execute([self::$roleId, $purchaseEditPermissionId, self::$tenantId]);

        $userStmt = $db->prepare(
            "INSERT INTO users (username, password, name, role_id, status, tenant_id, branch_id, email)
             VALUES (?, 'test-only', 'Integration operator', ?, 'active', ?, ?, ?)"
        );
        $userStmt->execute([
            'supplier-payment-it-' . bin2hex(random_bytes(4)),
            self::$roleId,
            self::$tenantId,
            self::$branchId,
            'supplier-payment-it-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ]);
        self::$userId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO users_role (user_id, role_id, tenant_id) VALUES (?, ?, ?)')->execute([
            self::$userId,
            self::$roleId,
            self::$tenantId,
        ]);

        $subscription = $db->prepare(
            "INSERT INTO subscriptions (tenant_id, plan_id, start_date, end_date, status, payment_status, auto_renew, user_id)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active', 'paid', 0, ?)"
        );
        $subscription->execute([self::$tenantId, $planId, self::$userId]);

        $settings = $db->prepare('INSERT INTO settings (tenant_id, key_name, value, updated_at) VALUES (?, ?, ?, NOW())');
        $settings->execute([self::$tenantId, 'company.currency', 'EGP']);
        $settings->execute([self::$tenantId, 'pos.sessions.enabled', '1']);

        $method = $db->prepare(
            'INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $method->execute(['Integration bank', 'bank', $bankAccountId, self::$tenantId, 'bank']);
        self::$bankMethodId = (int) $db->lastInsertId();
        $method->execute(['Integration cash', 'cash', $cashAccountId, self::$tenantId, 'cash']);
        self::$cashMethodId = (int) $db->lastInsertId();

        $openingVoucher = $db->prepare(
            "INSERT INTO cash_vouchers
                (tenant_id, branch_id, type, date, amount, currency, account_id, reference, notes,
                 created_by, cost_center_id)
             VALUES (?, ?, 'receipt', ?, 1000, 'EGP', ?, ?, 'Integration opening liquidity', ?, ?)"
        );
        $openingJournal = $db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, voucher_id, description, created_by, status, entry_date, reference_type, cost_center_id)
             VALUES (?, ?, 'Integration opening liquidity', ?, 'posted', ?, 'manual', ?)"
        );
        $openingLine = $db->prepare(
            'INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ([
            ['IT-OPEN-BANK-' . bin2hex(random_bytes(3)), self::$bankMethodId, $bankAccountId],
            ['IT-OPEN-CASH-' . bin2hex(random_bytes(3)), self::$cashMethodId, $cashAccountId],
        ] as [$reference, $methodId, $liquidityAccountId]) {
            $openingVoucher->execute([
                self::$tenantId,
                self::$branchId,
                date('Y-m-d'),
                $liquidityAccountId,
                $reference,
                self::$userId,
                $costCenterId,
            ]);
            $voucherId = (int) $db->lastInsertId();
            $openingJournal->execute([
                self::$tenantId,
                $voucherId,
                self::$userId,
                date('Y-m-d'),
                $costCenterId,
            ]);
            $journalId = (int) $db->lastInsertId();
            $db->prepare('UPDATE cash_vouchers SET journal_entry_id = ? WHERE id = ?')
                ->execute([$journalId, $voucherId]);
            $openingLine->execute([
                $journalId,
                $liquidityAccountId,
                1000,
                0,
                'Integration opening liquidity',
                self::$tenantId,
                $costCenterId,
            ]);
            $openingLine->execute([
                $journalId,
                $equityAccountId,
                0,
                1000,
                'Integration opening balancing entry',
                self::$tenantId,
                $costCenterId,
            ]);
        }

        $supplier = $db->prepare(
            'INSERT INTO suppliers (tenant_id, branch_id, name, email, address, account_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $supplier->execute([
            self::$tenantId,
            self::$branchId,
            'Integration supplier',
            'supplier-it@example.invalid',
            'Integration test',
            $payableAccountId,
        ]);
        self::$supplierId = (int) $db->lastInsertId();
        $supplier->execute([
            self::$otherTenantId,
            $otherBranchId,
            'Other integration supplier',
            'other-supplier-it@example.invalid',
            'Integration test',
            $otherPayableAccountId,
        ]);
        self::$otherSupplierId = (int) $db->lastInsertId();

        $purchase = $db->prepare(
            "INSERT INTO purchases (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, paid_amount, user_id, total_items, status, branch_id, payment_method_id, cost_center_id)
             VALUES (?, ?, ?, NOW(), ?, 0, ?, 1, 'due', ?, ?, ?)"
        );
        $purchase->execute([
            self::$tenantId,
            self::$supplierId,
            'IT-BANK-' . bin2hex(random_bytes(3)),
            100,
            self::$userId,
            self::$branchId,
            self::$bankMethodId,
            $costCenterId,
        ]);
        self::$bankPurchaseId = (int) $db->lastInsertId();
        $purchase->execute([
            self::$tenantId,
            self::$supplierId,
            'IT-CASH-' . bin2hex(random_bytes(3)),
            100,
            self::$userId,
            self::$branchId,
            self::$cashMethodId,
            $costCenterId,
        ]);
        self::$cashPurchaseId = (int) $db->lastInsertId();
        $purchase->execute([
            self::$tenantId,
            self::$supplierId,
            'IT-ROLLBACK-A-' . bin2hex(random_bytes(3)),
            20,
            self::$userId,
            self::$branchId,
            self::$bankMethodId,
            $costCenterId,
        ]);
        self::$rollbackPurchaseId = (int) $db->lastInsertId();
        $purchase->execute([
            self::$tenantId,
            self::$supplierId,
            'IT-ROLLBACK-B-' . bin2hex(random_bytes(3)),
            5,
            self::$userId,
            self::$branchId,
            self::$bankMethodId,
            $costCenterId,
        ]);
        self::$overdrawnPurchaseId = (int) $db->lastInsertId();
        $purchase->execute([
            self::$tenantId,
            self::$supplierId,
            'IT-CONCURRENT-' . bin2hex(random_bytes(3)),
            100,
            self::$userId,
            self::$branchId,
            self::$bankMethodId,
            $costCenterId,
        ]);
        self::$concurrentPurchaseId = (int) $db->lastInsertId();
        $idempotencyPurchaseIds = [];
        foreach (['IT-IDEMPOTENCY-A-', 'IT-IDEMPOTENCY-B-', 'IT-IDEMPOTENCY-RETRY-'] as $invoicePrefix) {
            $purchase->execute([
                self::$tenantId,
                self::$supplierId,
                $invoicePrefix . bin2hex(random_bytes(3)),
                100,
                self::$userId,
                self::$branchId,
                self::$bankMethodId,
                $costCenterId,
            ]);
            $idempotencyPurchaseIds[] = (int) $db->lastInsertId();
        }
        [
            self::$idempotencyPurchaseId,
            self::$secondIdempotencyPurchaseId,
            self::$invoiceRetryPurchaseId,
        ] = $idempotencyPurchaseIds;

        $otherPurchase = $db->prepare(
            "INSERT INTO purchases (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, paid_amount, user_id, total_items, status, branch_id)
             VALUES (?, ?, ?, NOW(), 20, 0, NULL, 1, 'due', ?)"
        );
        $otherPurchase->execute([
            self::$otherTenantId,
            self::$otherSupplierId,
            'IT-OTHER-' . bin2hex(random_bytes(3)),
            $otherBranchId,
        ]);
    }

    private function requestPayment(array $payload, array $headers = [], bool $authenticate = true): \Psr\Http\Message\ResponseInterface
    {
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set('config.security', ['jwt' => ['secret' => self::JWT_SECRET]]);
        $container->set(PurchasesHandler::class, new PurchasesHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/purchases.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware(self::$db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(
            new JwtBlacklistService(self::$db, self::JWT_SECRET),
            null,
            $container
        ));

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/purchases/pay-debt')
            ->withParsedBody($payload);
        if ($authenticate) {
            $token = JWT::encode([
                'user_id' => self::$userId,
                'tenant_id' => self::$tenantId,
                'role_id' => self::$roleId,
                'role' => 'payment_operator',
                'exp' => time() + 3600,
                'jti' => bin2hex(random_bytes(8)),
            ], self::JWT_SECRET, 'HS256');
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    private function requestInvoicePayment(
        int $purchaseId,
        array $payload,
        array $headers = []
    ): \Psr\Http\Message\ResponseInterface {
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set('config.security', ['jwt' => ['secret' => self::JWT_SECRET]]);
        $container->set(PurchasesHandler::class, new PurchasesHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/purchases.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware(self::$db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(
            new JwtBlacklistService(self::$db, self::JWT_SECRET),
            null,
            $container
        ));

        $token = JWT::encode([
            'user_id' => self::$userId,
            'tenant_id' => self::$tenantId,
            'role_id' => self::$roleId,
            'role' => 'payment_operator',
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(8)),
        ], self::JWT_SECRET, 'HS256');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/purchases/' . $purchaseId . '/payments')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withParsedBody($payload);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    private function requestPurchaseCreation(array $payload, string $idempotencyKey): \Psr\Http\Message\ResponseInterface
    {
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set('config.security', ['jwt' => ['secret' => self::JWT_SECRET]]);
        $container->set(PurchasesHandler::class, new PurchasesHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/purchases.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware(self::$db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(
            new JwtBlacklistService(self::$db, self::JWT_SECRET),
            null,
            $container
        ));

        $token = JWT::encode([
            'user_id' => self::$userId,
            'tenant_id' => self::$tenantId,
            'role_id' => self::$roleId,
            'role' => 'payment_operator',
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(8)),
        ], self::JWT_SECRET, 'HS256');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/purchases')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->withParsedBody($payload);

        return $app->handle($request);
    }

    private function requestPurchaseUpdate(int $purchaseId, array $payload): \Psr\Http\Message\ResponseInterface
    {
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set('config.security', ['jwt' => ['secret' => self::JWT_SECRET]]);
        $container->set(PurchasesHandler::class, new PurchasesHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/purchases.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware(self::$db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(
            new JwtBlacklistService(self::$db, self::JWT_SECRET),
            null,
            $container
        ));

        $token = JWT::encode([
            'user_id' => self::$userId,
            'tenant_id' => self::$tenantId,
            'role_id' => self::$roleId,
            'role' => 'payment_operator',
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(8)),
        ], self::JWT_SECRET, 'HS256');
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/v1/purchases/' . $purchaseId)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withParsedBody($payload);

        return $app->handle($request);
    }

    private function payload(int $purchaseId, int $paymentMethodId, float $amount, ?int $branchId = null): array
    {
        return [
            'supplier_id' => self::$supplierId,
            'amount' => $amount,
            'payment_method_id' => $paymentMethodId,
            'payment_date' => date('Y-m-d'),
            'branch_id' => $branchId,
            'cost_center_id' => (int) self::$db->query(
                'SELECT id FROM cost_centers WHERE tenant_id = ' . self::$tenantId . ' LIMIT 1'
            )->fetchColumn(),
            'purchases' => [['purchase_id' => $purchaseId, 'amount' => $amount]],
        ];
    }

    public function testSupplierPaymentLifecycleOnFreshMySqlSchema(): void
    {
        $unauthenticated = $this->requestPayment($this->payload(
            self::$bankPurchaseId,
            self::$bankMethodId,
            10
        ), [], false);
        self::assertSame(401, $unauthenticated->getStatusCode());

        $paymentPermission = self::$db->query(
            "SELECT id FROM permissions WHERE name = 'purchase.payment.create' AND tenant_id = " . self::$tenantId
        )->fetchColumn();
        self::$db->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?')->execute([
            self::$roleId,
            $paymentPermission,
        ]);
        $forbidden = $this->requestPayment($this->payload(
            self::$bankPurchaseId,
            self::$bankMethodId,
            10
        ));
        self::assertSame(403, $forbidden->getStatusCode());
        self::$db->prepare('INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)')->execute([
            self::$roleId,
            $paymentPermission,
            self::$tenantId,
        ]);

        $payload = $this->payload(self::$bankPurchaseId, self::$bankMethodId, 25, self::$branchId);
        $headers = ['Idempotency-Key' => 'mysql-payment-' . bin2hex(random_bytes(8))];
        $created = $this->requestPayment($payload, $headers);
        $createdBody = json_decode((string) $created->getBody(), true);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('success', $createdBody['status']);
        self::assertCount(1, $createdBody['data']['payments']);

        $replayed = $this->requestPayment($payload, $headers);
        self::assertSame(200, $replayed->getStatusCode());
        self::assertSame($createdBody['data'], json_decode((string) $replayed->getBody(), true)['data']);

        $differentPayload = $payload;
        $differentPayload['notes'] = 'changed payload';
        self::assertSame(409, $this->requestPayment($differentPayload, $headers)->getStatusCode());

        $bankRows = self::$db->prepare(
            "SELECT p.amount, p.journal_entry_id, pa.reference_id, pa.amount AS applied_amount
             FROM payments p
             JOIN payment_applications pa ON pa.payment_id = p.id AND pa.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.purchase_id = ?"
        );
        $bankRows->execute([self::$tenantId, self::$bankPurchaseId]);
        $bankPayment = $bankRows->fetch();
        self::assertEquals(25, (float) $bankPayment['amount']);
        self::assertSame(self::$bankPurchaseId, (int) $bankPayment['reference_id']);
        self::assertEquals(25, (float) $bankPayment['applied_amount']);
        self::assertGreaterThan(0, (int) $bankPayment['journal_entry_id']);

        $journalTotals = self::$db->prepare(
            'SELECT SUM(debit_amount) AS debit, SUM(credit_amount) AS credit
             FROM journal_entry_lines WHERE journal_entry_id = ?'
        );
        $journalTotals->execute([(int) $bankPayment['journal_entry_id']]);
        $totals = $journalTotals->fetch();
        self::assertEquals(25, (float) $totals['debit']);
        self::assertEquals(25, (float) $totals['credit']);

        $headerTenantMismatch = $this->payload(self::$bankPurchaseId, self::$bankMethodId, 5, self::$branchId);
        self::assertSame(201, $this->requestPayment($headerTenantMismatch, ['X-Tenant-ID' => (string) self::$otherTenantId])->getStatusCode());
        self::assertEquals(30, (float) self::$db->query(
            'SELECT paid_amount FROM purchases WHERE id = ' . self::$bankPurchaseId
        )->fetchColumn());
        self::assertEquals(0, (float) self::$db->query(
            'SELECT paid_amount FROM purchases WHERE tenant_id = ' . self::$otherTenantId
        )->fetchColumn());
        $paymentJournalCount = self::$db->prepare(
            "SELECT COUNT(DISTINCT je.id)
             FROM payments p
             JOIN journal_entries je
               ON je.tenant_id = p.tenant_id
              AND je.reference_type = 'supplier_payment'
              AND je.reference_id = p.id
             WHERE p.tenant_id = ? AND p.purchase_id = ?"
        );
        $paymentJournalCount->execute([self::$tenantId, self::$bankPurchaseId]);
        self::assertSame(2, (int) $paymentJournalCount->fetchColumn());

        $statementRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/')
            ->withAttribute('tenant_id', self::$tenantId)
            ->withAttribute('user_id', self::$userId)
            ->withAttribute('user', [
                'id' => self::$userId,
                'tenant_id' => self::$tenantId,
                'role' => 'payment_operator',
                'branch_id' => self::$branchId,
            ])
            ->withQueryParams([
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d'),
                'party_type' => 'supplier',
                'party_id' => (string) self::$supplierId,
            ]);
        $supplierHandler = new SuppliersHandler(self::$db);
        $statementResponse = $supplierHandler->getStatement(
            $statementRequest,
            (new ResponseFactory())->createResponse(),
            ['id' => self::$supplierId]
        );
        $supplierStatement = json_decode((string) $statementResponse->getBody(), true);
        self::assertSame(200, $statementResponse->getStatusCode());
        $statementPaymentTransactions = array_values(array_filter(
            $supplierStatement['data']['transactions'],
            static fn (array $transaction): bool => $transaction['reference_type'] === 'supplier_payment'
        ));
        self::assertCount(2, $statementPaymentTransactions);
        self::assertSame(['payment'], array_values(array_unique(array_column(
            $statementPaymentTransactions,
            'transaction_type'
        ))));
        self::assertEquals(30.0, (float) $supplierStatement['data']['closing_balance']);
        self::assertEquals(30.0, (float) $supplierStatement['data']['total_debit']);
        self::assertEquals(0.0, (float) $supplierStatement['data']['total_credit']);

        $missingBranch = $this->requestPayment($this->payload(
            self::$cashPurchaseId,
            self::$cashMethodId,
            10
        ));
        self::assertSame(400, $missingBranch->getStatusCode());
        $payloadWithoutSession = $this->payload(
            self::$cashPurchaseId,
            self::$cashMethodId,
            10,
            self::$branchId
        );
        self::assertSame(400, $this->requestPayment($payloadWithoutSession)->getStatusCode());

        $db = self::$db;
        $db->prepare(
            "INSERT INTO cashier_sessions (tenant_id, cashier_id, session_type, opening_cash_amount, status, branch_id, created_by)
             VALUES (?, ?, 'manual', 100, 'open', ?, ?)"
        )->execute([self::$tenantId, self::$userId, self::$branchId, self::$userId]);
        $sessionId = (int) $db->lastInsertId();
        $cashPayment = $this->requestPayment($payloadWithoutSession);
        $cashBody = json_decode((string) $cashPayment->getBody(), true);
        self::assertSame(201, $cashPayment->getStatusCode(), (string) $cashPayment->getBody());
        self::assertSame($sessionId, (int) $cashBody['data']['session_id']);

        $cashTransaction = $db->prepare(
            "SELECT type, reference_type, reference_id, session_id, branch_id
             FROM cash_transactions WHERE tenant_id = ? AND reference_id = ?"
        );
        $cashTransaction->execute([self::$tenantId, self::$cashPurchaseId]);
        $cashMovement = $cashTransaction->fetch();
        self::assertSame('expense', $cashMovement['type']);
        self::assertSame('purchase', $cashMovement['reference_type']);
        self::assertSame(self::$cashPurchaseId, (int) $cashMovement['reference_id']);
        self::assertSame($sessionId, (int) $cashMovement['session_id']);
        self::assertSame(self::$branchId, (int) $cashMovement['branch_id']);

        $rollbackPayload = [
            'supplier_id' => self::$supplierId,
            'amount' => 15,
            'payment_method_id' => self::$bankMethodId,
            'payment_date' => date('Y-m-d'),
            'branch_id' => self::$branchId,
            'cost_center_id' => $payload['cost_center_id'],
            'purchases' => [
                ['purchase_id' => self::$rollbackPurchaseId, 'amount' => 4],
                ['purchase_id' => self::$overdrawnPurchaseId, 'amount' => 11],
            ],
        ];
        self::assertSame(400, $this->requestPayment($rollbackPayload)->getStatusCode());
        $rollbackRows = $db->prepare(
            'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND purchase_id IN (?, ?)'
        );
        $rollbackRows->execute([self::$tenantId, self::$rollbackPurchaseId, self::$overdrawnPurchaseId]);
        self::assertSame(0, (int) $rollbackRows->fetchColumn());
        self::assertEquals(0, (float) $db->query(
            'SELECT paid_amount FROM purchases WHERE id = ' . self::$rollbackPurchaseId
        )->fetchColumn());
    }

    public function testConcurrentPartialPaymentsCannotOverpaySameInvoice(): void
    {
        $gate = 'supplier_payment_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );

        $payload = [
            'schema' => self::$schema,
            'tenant_id' => self::$tenantId,
            'user_id' => self::$userId,
            'role_id' => self::$roleId,
            'supplier_id' => self::$supplierId,
            'purchase_id' => self::$concurrentPurchaseId,
            'payment_method_id' => self::$bankMethodId,
            'branch_id' => self::$branchId,
            'cost_center_id' => (int) self::$db->query(
                'SELECT id FROM cost_centers WHERE tenant_id = ' . self::$tenantId . ' LIMIT 1'
            )->fetchColumn(),
            'amount' => 60,
            'barrier' => $gate,
        ];
        $workers = [];

        try {
            foreach (['first', 'second'] as $workerName) {
                $workerPayload = json_encode(
                    $payload + ['worker' => $workerName, 'idempotency_key' => $gate . '_' . $workerName],
                    JSON_THROW_ON_ERROR
                );
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SupplierPaymentConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent MySQL payment worker.');
                fwrite($pipes[0], $workerPayload);
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

            self::assertEqualsCanonicalizing([201, 400], array_column($results, 'status'));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 201));

            $purchase = self::$db->prepare(
                'SELECT paid_amount FROM purchases WHERE tenant_id = ? AND id = ?'
            );
            $purchase->execute([self::$tenantId, self::$concurrentPurchaseId]);
            self::assertEquals(60, (float) $purchase->fetchColumn());

            $payments = self::$db->prepare(
                'SELECT COUNT(*), COALESCE(SUM(amount), 0)
                 FROM payments WHERE tenant_id = ? AND purchase_id = ?'
            );
            $payments->execute([self::$tenantId, self::$concurrentPurchaseId]);
            [$paymentCount, $paymentTotal] = $payments->fetch(PDO::FETCH_NUM);
            self::assertSame(1, (int) $paymentCount);
            self::assertEquals(60, (float) $paymentTotal);

            $applications = self::$db->prepare(
                "SELECT COUNT(*), COALESCE(SUM(pa.amount), 0)
                 FROM payment_applications pa
                 JOIN payments p ON p.id = pa.payment_id AND p.tenant_id = pa.tenant_id
                 WHERE pa.tenant_id = ? AND pa.reference_type = 'purchase' AND pa.reference_id = ?"
            );
            $applications->execute([self::$tenantId, self::$concurrentPurchaseId]);
            [$applicationCount, $applicationTotal] = $applications->fetch(PDO::FETCH_NUM);
            self::assertSame(1, (int) $applicationCount);
            self::assertEquals(60, (float) $applicationTotal);
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
        }
    }

    public function testInvoicePaymentIdempotencyReturnsCachedResultAndRejectsDifferentPayload(): void
    {
        $costCenterId = (int) self::$db->query(
            'SELECT id FROM cost_centers WHERE tenant_id = ' . self::$tenantId . ' LIMIT 1'
        )->fetchColumn();
        $key = 'invoice-payment-retry-' . bin2hex(random_bytes(8));
        $payload = [
            'amount' => 20,
            'payment_method_id' => self::$bankMethodId,
            'payment_date' => date('Y-m-d'),
            'branch_id' => self::$branchId,
            'cost_center_id' => $costCenterId,
        ];

        $created = $this->requestInvoicePayment(
            self::$invoiceRetryPurchaseId,
            $payload,
            ['Idempotency-Key' => $key]
        );
        $createdBody = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        $retry = $this->requestInvoicePayment(
            self::$invoiceRetryPurchaseId,
            $payload,
            ['Idempotency-Key' => $key]
        );
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getBody());
        self::assertSame(
            $createdBody,
            json_decode((string) $retry->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );

        $payload['amount'] = 21;
        $conflict = $this->requestInvoicePayment(
            self::$invoiceRetryPurchaseId,
            $payload,
            ['Idempotency-Key' => $key]
        );
        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());

        $paymentCount = self::$db->prepare(
            'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND purchase_id = ?'
        );
        $paymentCount->execute([self::$tenantId, self::$invoiceRetryPurchaseId]);
        self::assertSame(1, (int) $paymentCount->fetchColumn());
        self::assertEquals(20, (float) self::$db->query(
            'SELECT paid_amount FROM purchases WHERE id = ' . self::$invoiceRetryPurchaseId
        )->fetchColumn());
    }

    public function testInvoicePaymentLiquidityShortfallReturnsStructuredErrorAndRollsBack(): void
    {
        $originalMethodAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalMethodAccount->execute([self::$bankMethodId, self::$tenantId]);
        $originalAccountId = (int) $originalMethodAccount->fetchColumn();

        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-INVOICE-SHORT-' . bin2hex(random_bytes(4)),
            'Invoice payment shortfall account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$liquidityAccountId, self::$bankMethodId, self::$tenantId]);

        try {
            $costCenterId = (int) self::$db->query(
                'SELECT id FROM cost_centers WHERE tenant_id = ' . self::$tenantId . ' LIMIT 1'
            )->fetchColumn();
            $response = $this->requestInvoicePayment(self::$bankPurchaseId, [
                'amount' => 10,
                'payment_method_id' => self::$bankMethodId,
                'payment_date' => date('Y-m-d'),
                'branch_id' => self::$branchId,
                'cost_center_id' => $costCenterId,
            ]);
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(402, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('error', $body['status'] ?? null);
            self::assertSame('insufficient_liquidity', $body['error_code'] ?? null);
            self::assertIsArray($body['details'] ?? null);
            self::assertSame($liquidityAccountId, (int) ($body['details']['account_id'] ?? 0));
            self::assertFalse(self::$db->inTransaction(), 'The rejected invoice payment must roll back its transaction.');

            $paymentCount = self::$db->prepare(
                'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND purchase_id = ?'
            );
            $paymentCount->execute([self::$tenantId, self::$bankPurchaseId]);
            self::assertSame(0, (int) $paymentCount->fetchColumn());
            self::assertEquals(0, (float) self::$db->query(
                'SELECT paid_amount FROM purchases WHERE id = ' . self::$bankPurchaseId
            )->fetchColumn());
        } finally {
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalAccountId, self::$bankMethodId, self::$tenantId]);
        }
    }

    public function testConcurrentSupplierPaymentsCannotOverdrawSharedLiquidityAccount(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $liquidityAccountCode = 'IT-RACE-BANK-' . $suffix;
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            $liquidityAccountCode,
            'Concurrent liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES (?, 'other', ?, ?, 1, 'other')"
        )->execute(['Concurrent liquidity method ' . $suffix, $liquidityAccountId, self::$tenantId]);
        $paymentMethodId = (int) self::$db->lastInsertId();

        $openingJournal = self::$db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, description, created_by, status, entry_date, reference_type, cost_center_id)
             VALUES (?, 'Concurrent liquidity opening', ?, 'posted', ?, 'manual', ?)"
        );
        $costCenterId = (int) self::$db->query(
            'SELECT id FROM cost_centers WHERE tenant_id = ' . self::$tenantId . ' LIMIT 1'
        )->fetchColumn();
        $openingJournal->execute([self::$tenantId, self::$userId, date('Y-m-d'), $costCenterId]);
        $openingJournalId = (int) self::$db->lastInsertId();
        $openingLine = self::$db->prepare(
            'INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $openingLine->execute([
            $openingJournalId,
            $liquidityAccountId,
            1000,
            0,
            'Concurrent liquidity opening',
            self::$tenantId,
            $costCenterId,
        ]);
        $openingLine->execute([
            $openingJournalId,
            self::$ownerRelatedAccountId,
            0,
            1000,
            'Concurrent liquidity opening offset',
            self::$tenantId,
            $costCenterId,
        ]);

        $supplierIds = [];
        $supplierAccount = self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $supplierInsert = self::$db->prepare(
            'INSERT INTO suppliers (tenant_id, branch_id, name, email, address, account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach (['first', 'second'] as $name) {
            $supplierAccount->execute([
                self::$tenantId,
                'IT-RACE-AP-' . strtoupper($name) . '-' . $suffix,
                'Concurrent supplier payable ' . $name,
                'liability',
            ]);
            $supplierAccountId = (int) self::$db->lastInsertId();
            $supplierInsert->execute([
                self::$tenantId,
                self::$branchId,
                'Concurrent liquidity supplier ' . $name,
                "liquidity-{$name}-{$suffix}@example.invalid",
                'Integration test',
                $supplierAccountId,
            ]);
            $supplierIds[] = (int) self::$db->lastInsertId();
        }

        $gate = 'supplier_liquidity_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $workers = [];
        try {
            foreach (['first', 'second'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'route' => 'supplier_debt_unallocated',
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => self::$roleId,
                    'supplier_id' => $supplierIds[$index],
                    'purchase_id' => 0,
                    'payment_method_id' => $paymentMethodId,
                    'branch_id' => self::$branchId,
                    'cost_center_id' => $costCenterId,
                    'amount' => 600,
                    'barrier' => $gate,
                    'worker' => $workerName,
                    'idempotency_key' => $gate . '_' . $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SupplierPaymentConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent liquidity worker.');
                fwrite($pipes[0], $workerPayload);
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
                self::assertSame(0, $exitCode, "Liquidity worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Liquidity worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertEqualsCanonicalizing([201, 402], array_column($results, 'status'), json_encode($results));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['status'] === 201
            ));
            $successfulSupplierIds = self::$db->prepare(
                'SELECT supplier_id, amount, journal_entry_id FROM payments
                 WHERE tenant_id = ? AND supplier_id IN (?, ?) AND payment_method_id = ? AND type = ?'
            );
            $successfulSupplierIds->execute([
                self::$tenantId,
                $supplierIds[0],
                $supplierIds[1],
                $paymentMethodId,
                'supplier',
            ]);
            $payments = $successfulSupplierIds->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(1, $payments);
            self::assertEquals(600, (float) $payments[0]['amount']);
            self::assertGreaterThan(0, (int) $payments[0]['journal_entry_id']);
            $journalTotals = self::$db->prepare(
                'SELECT SUM(debit_amount), SUM(credit_amount)
                 FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?'
            );
            $journalTotals->execute([(int) $payments[0]['journal_entry_id'], self::$tenantId]);
            [$debitTotal, $creditTotal] = $journalTotals->fetch(PDO::FETCH_NUM);
            self::assertEquals(600, (float) $debitTotal);
            self::assertEquals(600, (float) $creditTotal);

            $balance = self::$db->prepare(
                'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ? AND je.status = ?'
            );
            $balance->execute([$liquidityAccountId, self::$tenantId, 'posted']);
            self::assertEquals(400, (float) $balance->fetchColumn());
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
        }
    }

    public function testConcurrentInvoicePaymentsCannotOverdrawSharedLiquidityAccount(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $originalPaymentMethodAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalPaymentMethodAccount->execute([self::$bankMethodId, self::$tenantId]);
        $originalBankAccountId = (int) $originalPaymentMethodAccount->fetchColumn();

        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-INVOICE-RACE-CASH-' . $suffix,
            'Concurrent invoice liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$liquidityAccountId, self::$bankMethodId, self::$tenantId]);
        $paymentMethodId = self::$bankMethodId;

        self::$db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, description, created_by, status, entry_date, reference_type, cost_center_id)
             VALUES (?, 'Concurrent invoice liquidity opening', ?, 'posted', ?, 'manual', ?)"
        )->execute([self::$tenantId, self::$userId, date('Y-m-d'), self::$ownerCostCenterId]);
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
            'Concurrent invoice liquidity opening',
            self::$tenantId,
            self::$ownerCostCenterId,
        ]);
        $openingLine->execute([
            $openingJournalId,
            self::$ownerRelatedAccountId,
            0,
            100,
            'Concurrent invoice liquidity opening offset',
            self::$tenantId,
            self::$ownerCostCenterId,
        ]);

        $purchaseIds = [];
        $supplierAccount = self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $supplierInsert = self::$db->prepare(
            'INSERT INTO suppliers (tenant_id, branch_id, name, email, address, account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $purchaseInsert = self::$db->prepare(
            "INSERT INTO purchases
                (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, paid_amount,
                 user_id, total_items, status, branch_id, payment_method_id, cost_center_id)
             VALUES (?, ?, ?, NOW(), 100, 0, ?, 1, 'due', ?, ?, ?)"
        );
        foreach (['first', 'second'] as $index => $name) {
            $supplierAccount->execute([
                self::$tenantId,
                'IT-INVOICE-RACE-AP-' . strtoupper($name) . '-' . $suffix,
                'Concurrent invoice supplier ' . $name,
                'liability',
            ]);
            $supplierAccountId = (int) self::$db->lastInsertId();
            $supplierInsert->execute([
                self::$tenantId,
                self::$branchId,
                'Concurrent invoice supplier ' . $name,
                "invoice-liquidity-{$name}-{$suffix}@example.invalid",
                'Integration test',
                $supplierAccountId,
            ]);
            $supplierId = (int) self::$db->lastInsertId();
            $purchaseInsert->execute([
                self::$tenantId,
                $supplierId,
                'IT-INVOICE-RACE-' . strtoupper($name) . '-' . $suffix,
                self::$userId,
                self::$branchId,
                $paymentMethodId,
                self::$ownerCostCenterId,
            ]);
            $purchaseIds[] = (int) self::$db->lastInsertId();
        }

        $gate = 'purchase_invoice_liquidity_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $workers = [];
        try {
            foreach (['first', 'second'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'route' => 'invoice',
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => self::$roleId,
                    'purchase_id' => $purchaseIds[$index],
                    'payment_method_id' => $paymentMethodId,
                    'branch_id' => self::$branchId,
                    'cost_center_id' => self::$ownerCostCenterId,
                    'amount' => 60,
                    'barrier' => $gate,
                    'worker' => $workerName,
                    'idempotency_key' => $gate . '_' . $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SupplierPaymentConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent invoice liquidity worker.');
                fwrite($pipes[0], $workerPayload);
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
                self::assertSame(
                    0,
                    $exitCode,
                    "Invoice liquidity worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
                );
                self::assertNotNull($resultLine, "Invoice worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertEqualsCanonicalizing([201, 402], array_column($results, 'status'), json_encode($results));
            $payments = self::$db->prepare(
                'SELECT purchase_id, amount, journal_entry_id FROM payments
                 WHERE tenant_id = ? AND purchase_id IN (?, ?)'
            );
            $payments->execute([self::$tenantId, $purchaseIds[0], $purchaseIds[1]]);
            $createdPayments = $payments->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(1, $createdPayments);
            self::assertEquals(60, (float) $createdPayments[0]['amount']);
            self::assertGreaterThan(0, (int) $createdPayments[0]['journal_entry_id']);

            $paymentTotals = self::$db->prepare(
                'SELECT SUM(debit_amount), SUM(credit_amount)
                 FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?'
            );
            $paymentTotals->execute([(int) $createdPayments[0]['journal_entry_id'], self::$tenantId]);
            [$debitTotal, $creditTotal] = $paymentTotals->fetch(PDO::FETCH_NUM);
            self::assertEquals(60, (float) $debitTotal);
            self::assertEquals(60, (float) $creditTotal);

            $paidAmounts = self::$db->prepare(
                'SELECT id, paid_amount FROM purchases WHERE tenant_id = ? AND id IN (?, ?)'
            );
            $paidAmounts->execute([self::$tenantId, $purchaseIds[0], $purchaseIds[1]]);
            $paidRows = $paidAmounts->fetchAll(PDO::FETCH_KEY_PAIR);
            self::assertEquals(60, (float) $paidRows[$createdPayments[0]['purchase_id']]);
            $otherPurchaseId = (int) $createdPayments[0]['purchase_id'] === $purchaseIds[0]
                ? $purchaseIds[1]
                : $purchaseIds[0];
            self::assertEquals(0, (float) $paidRows[$otherPurchaseId]);

            $liquidity = self::$db->prepare(
                "SELECT SUM(jel.debit_amount - jel.credit_amount)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ?
                   AND je.status IN ('posted', 'reversed')"
            );
            $liquidity->execute([$liquidityAccountId, self::$tenantId]);
            self::assertEquals(40, (float) $liquidity->fetchColumn());
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalBankAccountId, self::$bankMethodId, self::$tenantId]);
        }
    }

    public function testInitialPurchasePaymentShortfallRollsBackInvoiceAndStock(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $originalPaymentMethodAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalPaymentMethodAccount->execute([self::$bankMethodId, self::$tenantId]);
        $originalBankAccountId = (int) $originalPaymentMethodAccount->fetchColumn();

        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-INITIAL-PAYMENT-' . $suffix,
            'Initial purchase payment liquidity account',
            'asset',
        ]);
        $emptyLiquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$emptyLiquidityAccountId, self::$bankMethodId, self::$tenantId]);

        try {
            self::$db->prepare(
                'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
                 VALUES (?, ?, ?, ?, 0, 0)'
            )->execute([
                self::$tenantId,
                'IT-INITIAL-INVENTORY-' . $suffix,
                'Initial purchase inventory account',
                'asset',
            ]);
            $inventoryAccountId = (int) self::$db->lastInsertId();
            self::$db->prepare(
                'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
                 VALUES (?, ?, ?, ?, 0, 0)'
            )->execute([
                self::$tenantId,
                'IT-INITIAL-COGS-' . $suffix,
                'Initial purchase cost account',
                'expense',
            ]);
            $cogsAccountId = (int) self::$db->lastInsertId();

            self::$db->prepare('INSERT INTO units (name, code, tenant_id) VALUES (?, ?, ?)')->execute([
                'Initial purchase unit ' . $suffix,
                'IT-INITIAL-UNIT-' . $suffix,
                self::$tenantId,
            ]);
            $unitId = (int) self::$db->lastInsertId();
            self::$db->prepare(
                "INSERT INTO products
                    (tenant_id, name, sale_price, purchase_price, product_code, unit_id, active, product_type, product_status)
                 VALUES (?, ?, 100, 0, ?, ?, 1, 'stock', 'ACTIVE')"
            )->execute([
                self::$tenantId,
                'Initial purchase product ' . $suffix,
                'IT-INITIAL-PRODUCT-' . $suffix,
                $unitId,
            ]);
            $productId = (int) self::$db->lastInsertId();
            self::$db->prepare(
                'INSERT INTO product_units (product_id, unit_id, conversion_factor, tenant_id, is_main_unit)
                 VALUES (?, ?, 1, ?, 1)'
            )->execute([$productId, $unitId, self::$tenantId]);
            self::$db->prepare(
                "INSERT INTO product_branch_gl_mapping
                    (tenant_id, product_id, branch_id, inventory_gl_account_id, purchase_gl_account_id,
                     cogs_gl_account_id, activation_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH')"
            )->execute([
                self::$tenantId,
                $productId,
                self::$branchId,
                $inventoryAccountId,
                $inventoryAccountId,
                $cogsAccountId,
            ]);

            $key = 'mysql-initial-purchase-' . $suffix;
            $baseline = self::$db->query(
                'SELECT
                    (SELECT COUNT(*) FROM purchases WHERE tenant_id = ' . self::$tenantId . ') AS purchases,
                    (SELECT COUNT(*) FROM purchase_items WHERE tenant_id = ' . self::$tenantId . ') AS items,
                    (SELECT COUNT(*) FROM payments WHERE tenant_id = ' . self::$tenantId . ') AS payments,
                    (SELECT COUNT(*) FROM journal_entries WHERE tenant_id = ' . self::$tenantId . ') AS journals,
                    (SELECT COUNT(*) FROM inventory_transactions WHERE tenant_id = ' . self::$tenantId . ') AS inventory'
            )->fetch(PDO::FETCH_ASSOC);

            $response = $this->requestPurchaseCreation([
                'supplier_id' => self::$supplierId,
                'branch_id' => self::$branchId,
                'payment_method_id' => self::$bankMethodId,
                'cost_center_id' => self::$ownerCostCenterId,
                'paid_amount' => 50,
                'items' => [[
                    'product_id' => $productId,
                    'unit_id' => $unitId,
                    'quantity' => 1,
                    'cost' => 100,
                ]],
            ], $key);

            self::assertSame(402, $response->getStatusCode(), (string) $response->getBody());
            $body = json_decode((string) $response->getBody(), true);
            self::assertSame('insufficient_liquidity', $body['error_code'] ?? null);
            self::assertSame(0, (int) self::$db->query(
                'SELECT COUNT(*) FROM purchases WHERE tenant_id = ' . self::$tenantId .
                ' AND idempotency_key = ' . self::$db->quote($key)
            )->fetchColumn());
            self::assertSame(0, (int) self::$db->query(
                'SELECT COUNT(*) FROM branch_products WHERE tenant_id = ' . self::$tenantId .
                ' AND branch_id = ' . self::$branchId . ' AND product_id = ' . $productId
            )->fetchColumn());

            $after = self::$db->query(
                'SELECT
                    (SELECT COUNT(*) FROM purchases WHERE tenant_id = ' . self::$tenantId . ') AS purchases,
                    (SELECT COUNT(*) FROM purchase_items WHERE tenant_id = ' . self::$tenantId . ') AS items,
                    (SELECT COUNT(*) FROM payments WHERE tenant_id = ' . self::$tenantId . ') AS payments,
                    (SELECT COUNT(*) FROM journal_entries WHERE tenant_id = ' . self::$tenantId . ') AS journals,
                    (SELECT COUNT(*) FROM inventory_transactions WHERE tenant_id = ' . self::$tenantId . ') AS inventory'
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame(
                $baseline,
                $after,
                'A rejected initial payment must leave no invoice, stock, payment, journal, or inventory movement.'
            );

            self::$db->prepare(
                "INSERT INTO journal_entries
                    (tenant_id, description, created_by, status, entry_date, reference_type, cost_center_id)
                 VALUES (?, 'Initial purchase payment opening balance', ?, 'posted', ?, 'manual', ?)"
            )->execute([
                self::$tenantId,
                self::$userId,
                date('Y-m-d'),
                self::$ownerCostCenterId,
            ]);
            $openingJournalId = (int) self::$db->lastInsertId();
            $openingLine = self::$db->prepare(
                'INSERT INTO journal_entry_lines
                    (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $openingLine->execute([
                $openingJournalId,
                $emptyLiquidityAccountId,
                100,
                0,
                'Initial purchase payment opening balance',
                self::$tenantId,
                self::$ownerCostCenterId,
            ]);
            $openingLine->execute([
                $openingJournalId,
                self::$ownerRelatedAccountId,
                0,
                100,
                'Initial purchase payment opening balance offset',
                self::$tenantId,
                self::$ownerCostCenterId,
            ]);

            $successfulResponse = $this->requestPurchaseCreation([
                'supplier_id' => self::$supplierId,
                'branch_id' => self::$branchId,
                'payment_method_id' => self::$bankMethodId,
                'cost_center_id' => self::$ownerCostCenterId,
                'paid_amount' => 50,
                'items' => [[
                    'product_id' => $productId,
                    'unit_id' => $unitId,
                    'quantity' => 1,
                    'cost' => 100,
                ]],
            ], $key . '-funded');
            self::assertSame(201, $successfulResponse->getStatusCode(), (string) $successfulResponse->getBody());
            $successfulBody = json_decode((string) $successfulResponse->getBody(), true);
            $createdPurchaseId = (int) ($successfulBody['data']['id'] ?? 0);
            self::assertGreaterThan(0, $createdPurchaseId);
            $createdPurchase = self::$db->prepare(
                'SELECT paid_amount, journal_entry_id FROM purchases WHERE id = ? AND tenant_id = ?'
            );
            $createdPurchase->execute([$createdPurchaseId, self::$tenantId]);
            $createdPurchaseRow = $createdPurchase->fetch(PDO::FETCH_ASSOC);
            self::assertEquals(50, (float) $createdPurchaseRow['paid_amount']);
            $createdPayment = self::$db->prepare(
                'SELECT amount, journal_entry_id FROM payments WHERE purchase_id = ? AND tenant_id = ?'
            );
            $createdPayment->execute([$createdPurchaseId, self::$tenantId]);
            $createdPaymentRow = $createdPayment->fetch(PDO::FETCH_ASSOC);
            self::assertEquals(50, (float) $createdPaymentRow['amount']);
            self::assertSame(
                (int) $createdPurchaseRow['journal_entry_id'],
                (int) $createdPaymentRow['journal_entry_id']
            );
            $createdJournalTotals = self::$db->prepare(
                'SELECT SUM(debit_amount) AS debit, SUM(credit_amount) AS credit
                 FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?'
            );
            $createdJournalTotals->execute([
                (int) $createdPurchaseRow['journal_entry_id'],
                self::$tenantId,
            ]);
            $createdJournal = $createdJournalTotals->fetch(PDO::FETCH_ASSOC);
            self::assertEquals((float) $createdJournal['debit'], (float) $createdJournal['credit']);
            self::assertSame(1, (int) self::$db->query(
                'SELECT COUNT(*) FROM inventory_transactions WHERE tenant_id = ' . self::$tenantId .
                ' AND reference_type = ' . self::$db->quote('purchase') .
                ' AND reference_id = ' . $createdPurchaseId
            )->fetchColumn());
        } finally {
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalBankAccountId, self::$bankMethodId, self::$tenantId]);
        }
    }

    public function testPurchaseUpdateInitialPaymentRollsBackOnShortfallAndRecordsPaymentWhenFunded(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $originalMethodAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalMethodAccount->execute([self::$bankMethodId, self::$tenantId]);
        $originalBankAccountId = (int) $originalMethodAccount->fetchColumn();

        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-PURCHASE-UPDATE-BANK-' . $suffix,
            'Purchase update liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$liquidityAccountId, self::$bankMethodId, self::$tenantId]);

        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-PURCHASE-UPDATE-INV-' . $suffix,
            'Purchase update inventory account',
            'asset',
        ]);
        $inventoryAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-PURCHASE-UPDATE-COGS-' . $suffix,
            'Purchase update COGS account',
            'expense',
        ]);
        $cogsAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO units (name, code, tenant_id) VALUES (?, ?, ?)')->execute([
            'Purchase update unit ' . $suffix,
            'IT-PURCHASE-UPDATE-UNIT-' . $suffix,
            self::$tenantId,
        ]);
        $unitId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            "INSERT INTO products
                (tenant_id, name, sale_price, purchase_price, product_code, unit_id, active, product_type, product_status)
             VALUES (?, ?, 100, 0, ?, ?, 1, 'stock', 'ACTIVE')"
        )->execute([
            self::$tenantId,
            'Purchase update product ' . $suffix,
            'IT-PURCHASE-UPDATE-PRODUCT-' . $suffix,
            $unitId,
        ]);
        $productId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO product_units (product_id, unit_id, conversion_factor, tenant_id, is_main_unit)
             VALUES (?, ?, 1, ?, 1)'
        )->execute([$productId, $unitId, self::$tenantId]);
        self::$db->prepare(
            "INSERT INTO product_branch_gl_mapping
                (tenant_id, product_id, branch_id, inventory_gl_account_id, purchase_gl_account_id,
                 cogs_gl_account_id, activation_status)
             VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH')"
        )->execute([
            self::$tenantId,
            $productId,
            self::$branchId,
            $inventoryAccountId,
            $inventoryAccountId,
            $cogsAccountId,
        ]);

        self::$db->prepare(
            "INSERT INTO purchases
                (tenant_id, supplier_id, invoice_number, invoice_date, total_amount, paid_amount,
                 user_id, total_items, status, branch_id, payment_method_id, cost_center_id)
             VALUES (?, ?, ?, NOW(), 100, 0, ?, 1, 'due', ?, ?, ?)"
        )->execute([
            self::$tenantId,
            self::$supplierId,
            'IT-PURCHASE-UPDATE-' . $suffix,
            self::$userId,
            self::$branchId,
            self::$bankMethodId,
            self::$ownerCostCenterId,
        ]);
        $purchaseId = (int) self::$db->lastInsertId();

        try {
            $payload = [
                'supplier_id' => self::$supplierId,
                'branch_id' => self::$branchId,
                'payment_method_id' => self::$bankMethodId,
                'cost_center_id' => self::$ownerCostCenterId,
                'paid_amount' => 50,
                'items' => [[
                    'product_id' => $productId,
                    'unit_id' => $unitId,
                    'quantity' => 1,
                    'cost' => 100,
                ]],
            ];
            $rejected = $this->requestPurchaseUpdate($purchaseId, $payload);
            self::assertSame(402, $rejected->getStatusCode(), (string) $rejected->getBody());
            $rejectedBody = json_decode((string) $rejected->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('insufficient_liquidity', $rejectedBody['error_code'] ?? null);
            self::assertSame(0, (int) self::$db->query(
                'SELECT COUNT(*) FROM purchase_items WHERE tenant_id = ' . self::$tenantId .
                ' AND purchase_id = ' . $purchaseId
            )->fetchColumn());
            self::assertSame(0, (int) self::$db->query(
                'SELECT COUNT(*) FROM inventory_transactions WHERE tenant_id = ' . self::$tenantId .
                ' AND reference_type = ' . self::$db->quote('purchase') .
                ' AND reference_id = ' . $purchaseId
            )->fetchColumn());
            self::assertSame(0, (int) self::$db->query(
                'SELECT COUNT(*) FROM payments WHERE tenant_id = ' . self::$tenantId .
                ' AND purchase_id = ' . $purchaseId
            )->fetchColumn());
            $purchaseAfterRejection = self::$db->prepare(
                'SELECT total_amount, paid_amount, journal_entry_id FROM purchases WHERE id = ? AND tenant_id = ?'
            );
            $purchaseAfterRejection->execute([$purchaseId, self::$tenantId]);
            $rejectedPurchase = $purchaseAfterRejection->fetch(PDO::FETCH_ASSOC);
            self::assertEquals(100, (float) $rejectedPurchase['total_amount']);
            self::assertEquals(0, (float) $rejectedPurchase['paid_amount']);
            self::assertNull($rejectedPurchase['journal_entry_id']);

            self::$db->prepare(
                "INSERT INTO journal_entries
                    (tenant_id, description, created_by, status, entry_date, reference_type, cost_center_id)
                 VALUES (?, 'Purchase update opening balance', ?, 'posted', ?, 'manual', ?)"
            )->execute([
                self::$tenantId,
                self::$userId,
                date('Y-m-d'),
                self::$ownerCostCenterId,
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
                'Purchase update opening balance',
                self::$tenantId,
                self::$ownerCostCenterId,
            ]);
            $openingLine->execute([
                $openingJournalId,
                self::$ownerRelatedAccountId,
                0,
                100,
                'Purchase update opening balance offset',
                self::$tenantId,
                self::$ownerCostCenterId,
            ]);

            $updated = $this->requestPurchaseUpdate($purchaseId, $payload);
            self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
            $updatedPurchase = self::$db->prepare(
                'SELECT paid_amount, journal_entry_id FROM purchases WHERE id = ? AND tenant_id = ?'
            );
            $updatedPurchase->execute([$purchaseId, self::$tenantId]);
            $purchaseRow = $updatedPurchase->fetch(PDO::FETCH_ASSOC);
            self::assertEquals(50, (float) $purchaseRow['paid_amount']);
            self::assertGreaterThan(0, (int) $purchaseRow['journal_entry_id']);

            $payment = self::$db->prepare(
                "SELECT p.id, p.amount, p.journal_entry_id, pa.amount AS applied_amount,
                        pa.reference_id, pa.reference_type
                 FROM payments p
                 JOIN payment_applications pa ON pa.payment_id = p.id AND pa.tenant_id = p.tenant_id
                 WHERE p.tenant_id = ? AND p.purchase_id = ? AND p.status = 'completed' AND p.is_draft = 0"
            );
            $payment->execute([self::$tenantId, $purchaseId]);
            $paymentRow = $payment->fetch(PDO::FETCH_ASSOC);
            self::assertEquals(50, (float) $paymentRow['amount']);
            self::assertEquals(50, (float) $paymentRow['applied_amount']);
            self::assertSame('purchase', $paymentRow['reference_type']);
            self::assertSame($purchaseId, (int) $paymentRow['reference_id']);
            self::assertSame((int) $purchaseRow['journal_entry_id'], (int) $paymentRow['journal_entry_id']);

            $journalTotals = self::$db->prepare(
                'SELECT SUM(debit_amount) AS debit, SUM(credit_amount) AS credit
                 FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?'
            );
            $journalTotals->execute([(int) $purchaseRow['journal_entry_id'], self::$tenantId]);
            $journal = $journalTotals->fetch(PDO::FETCH_ASSOC);
            self::assertEquals((float) $journal['debit'], (float) $journal['credit']);
            self::assertEquals(1, (float) self::$db->query(
                'SELECT quantity FROM branch_products WHERE tenant_id = ' . self::$tenantId .
                ' AND branch_id = ' . self::$branchId . ' AND product_id = ' . $productId
            )->fetchColumn());
        } finally {
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalBankAccountId, self::$bankMethodId, self::$tenantId]);
        }
    }

    public function testConcurrentInvoicePaymentIdempotencyKeyAcrossDifferentInvoicesCreatesOnlyOnePayment(): void
    {
        $gate = 'supplier_idempotency_gate_' . bin2hex(random_bytes(8));
        $key = 'supplier-idempotency-concurrent-' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $costCenterId = (int) self::$db->query(
            'SELECT id FROM cost_centers WHERE tenant_id = ' . self::$tenantId . ' LIMIT 1'
        )->fetchColumn();
        $workers = [];

        try {
            foreach ([
                'first' => [self::$idempotencyPurchaseId, 10],
                'second' => [self::$secondIdempotencyPurchaseId, 12],
            ] as $workerName => [$purchaseId, $amount]) {
                $workerPayload = json_encode([
                    'route' => 'invoice',
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => self::$roleId,
                    'supplier_id' => self::$supplierId,
                    'purchase_id' => $purchaseId,
                    'payment_method_id' => self::$bankMethodId,
                    'branch_id' => self::$branchId,
                    'cost_center_id' => $costCenterId,
                    'amount' => $amount,
                    'barrier' => $gate,
                    'worker' => $workerName,
                    'idempotency_key' => $key,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/SupplierPaymentConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent MySQL payment worker.');
                fwrite($pipes[0], $workerPayload);
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
                self::assertNotNull($resultLine, "Worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertEqualsCanonicalizing([201, 409], array_column($results, 'status'), json_encode($results));
            $payments = self::$db->prepare(
                'SELECT purchase_id, amount FROM payments
                 WHERE tenant_id = ? AND purchase_id IN (?, ?)'
            );
            $payments->execute([
                self::$tenantId,
                self::$idempotencyPurchaseId,
                self::$secondIdempotencyPurchaseId,
            ]);
            $createdPayments = $payments->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(1, $createdPayments);
            $paidAmounts = self::$db->prepare(
                'SELECT id, paid_amount FROM purchases
                 WHERE tenant_id = ? AND id IN (?, ?)'
            );
            $paidAmounts->execute([
                self::$tenantId,
                self::$idempotencyPurchaseId,
                self::$secondIdempotencyPurchaseId,
            ]);
            $paidRows = $paidAmounts->fetchAll(PDO::FETCH_KEY_PAIR);
            self::assertEquals((float) $createdPayments[0]['amount'], (float) $paidRows[$createdPayments[0]['purchase_id']]);
            $otherPurchaseId = (int) $createdPayments[0]['purchase_id'] === self::$idempotencyPurchaseId
                ? self::$secondIdempotencyPurchaseId
                : self::$idempotencyPurchaseId;
            self::assertEquals(0, (float) $paidRows[$otherPurchaseId]);

            $reservation = self::$db->prepare(
                'SELECT COUNT(*) FROM payment_idempotency_keys
                 WHERE tenant_id = ? AND idempotency_key = ?'
            );
            $reservation->execute([self::$tenantId, $key]);
            self::assertSame(1, (int) $reservation->fetchColumn());
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
        }
    }

    public function testConcurrentPurchaseInvoiceNumberGenerationReturnsUniquePersistedNumbers(): void
    {
        $gate = 'purchase_invoice_number_gate_' . bin2hex(random_bytes(8));
        $invoiceDate = date('Y-m-d') . ' 12:00:00';
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
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
                    'supplier_id' => self::$supplierId,
                    'branch_id' => self::$branchId,
                    'invoice_date' => $invoiceDate,
                    'barrier' => $gate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/PurchaseInvoiceNumberMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent invoice number worker.');
                fwrite($pipes[0], $workerPayload);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $invoiceNumbers = [];
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
                self::assertSame(0, $exitCode, "Invoice number worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Invoice number worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $result = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('error', $result);
                $invoiceNumbers[] = $result['invoice_number'];
            }

            self::assertCount(2, array_unique($invoiceNumbers));
            foreach ($invoiceNumbers as $invoiceNumber) {
                self::assertMatchesRegularExpression('/^PUR-' . date('ymd') . '-\d{3}$/', $invoiceNumber);
            }
            $stored = self::$db->prepare(
                'SELECT invoice_number FROM purchases
                 WHERE tenant_id = ? AND invoice_number IN (?, ?)'
            );
            $stored->execute([self::$tenantId, ...$invoiceNumbers]);
            self::assertEqualsCanonicalizing($invoiceNumbers, $stored->fetchAll(PDO::FETCH_COLUMN));
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
        }
    }

    public function testConcurrentOwnerContributionMatchingRetriesCreateOneContribution(): void
    {
        $key = 'owner-contribution-retry-' . bin2hex(random_bytes(8));
        $results = $this->runConcurrentOwnerContributions($key, [25, 25]);
        self::assertCount(2, array_filter($results, static fn (array $result): bool => isset($result['result'])));
        self::assertCount(0, array_filter($results, static fn (array $result): bool => isset($result['exception'])));
        $duplicates = array_map(
            static fn (array $result): bool => (bool) $result['result']['duplicate'],
            $results
        );
        self::assertEqualsCanonicalizing([false, true], $duplicates);
        $this->assertSingleOwnerContributionAndJournal($key, 25);
    }

    public function testConcurrentOwnerContributionDifferentPayloadIsRejected(): void
    {
        $key = 'owner-contribution-conflict-' . bin2hex(random_bytes(8));
        $results = $this->runConcurrentOwnerContributions($key, [25, 31]);
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['result'])), json_encode($results));
        self::assertCount(1, array_filter(
            $results,
            static fn (array $result): bool => ($result['exception'] ?? null) === DomainException::class
        ), json_encode($results));

        $contribution = self::$db->prepare(
            'SELECT amount FROM owner_contributions WHERE tenant_id = ? AND idempotency_key = ?'
        );
        $contribution->execute([self::$tenantId, $key]);
        $winnerAmount = (float) $contribution->fetchColumn();
        self::assertContains($winnerAmount, [25.0, 31.0]);
        $this->assertSingleOwnerContributionAndJournal($key, $winnerAmount);
    }

    public function testConcurrentOwnerContributionVoidsCannotOverdrawSharedLiquidity(): void
    {
        $service = new \App\Services\OwnerContributionService(
            self::$db,
            self::$tenantId,
            self::$userId
        );
        $contributionIds = [];
        foreach (['first', 'second'] as $workerName) {
            $contribution = $service->recordContribution(
                'capital',
                60,
                self::$ownerTargetAccountId,
                self::$ownerRelatedAccountId,
                costCenterId: self::$ownerCostCenterId,
                idempotencyKey: 'owner-contribution-void-liquidity-' . $workerName . '-' . bin2hex(random_bytes(5))
            );
            $contributionIds[] = (int) $contribution['id'];
        }

        $liquidity = self::$db->prepare(
            'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
             FROM journal_entry_lines jel
             JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
             WHERE jel.account_id = ? AND jel.tenant_id = ?'
        );
        $liquidity->execute([self::$ownerTargetAccountId, self::$tenantId]);
        $balanceBeforeExpense = (float) $liquidity->fetchColumn();
        self::assertGreaterThan(70, $balanceBeforeExpense);

        $expenseCode = 'IT-OWNER-VOID-EXPENSE-' . bin2hex(random_bytes(4));
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            $expenseCode,
            'Owner contribution void liquidity test expense',
            'expense',
        ]);
        $expenseAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, description, created_by, status, entry_date, reference_type, cost_center_id)
             VALUES (?, 'Owner contribution test prior spending', ?, 'posted', ?, 'manual', ?)"
        )->execute([
            self::$tenantId,
            self::$userId,
            date('Y-m-d'),
            self::$ownerCostCenterId,
        ]);
        $expenseJournalId = (int) self::$db->lastInsertId();
        $spendingAmount = round($balanceBeforeExpense - 70, 2);
        $line = self::$db->prepare(
            'INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $line->execute([
            $expenseJournalId,
            $expenseAccountId,
            $spendingAmount,
            0,
            'Prior expense before contribution voids',
            self::$tenantId,
            self::$ownerCostCenterId,
        ]);
        $line->execute([
            $expenseJournalId,
            self::$ownerTargetAccountId,
            0,
            $spendingAmount,
            'Prior liquidity spending before contribution voids',
            self::$tenantId,
            self::$ownerCostCenterId,
        ]);
        $liquidity->execute([self::$ownerTargetAccountId, self::$tenantId]);
        self::assertEquals(70, (float) $liquidity->fetchColumn());

        $gate = 'owner_contribution_void_liquidity_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $workers = [];
        try {
            foreach (['first', 'second'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'action' => 'void',
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'contribution_id' => $contributionIds[$index],
                    'payment_method_id' => self::$bankMethodId,
                    'branch_id' => self::$branchId,
                    'barrier' => $gate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/OwnerContributionMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent contribution void worker.');
                fwrite($pipes[0], $workerPayload);
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
                self::assertSame(0, $exitCode, "Contribution void worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Contribution void worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => isset($result['result'])
            ), json_encode($results));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => ($result['exception'] ?? null)
                    === \App\Exceptions\InsufficientLiquidityException::class
            ), json_encode($results));
            $contributions = self::$db->prepare(
                'SELECT id, status, reversal_je_id FROM owner_contributions
                 WHERE tenant_id = ? AND id IN (?, ?)'
            );
            $contributions->execute([self::$tenantId, $contributionIds[0], $contributionIds[1]]);
            $savedContributions = $contributions->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $savedContributions);
            self::assertSame(1, count(array_filter(
                $savedContributions,
                static fn (array $row): bool => $row['status'] === 'voided'
                    && (int) $row['reversal_je_id'] > 0
            )));
            self::assertSame(1, count(array_filter(
                $savedContributions,
                static fn (array $row): bool => $row['status'] === 'recorded'
                    && $row['reversal_je_id'] === null
            )));
            $liquidity->execute([self::$ownerTargetAccountId, self::$tenantId]);
            self::assertEquals(10, (float) $liquidity->fetchColumn());
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
        }
    }

    /** @param array{0: float, 1: float} $amounts
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentOwnerContributions(string $key, array $amounts): array
    {
        $gate = 'owner_contribution_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE supplier_payment_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $workers = [];

        try {
            foreach (['first', 'second'] as $index => $workerName) {
                $workerPayload = json_encode([
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'target_account_id' => self::$ownerTargetAccountId,
                    'related_account_id' => self::$ownerRelatedAccountId,
                    'cost_center_id' => self::$ownerCostCenterId,
                    'amount' => $amounts[$index],
                    'barrier' => $gate,
                    'worker' => $workerName,
                    'idempotency_key' => $key,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/OwnerContributionMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent owner contribution worker.');
                fwrite($pipes[0], $workerPayload);
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
                self::assertSame(0, $exitCode, "Owner contribution worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Owner contribution worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $result = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('fatal', $result, json_encode($result));
                $results[] = $result;
            }

            return $results;
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
            self::$db->exec('DROP TABLE IF EXISTS supplier_payment_test_barrier');
        }
    }

    private function assertSingleOwnerContributionAndJournal(string $key, float $amount): void
    {
        $contribution = self::$db->prepare(
            'SELECT id, journal_entry_id, amount FROM owner_contributions
             WHERE tenant_id = ? AND idempotency_key = ?'
        );
        $contribution->execute([self::$tenantId, $key]);
        $row = $contribution->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertEquals($amount, (float) $row['amount']);

        $journal = self::$db->prepare(
            'SELECT COUNT(*) FROM journal_entries
             WHERE tenant_id = ? AND idempotency_key = ?'
        );
        $journal->execute([self::$tenantId, $key]);
        self::assertSame(1, (int) $journal->fetchColumn());

        $lines = self::$db->prepare(
            'SELECT COUNT(*), COALESCE(SUM(debit_amount), 0), COALESCE(SUM(credit_amount), 0)
             FROM journal_entry_lines WHERE tenant_id = ? AND journal_entry_id = ?'
        );
        $lines->execute([self::$tenantId, (int) $row['journal_entry_id']]);
        [$lineCount, $debit, $credit] = $lines->fetch(PDO::FETCH_NUM);
        self::assertSame(2, (int) $lineCount);
        self::assertEquals($amount, (float) $debit);
        self::assertEquals($amount, (float) $credit);
    }
}
