<?php

declare(strict_types=1);

use App\Services\SupplierPaymentAllocationPolicy;
use App\Services\PurchaseService;
use App\Services\SupplierInvoicePaymentAllocator;
use App\Services\JwtBlacklistService;
use App\Handlers\PurchasesHandler;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\TenantMiddleware;
use DI\Container;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierPaymentAllocationPolicyTest extends TestCase
{
    private function createPaymentDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $db->exec("
            CREATE TABLE suppliers (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL
            );
            CREATE TABLE purchases (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                supplier_id INTEGER NOT NULL,
                total_amount NUMERIC NOT NULL,
                paid_amount NUMERIC NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                updated_at TEXT
            );
            CREATE TABLE payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                purchase_id INTEGER NOT NULL,
                supplier_id INTEGER NOT NULL,
                amount NUMERIC NOT NULL,
                payment_date TEXT NOT NULL,
                payment_method_id INTEGER NOT NULL,
                reference_number TEXT,
                created_by INTEGER,
                created_at TEXT,
                journal_entry_id INTEGER,
                cost_center_id INTEGER,
                currency TEXT,
                type TEXT NOT NULL,
                status TEXT NOT NULL,
                is_draft INTEGER NOT NULL,
                session_id INTEGER
            );
            CREATE TABLE payment_applications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                payment_id INTEGER NOT NULL,
                reference_type TEXT NOT NULL,
                reference_id INTEGER NOT NULL,
                amount NUMERIC NOT NULL,
                created_by INTEGER,
                created_at TEXT
            );
            CREATE TABLE returns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                purchase_id INTEGER NOT NULL,
                return_type TEXT NOT NULL,
                grand_total NUMERIC NOT NULL
            );
            CREATE TABLE accounts (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                debit_balance NUMERIC NOT NULL DEFAULT 0,
                credit_balance NUMERIC NOT NULL DEFAULT 0
            );
            CREATE TABLE payment_methods (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                kind TEXT NOT NULL,
                account_id INTEGER NOT NULL,
                account_type TEXT NOT NULL,
                is_active INTEGER NOT NULL,
                allow_negative INTEGER NOT NULL DEFAULT 0,
                overdraft_limit NUMERIC,
                credit_limit NUMERIC,
                wallet_type TEXT
            );
            CREATE TABLE settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                key_name TEXT NOT NULL,
                value TEXT,
                updated_at TEXT
            );
            CREATE TABLE cashier_sessions (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                branch_id INTEGER NOT NULL,
                cashier_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                opening_cash_amount NUMERIC NOT NULL DEFAULT 0,
                expected_cash NUMERIC
            );
            CREATE TABLE cash_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                supplier_id INTEGER,
                amount NUMERIC NOT NULL,
                type TEXT NOT NULL,
                reference_type TEXT NOT NULL,
                reference_id INTEGER NOT NULL,
                payment_method_id INTEGER NOT NULL,
                description TEXT,
                created_by INTEGER,
                created_at TEXT,
                status TEXT NOT NULL,
                notes TEXT,
                journal_entry_id INTEGER,
                session_id INTEGER,
                branch_id INTEGER,
                cost_center_id INTEGER
            );
            CREATE TABLE payment_idempotency_keys (
                tenant_id INTEGER NOT NULL,
                idempotency_key TEXT NOT NULL,
                payment_id INTEGER NOT NULL,
                response_json TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                UNIQUE (tenant_id, idempotency_key)
            );
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                is_owner INTEGER NOT NULL DEFAULT 0,
                token_version INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER,
                name TEXT NOT NULL,
                is_system_role INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER,
                name TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                tenant_id INTEGER
            );
            CREATE TABLE permission_overrides (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                subject_type TEXT NOT NULL,
                subject_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                effect TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_by INTEGER NOT NULL,
                created_at TEXT,
                updated_at TEXT
            );
            CREATE TABLE users_role (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                tenant_id INTEGER
            );
            CREATE TABLE accounting_periods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                period_name TEXT NOT NULL,
                status TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL
            );
            CREATE TABLE cost_centers (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT NOT NULL,
                entity TEXT NOT NULL,
                entity_id INTEGER,
                details TEXT,
                tenant_id INTEGER NOT NULL,
                ip_address TEXT,
                created_at TEXT
            );
            CREATE TABLE jwt_blacklist (
                token_id TEXT PRIMARY KEY,
                expires_at TEXT NOT NULL
            );
            CREATE TABLE plans (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                billing_cycle_days INTEGER NOT NULL,
                code TEXT NOT NULL
            );
            CREATE TABLE subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                plan_id INTEGER NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                status TEXT NOT NULL,
                payment_status TEXT NOT NULL,
                auto_renew INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT
            );
            CREATE TABLE subscription_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subscription_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                event_date TEXT NOT NULL,
                meta_json TEXT
            );
            CREATE TABLE journal_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entry_date TEXT NOT NULL,
                description TEXT NOT NULL,
                reference_type TEXT NOT NULL,
                reference_id INTEGER,
                created_by INTEGER,
                created_at TEXT,
                status TEXT NOT NULL,
                cost_center_id INTEGER,
                idempotency_key TEXT
            );
            CREATE TABLE journal_entry_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                journal_entry_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                debit_amount NUMERIC NOT NULL,
                credit_amount NUMERIC NOT NULL,
                description TEXT,
                tenant_id INTEGER NOT NULL,
                cost_center_id INTEGER
            );
        ");
        $db->exec("
            INSERT INTO accounts (id, tenant_id, code, name, type, debit_balance, credit_balance)
                VALUES (30, 2, '1010', 'Bank', 'asset', 1000, 0),
                       (31, 2, '1000', 'Cash', 'asset', 1000, 0),
                       (40, 2, '2100', 'Supplier Payable', 'liability', 0, 0),
                       (50, 3, '2100', 'Other Supplier Payable', 'liability', 0, 0);
            INSERT INTO cost_centers (id, tenant_id, name) VALUES (8, 2, 'Test Cost Center');
            INSERT INTO payment_methods (id, tenant_id, kind, account_id, account_type, is_active)
                VALUES (3, 2, 'bank', 30, 'bank', 1),
                       (5, 2, 'cash', 31, 'cash', 1);
            INSERT INTO settings (tenant_id, key_name, value, updated_at)
                VALUES (2, 'company.currency', 'EGP', '2026-10-05 00:00:00'),
                       (2, 'pos.sessions.enabled', '1', '2026-10-05 00:00:00');
        ");
        $db->exec("
            INSERT INTO suppliers (id, tenant_id, account_id) VALUES (4, 2, 40);
            INSERT INTO purchases (id, tenant_id, supplier_id, total_amount, paid_amount, status)
                VALUES (11, 2, 4, 100, 0, 'due'), (12, 2, 4, 50, 0, 'due');
            INSERT INTO suppliers (id, tenant_id, account_id) VALUES (5, 3, 50);
            INSERT INTO purchases (id, tenant_id, supplier_id, total_amount, paid_amount, status)
                VALUES (13, 3, 5, 20, 0, 'due');
            INSERT INTO users (id, tenant_id, role_id) VALUES (7, 2, 70);
            INSERT INTO roles (id, tenant_id, name) VALUES (70, 2, 'cashier');
            INSERT INTO permissions (id, tenant_id, name) VALUES (90, 2, 'purchase.payment.create');
            INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (70, 90, 2);
            INSERT INTO users_role (user_id, role_id, tenant_id) VALUES (7, 70, 2);
            INSERT INTO plans (id, name, billing_cycle_days, code) VALUES (1, 'Test plan', 30, 'test');
        ");
        $subscription = $db->prepare("
            INSERT INTO subscriptions (tenant_id, plan_id, start_date, end_date, status, payment_status, auto_renew)
            VALUES (2, 1, ?, ?, 'active', 'paid', 0)
        ");
        $subscription->execute([
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 30 * 86400),
        ]);
        return $db;
    }

    private function createPurchaseService(PDO $db): PurchaseService
    {
        return new PurchaseService($db, 2, 7);
    }

    private function requestSupplierPayment(
        PDO $db,
        array $payload,
        array $headers = [],
        ?array $user = null
    ): \Psr\Http\Message\ResponseInterface {
        $jwtSecret = 'supplier-payment-isolated-test-secret-256-bits';
        $container = new Container();
        $container->set(PDO::class, $db);
        $container->set('db', $db);
        $container->set('config.security', ['jwt' => ['secret' => $jwtSecret]]);
        $container->set(PurchasesHandler::class, new PurchasesHandler($db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require __DIR__ . '/../../app/routes/purchases.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware($db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(new JwtBlacklistService($db, $jwtSecret), null, $container));

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/purchases/pay-debt')
            ->withParsedBody($payload);
        if ($user !== null) {
            $token = JWT::encode([
                'user_id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'role_id' => $user['role_id'],
                'role' => $user['role'],
                'exp' => time() + 3600,
            ], $jwtSecret, 'HS256');
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    private function paymentRequestBody(): array
    {
        return [
            'supplier_id' => 4,
            'amount' => 25,
            'payment_method_id' => 3,
            'payment_date' => '2026-10-05',
            'cost_center_id' => 8,
            'purchases' => [['purchase_id' => 11, 'amount' => 25]],
        ];
    }

    private function cashierUser(): array
    {
        return [
            'id' => 7,
            'tenant_id' => 2,
            'role_id' => 70,
            'role' => 'cashier',
        ];
    }

    public function testNormalizesExplicitInvoiceAllocations(): void
    {
        self::assertSame(
            [
                ['purchase_id' => 7, 'amount' => 40.25],
                ['purchase_id' => 9, 'amount' => 59.75],
            ],
            SupplierPaymentAllocationPolicy::normalize([
                ['purchase_id' => '7', 'amount' => '40.25'],
                ['purchase_id' => 9, 'amount' => 59.75],
            ], 100.00)
        );
    }

    public function testRejectsAllocationTotalThatDoesNotMatchPayment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupplierPaymentAllocationPolicy::normalize([
            ['purchase_id' => 7, 'amount' => 40],
        ], 50);
    }

    public function testRejectsDuplicatePurchaseIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupplierPaymentAllocationPolicy::normalize([
            ['purchase_id' => 7, 'amount' => 20],
            ['purchase_id' => 7, 'amount' => 30],
        ], 50);
    }

    public function testRejectsNonPositiveAllocationAmounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupplierPaymentAllocationPolicy::normalize([
            ['purchase_id' => 7, 'amount' => 0],
        ], 0);
    }

    public function testRejectsEmptyOrOversizedAllocationLists(): void
    {
        try {
            SupplierPaymentAllocationPolicy::normalize([], 10);
            self::fail('An empty allocation list must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        $tooMany = [];
        for ($purchaseId = 1; $purchaseId <= 101; $purchaseId++) {
            $tooMany[] = ['purchase_id' => $purchaseId, 'amount' => 1];
        }

        $this->expectException(InvalidArgumentException::class);
        SupplierPaymentAllocationPolicy::normalize($tooMany, 101);
    }

    public function testAllocatesPaymentAcrossInvoicesAndRecalculatesTheirBalances(): void
    {
        $db = $this->createPaymentDatabase();
        $this->seedOpeningBankBalance($db, 30, 1000);
        $allocations = SupplierPaymentAllocationPolicy::normalize([
            ['purchase_id' => 11, 'amount' => 60],
            ['purchase_id' => 12, 'amount' => 40],
        ], 100);

        $db->beginTransaction();
        $payments = SupplierInvoicePaymentAllocator::allocate(
            $db,
            $this->createPurchaseService($db),
            2,
            4,
            $allocations,
            '2026-10-05',
            3,
            'SUP-001',
            5,
            8,
            null
        );
        $db->commit();

        self::assertCount(2, $payments);
        self::assertSame(60.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 11')->fetchColumn());
        self::assertSame(40.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 12')->fetchColumn());
        self::assertSame('partial', $db->query('SELECT status FROM purchases WHERE id = 11')->fetchColumn());
        self::assertSame('partial', $db->query('SELECT status FROM purchases WHERE id = 12')->fetchColumn());
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM payment_applications')->fetchColumn());
        self::assertSame(2, (int) $db->query(
            "SELECT COUNT(*) FROM journal_entries WHERE status = 'posted' AND reference_type = 'supplier_payment'"
        )->fetchColumn());
        self::assertSame(4, (int) $db->query(
            "SELECT COUNT(*)
             FROM journal_entry_lines jel
             JOIN journal_entries je ON je.id = jel.journal_entry_id
             WHERE je.reference_type = 'supplier_payment'"
        )->fetchColumn());
        self::assertSame(100.0, (float) $db->query(
            "SELECT SUM(jel.debit_amount)
             FROM journal_entry_lines jel
             JOIN journal_entries je ON je.id = jel.journal_entry_id
             WHERE je.reference_type = 'supplier_payment'"
        )->fetchColumn());
        self::assertSame(100.0, (float) $db->query(
            "SELECT SUM(jel.credit_amount)
             FROM journal_entry_lines jel
             JOIN journal_entries je ON je.id = jel.journal_entry_id
             WHERE je.reference_type = 'supplier_payment'"
        )->fetchColumn());
        self::assertSame(100.0, (float) $db->query('SELECT SUM(debit_amount) FROM journal_entry_lines WHERE account_id = 40')->fetchColumn());
        self::assertSame(100.0, (float) $db->query('SELECT SUM(credit_amount) FROM journal_entry_lines WHERE account_id = 30')->fetchColumn());
        self::assertSame(2, (int) $db->query(
            "SELECT COUNT(*)
             FROM journal_entries je
             JOIN payments p
               ON p.journal_entry_id = je.id
              AND p.id = je.reference_id
             WHERE je.reference_type = 'supplier_payment'"
        )->fetchColumn());
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM payments WHERE journal_entry_id IS NOT NULL')->fetchColumn());
        self::assertSame(100.0, (float) $db->query('SELECT SUM(amount) FROM payments')->fetchColumn());
        self::assertSame(100.0, (float) $db->query('SELECT SUM(amount) FROM payment_applications')->fetchColumn());
    }

    private function seedOpeningBankBalance(PDO $db, int $accountId, float $amount): void
    {
        $db->exec("
            INSERT INTO accounts (id, tenant_id, code, name, type, debit_balance, credit_balance)
            VALUES (60, 2, '3000', 'Opening Balance Equity', 'equity', 0, 0)
        ");
        $stmt = $db->prepare("
            INSERT INTO journal_entries (
                tenant_id, entry_date, description, reference_type, status
            ) VALUES (2, '2026-10-05', 'Opening bank balance', 'opening_balance', 'posted')
        ");
        $stmt->execute();
        $journalEntryId = (int) $db->lastInsertId();
        $line = $db->prepare("
            INSERT INTO journal_entry_lines (
                journal_entry_id, account_id, debit_amount, credit_amount,
                description, tenant_id
            ) VALUES (?, ?, ?, ?, 'Opening bank balance', 2)
        ");
        $line->execute([$journalEntryId, $accountId, $amount, 0]);
        $line->execute([$journalEntryId, 60, 0, $amount]);
    }

    public function testRejectsInvoiceOverpaymentAndCallerCanRollbackEarlierAllocations(): void
    {
        $db = $this->createPaymentDatabase();
        $allocations = SupplierPaymentAllocationPolicy::normalize([
            ['purchase_id' => 11, 'amount' => 40],
            ['purchase_id' => 12, 'amount' => 60],
        ], 100);
        $db->beginTransaction();

        try {
            SupplierInvoicePaymentAllocator::allocate(
                $db,
                $this->createPurchaseService($db),
                2,
                4,
                $allocations,
                '2026-10-05',
                3,
                null,
                5,
                8,
                null
            );
            self::fail('Allocation exceeding the invoice balance must fail.');
        } catch (Exception $e) {
            $db->rollBack();
            self::assertStringContainsString('تتجاوز المبلغ المتبقي', $e->getMessage());
        }

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame(0.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 11')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payment_applications')->fetchColumn());
        self::assertSame('due', $db->query('SELECT status FROM purchases WHERE id = 11')->fetchColumn());
    }

    public function testRejectsInvoiceFromAnotherSupplierAndRollsBackTheAllocationBatch(): void
    {
        $db = $this->createPaymentDatabase();
        $db->exec("INSERT INTO suppliers (id, tenant_id, account_id) VALUES (6, 2, 40)");
        $db->exec("INSERT INTO purchases (id, tenant_id, supplier_id, total_amount, paid_amount, status)
                   VALUES (14, 2, 6, 20, 0, 'due')");
        $allocations = SupplierPaymentAllocationPolicy::normalize([
            ['purchase_id' => 11, 'amount' => 20],
            ['purchase_id' => 14, 'amount' => 20],
        ], 40);
        $db->beginTransaction();

        try {
            SupplierInvoicePaymentAllocator::allocate(
                $db,
                $this->createPurchaseService($db),
                2,
                4,
                $allocations,
                '2026-10-05',
                3,
                null,
                5,
                8,
                null
            );
            self::fail('An invoice belonging to a different supplier must fail.');
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            self::assertStringContainsString('لا تخص هذا المورد', $e->getMessage());
        }

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame(0.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 11')->fetchColumn());
    }

    public function testPayDebtRouteRequiresAuthenticationAndPaymentPermission(): void
    {
        $db = $this->createPaymentDatabase();

        $unauthenticated = $this->requestSupplierPayment($db, $this->paymentRequestBody());
        self::assertSame(401, $unauthenticated->getStatusCode());

        $db->exec('DELETE FROM role_permissions');
        $forbidden = $this->requestSupplierPayment($db, $this->paymentRequestBody(), [], $this->cashierUser());
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testPayDebtRouteUsesAuthenticatedTenantInsteadOfTenantHeader(): void
    {
        $db = $this->createPaymentDatabase();
        $response = $this->requestSupplierPayment(
            $db,
            $this->paymentRequestBody(),
            ['X-Tenant-ID' => '3'],
            $this->cashierUser()
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(25.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 11')->fetchColumn());
        self::assertSame(0.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 13')->fetchColumn());
    }

    public function testPayDebtRouteBlocksExpiredSubscriptionBeforePayment(): void
    {
        $db = $this->createPaymentDatabase();
        $db->exec("UPDATE subscriptions SET status = 'expired' WHERE tenant_id = 2");

        $response = $this->requestSupplierPayment($db, $this->paymentRequestBody(), [], $this->cashierUser());

        self::assertSame(402, $response->getStatusCode());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
    }

    public function testPayDebtRoutePostsAllocationAndReplaysIdempotentResponse(): void
    {
        $db = $this->createPaymentDatabase();
        $payload = $this->paymentRequestBody();
        $headers = ['Idempotency-Key' => 'supplier-payment-http-001'];

        $created = $this->requestSupplierPayment($db, $payload, $headers, $this->cashierUser());
        $createdBody = json_decode((string) $created->getBody(), true);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('success', $createdBody['status']);
        self::assertEquals(25.0, $createdBody['data']['total_paid']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());

        $replayed = $this->requestSupplierPayment($db, $payload, $headers, $this->cashierUser());
        self::assertSame(200, $replayed->getStatusCode());
        self::assertSame($createdBody['data'], json_decode((string) $replayed->getBody(), true)['data']);

        $payload['notes'] = 'different payload';
        $conflict = $this->requestSupplierPayment($db, $payload, $headers, $this->cashierUser());
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame(25.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 11')->fetchColumn());
    }

    public function testPayDebtRouteRejectsSupplierOutsideTenant(): void
    {
        $db = $this->createPaymentDatabase();
        $payload = $this->paymentRequestBody();
        $payload['supplier_id'] = 5;
        $payload['purchases'] = [['purchase_id' => 13, 'amount' => 25]];

        $response = $this->requestSupplierPayment($db, $payload, [], $this->cashierUser());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame(0.0, (float) $db->query('SELECT paid_amount FROM purchases WHERE id = 13')->fetchColumn());
    }

    public function testPayDebtRouteRejectsAllocationTotalMismatch(): void
    {
        $db = $this->createPaymentDatabase();
        $payload = $this->paymentRequestBody();
        $payload['amount'] = 30;

        $response = $this->requestSupplierPayment($db, $payload, [], $this->cashierUser());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('error', $body['status']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testCashSupplierPaymentRequiresBranchAndOpenCashierSession(): void
    {
        $db = $this->createPaymentDatabase();
        $payload = $this->paymentRequestBody();
        $payload['payment_method_id'] = 5;

        $missingBranch = $this->requestSupplierPayment($db, $payload, [], $this->cashierUser());
        self::assertSame(400, $missingBranch->getStatusCode());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());

        $payload['branch_id'] = 5;
        $noSession = $this->requestSupplierPayment($db, $payload, [], $this->cashierUser());
        self::assertSame(400, $noSession->getStatusCode());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testCashSupplierPaymentUsesOpenSession(): void
    {
        $db = $this->createPaymentDatabase();
        $db->exec("
            INSERT INTO cashier_sessions (id, tenant_id, branch_id, cashier_id, status, opening_cash_amount, expected_cash)
            VALUES (501, 2, 5, 7, 'open', 100, 100)
        ");
        $payload = $this->paymentRequestBody();
        $payload['payment_method_id'] = 5;
        $payload['branch_id'] = 5;

        $response = $this->requestSupplierPayment($db, $payload, [], $this->cashierUser());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(501, $body['data']['session_id']);
        self::assertSame(501, (int) $db->query('SELECT session_id FROM payments')->fetchColumn());
        self::assertSame(501, (int) $db->query('SELECT session_id FROM cash_transactions')->fetchColumn());
        self::assertSame('expense', $db->query('SELECT type FROM cash_transactions')->fetchColumn());
        self::assertSame('purchase', $db->query('SELECT reference_type FROM cash_transactions')->fetchColumn());
        self::assertSame(11, (int) $db->query('SELECT reference_id FROM cash_transactions')->fetchColumn());
    }
}
