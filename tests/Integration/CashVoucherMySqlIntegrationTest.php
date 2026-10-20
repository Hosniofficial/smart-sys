<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/021_add_cash_voucher_idempotency.php';

use App\Handlers\CashVouchersHandler;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\TenantMiddleware;
use App\Services\JwtBlacklistService;
use DI\Container;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class CashVoucherMySqlIntegrationTest extends TestCase
{
    private const JWT_SECRET = 'cash-voucher-mysql-integration-test-secret';

    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;
    private static int $tenantId;
    private static int $userId;
    private static int $branchId;
    private static int $otherTenantBranchId;
    private static int $costCenterId;
    private static int $cashAccountId;
    private static int $bankAccountId;
    private static int $customerAccountId;
    private static int $customerId;
    private static int $supplierAccountId;
    private static int $supplierId;
    private static int $paymentMethodId;
    private static int $bankPaymentMethodId;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_CASH_VOUCHER_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_MYSQL_CASH_VOUCHER_TESTS=1 to create an isolated temporary MySQL schema.'
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
        self::$schema = 'smartsys_cash_voucher_it_' . bin2hex(random_bytes(8));
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
            (new AddCashVoucherIdempotency(self::$db))->up();
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
                throw new RuntimeException('Temporary MySQL cash voucher schema was not removed.');
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
        $db->exec(
            "INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
             VALUES ('cash-voucher-mysql-it', 'Cash voucher MySQL integration test', 0, 'EGP', 30, '{}', 1)"
        );
        $planId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO tenants (company_name, plan_id, status) VALUES (?, ?, 'active')")
            ->execute(['Cash voucher integration tenant', $planId]);
        self::$tenantId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO cost_centers (tenant_id, name, code) VALUES (?, ?, ?)')
            ->execute([
                self::$tenantId,
                'Cash voucher integration center',
                'IT-VOUCHER-' . bin2hex(random_bytes(4)),
            ]);
        self::$costCenterId = (int) $db->lastInsertId();

        $account = $db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $account->execute([self::$tenantId, '1001', 'Voucher integration cash', 'asset']);
        self::$cashAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '1002', 'Voucher integration bank', 'asset']);
        self::$bankAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '1101', 'Voucher integration customer', 'asset']);
        self::$customerAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '2101', 'Voucher integration supplier', 'liability']);
        self::$supplierAccountId = (int) $db->lastInsertId();
        $account->execute([self::$tenantId, '3001', 'Voucher integration equity', 'equity']);
        $equityAccountId = (int) $db->lastInsertId();

        $db->prepare(
            'INSERT INTO branches (tenant_id, name, account_id, cost_center_id, active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([self::$tenantId, 'Voucher integration branch', self::$cashAccountId, self::$costCenterId]);
        self::$branchId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO tenants (company_name, plan_id, status) VALUES (?, ?, 'active')")
            ->execute(['Cash voucher foreign-branch tenant', $planId]);
        $otherTenantId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO branches (tenant_id, name, account_id, cost_center_id, active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([
            $otherTenantId,
            'Foreign tenant voucher branch',
            self::$cashAccountId,
            self::$costCenterId,
        ]);
        self::$otherTenantBranchId = (int) $db->lastInsertId();

        $db->prepare(
            'INSERT INTO customers (tenant_id, branch_id, name, account_id) VALUES (?, ?, ?, ?)'
        )->execute([
            self::$tenantId,
            self::$branchId,
            'Voucher integration customer',
            self::$customerAccountId,
        ]);
        self::$customerId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO suppliers (tenant_id, branch_id, name, email, address, account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            self::$tenantId,
            self::$branchId,
            'Voucher integration supplier',
            'voucher-supplier@example.invalid',
            'Integration test only',
            self::$supplierAccountId,
        ]);
        self::$supplierId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO roles (name, display_name, is_system_role, tenant_id)
             VALUES ('voucher_integration_operator', 'Voucher integration operator', 0, ?)"
        )->execute([self::$tenantId]);
        $roleId = (int) $db->lastInsertId();
        $user = $db->prepare(
            "INSERT INTO users (username, password, name, role_id, status, tenant_id, branch_id, email)
             VALUES (?, 'test-only', 'Voucher integration operator', ?, 'active', ?, ?, ?)"
        );
        $user->execute([
            'voucher-it-' . bin2hex(random_bytes(4)),
            $roleId,
            self::$tenantId,
            self::$branchId,
            'voucher-it-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ]);
        self::$userId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO users_role (user_id, role_id, tenant_id) VALUES (?, ?, ?)')
            ->execute([self::$userId, $roleId, self::$tenantId]);

        $permission = $db->prepare(
            'INSERT INTO permissions (name, description, category, tenant_id) VALUES (?, ?, ?, ?)'
        );
        $rolePermission = $db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
        );
        foreach (['voucher.create', 'voucher.edit', 'voucher.delete'] as $name) {
            $permission->execute([$name, $name, 'cash_vouchers', self::$tenantId]);
            $rolePermission->execute([$roleId, (int) $db->lastInsertId(), self::$tenantId]);
        }

        $db->prepare(
            "INSERT INTO subscriptions
                (tenant_id, plan_id, start_date, end_date, status, payment_status, auto_renew, user_id)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active', 'paid', 0, ?)"
        )->execute([self::$tenantId, $planId, self::$userId]);
        $db->prepare('INSERT INTO settings (tenant_id, key_name, value) VALUES (?, ?, ?)')
            ->execute([self::$tenantId, 'pos.sessions.enabled', '0']);

        $db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES ('Voucher integration cash', 'cash', ?, ?, 1, 'cash')"
        )->execute([self::$cashAccountId, self::$tenantId]);
        self::$paymentMethodId = (int) $db->lastInsertId();
        $db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES ('Voucher integration bank', 'bank', ?, ?, 1, 'bank')"
        )->execute([self::$bankAccountId, self::$tenantId]);
        self::$bankPaymentMethodId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, entry_date, description, created_by, status, reference_type, cost_center_id)
             VALUES (?, ?, 'Customer opening balance', ?, 'posted', 'manual', ?)"
        )->execute([self::$tenantId, date('Y-m-d'), self::$userId, self::$costCenterId]);
        $openingJournalId = (int) $db->lastInsertId();
        $line = $db->prepare(
            'INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $line->execute([
            $openingJournalId,
            self::$customerAccountId,
            100,
            0,
            'Customer opening receivable',
            self::$tenantId,
            self::$costCenterId,
        ]);
        $line->execute([
            $openingJournalId,
            self::$cashAccountId,
            100,
            0,
            'Cash opening balance',
            self::$tenantId,
            self::$costCenterId,
        ]);
        $line->execute([
            $openingJournalId,
            self::$supplierAccountId,
            0,
            100,
            'Supplier opening payable',
            self::$tenantId,
            self::$costCenterId,
        ]);
        $line->execute([
            $openingJournalId,
            $equityAccountId,
            0,
            100,
            'Opening equity',
            self::$tenantId,
            self::$costCenterId,
        ]);
    }

    public function testVoucherCreateEditCancelLifecycleIsAtomicAndIdempotent(): void
    {
        self::assertEquals(100, $this->customerBalance());
        self::assertEquals(100, $this->calculatedCustomerBalance());
        $idempotencyKey = 'voucher-mysql-' . bin2hex(random_bytes(8));
        $payload = $this->voucherPayload(40, 'Initial customer receipt');
        $created = $this->request('POST', '/api/v1/cash-vouchers', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdBody = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('success', $createdBody['status']);
        $originalId = (int) $createdBody['data']['id'];
        $originalJournalId = (int) $createdBody['data']['journal_entry_id'];

        $replay = $this->request('POST', '/api/v1/cash-vouchers', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getBody());
        self::assertSame(
            $originalId,
            (int) json_decode((string) $replay->getBody(), true, 512, JSON_THROW_ON_ERROR)['data']['id']
        );
        $differentPayload = $this->voucherPayload(41, 'Changed receipt');
        self::assertSame(409, $this->request('POST', '/api/v1/cash-vouchers', $differentPayload, [
            'Idempotency-Key' => $idempotencyKey,
        ])->getStatusCode());
        self::assertSame(1, $this->countForTenant(
            'cash_vouchers',
            "idempotency_key = " . self::$db->quote($idempotencyKey)
        ));

        $createdMovement = self::$db->prepare(
            "SELECT branch_id, status FROM cash_transactions
             WHERE tenant_id = ? AND reference_type = 'cash_voucher' AND reference_id = ?"
        );
        $createdMovement->execute([self::$tenantId, $originalId]);
        $movement = $createdMovement->fetch();
        self::assertSame(self::$branchId, (int) $movement['branch_id']);
        self::assertSame('completed', $movement['status']);
        self::assertJournalBalanced($originalJournalId);

        $roleId = (int) self::$db->query(
            'SELECT role_id FROM users WHERE id = ' . self::$userId
        )->fetchColumn();
        foreach (['voucher.edit', 'voucher.delete'] as $permissionName) {
            $permissionStatement = self::$db->prepare(
                'SELECT id FROM permissions WHERE tenant_id = ? AND name = ?'
            );
            $permissionStatement->execute([self::$tenantId, $permissionName]);
            $permissionId = (int) $permissionStatement->fetchColumn();
            $deleteGrant = self::$db->prepare(
                'DELETE FROM role_permissions WHERE tenant_id = ? AND role_id = ? AND permission_id = ?'
            );
            $deleteGrant->execute([self::$tenantId, $roleId, $permissionId]);

            $deniedResponse = $permissionName === 'voucher.edit'
                ? $this->request('PUT', '/api/v1/cash-vouchers/' . $originalId, $this->voucherPayload(42, 'Denied edit'))
                : $this->request('DELETE', '/api/v1/cash-vouchers/' . $originalId);
            self::assertSame(403, $deniedResponse->getStatusCode(), (string) $deniedResponse->getBody());

            self::$db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (?, ?, ?)'
            )->execute([$roleId, $permissionId, self::$tenantId]);
        }
        self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $originalId));

        $beforeForeignBranchAttempt = $this->countForTenant('cash_vouchers');
        $foreignBranchPayload = $this->voucherPayload(5, 'Foreign tenant branch');
        $foreignBranchPayload['branch_id'] = self::$otherTenantBranchId;
        $foreignBranchResponse = $this->request(
            'POST',
            '/api/v1/cash-vouchers',
            $foreignBranchPayload
        );
        self::assertSame(403, $foreignBranchResponse->getStatusCode(), (string) $foreignBranchResponse->getBody());
        self::assertSame($beforeForeignBranchAttempt, $this->countForTenant('cash_vouchers'));

        $updated = $this->request(
            'PUT',
            '/api/v1/cash-vouchers/' . $originalId,
            $this->voucherPayload(50, 'Corrected customer receipt')
        );
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        $updatedBody = json_decode((string) $updated->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $replacementId = (int) $updatedBody['data']['id'];
        $replacementJournalId = (int) $updatedBody['data']['journal_entry_id'];
        self::assertNotSame($originalId, $replacementId);
        self::assertSame('cancelled', $this->rowValue('cash_vouchers', 'status', $originalId));
        self::assertSame('reversed', $this->rowValue('journal_entries', 'status', $originalJournalId));
        self::assertSame('cancelled', $this->cashTransactionStatus($originalId));
        self::assertEquals(50, $this->customerBalance());
        self::assertEquals(50, $this->calculatedCustomerBalance());
        self::assertJournalBalanced($replacementJournalId);

        $deleted = $this->request('DELETE', '/api/v1/cash-vouchers/' . $replacementId);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertSame('cancelled', $this->rowValue('cash_vouchers', 'status', $replacementId));
        self::assertSame('reversed', $this->rowValue('journal_entries', 'status', $replacementJournalId));
        self::assertSame('cancelled', $this->cashTransactionStatus($replacementId));
        self::assertEquals(100, $this->customerBalance());
        self::assertEquals(100, $this->calculatedCustomerBalance());

        $saleId = $this->createSale();
        $linkedSalePayload = $this->voucherPayload(20, 'Linked sales invoice receipt');
        $linkedSalePayload['sale_id'] = $saleId;
        $linkedSaleResponse = $this->request('POST', '/api/v1/cash-vouchers', $linkedSalePayload);
        self::assertSame(201, $linkedSaleResponse->getStatusCode(), (string) $linkedSaleResponse->getBody());
        $linkedSaleBody = json_decode((string) $linkedSaleResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $linkedSaleVoucherId = (int) $linkedSaleBody['data']['id'];
        self::assertEquals(20, $this->salePaidAmount($saleId));
        $salePaymentCount = $this->paymentCountForVoucher($linkedSaleVoucherId);
        self::assertSame(1, $salePaymentCount);

        self::assertSame(409, $this->request(
            'PUT',
            '/api/v1/cash-vouchers/' . $linkedSaleVoucherId,
            $this->voucherPayload(21, 'Must not edit invoice payment')
        )->getStatusCode());
        self::assertSame(409, $this->request(
            'DELETE',
            '/api/v1/cash-vouchers/' . $linkedSaleVoucherId
        )->getStatusCode());
        self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $linkedSaleVoucherId));
        self::assertSame($salePaymentCount, $this->paymentCountForVoucher($linkedSaleVoucherId));
        self::assertEquals(20, $this->salePaidAmount($saleId));

        $purchaseId = $this->createPurchase();
        $linkedPurchasePayload = [
            'type' => 'payment',
            'date' => date('Y-m-d'),
            'amount' => 20,
            'currency' => 'EGP',
            'supplier_id' => self::$supplierId,
            'purchase_id' => $purchaseId,
            'account_id' => self::$cashAccountId,
            'branch_id' => self::$branchId,
            'payment_method_id' => self::$paymentMethodId,
            'cost_center_id' => self::$costCenterId,
            'notes' => 'Linked purchase invoice payment',
        ];
        $linkedPurchaseResponse = $this->request('POST', '/api/v1/cash-vouchers', $linkedPurchasePayload);
        self::assertSame(201, $linkedPurchaseResponse->getStatusCode(), (string) $linkedPurchaseResponse->getBody());
        $linkedPurchaseBody = json_decode((string) $linkedPurchaseResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $linkedPurchaseVoucherId = (int) $linkedPurchaseBody['data']['id'];
        self::assertEquals(20, $this->purchasePaidAmount($purchaseId));
        $purchasePaymentCount = $this->paymentCountForVoucher($linkedPurchaseVoucherId);
        self::assertSame(1, $purchasePaymentCount);

        self::assertSame(409, $this->request(
            'PUT',
            '/api/v1/cash-vouchers/' . $linkedPurchaseVoucherId,
            array_merge($linkedPurchasePayload, ['amount' => 21])
        )->getStatusCode());
        self::assertSame(409, $this->request(
            'DELETE',
            '/api/v1/cash-vouchers/' . $linkedPurchaseVoucherId
        )->getStatusCode());
        self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $linkedPurchaseVoucherId));
        self::assertSame($purchasePaymentCount, $this->paymentCountForVoucher($linkedPurchaseVoucherId));
        self::assertEquals(20, $this->purchasePaidAmount($purchaseId));

        $supplierBalanceBeforeDisbursement = $this->supplierBalance();
        $disbursementPayload = [
            'type' => 'payment',
            'date' => date('Y-m-d'),
            'amount' => 15,
            'currency' => 'EGP',
            'supplier_id' => self::$supplierId,
            'account_id' => self::$cashAccountId,
            'branch_id' => self::$branchId,
            'payment_method_id' => self::$paymentMethodId,
            'cost_center_id' => self::$costCenterId,
            'notes' => 'Unallocated supplier cash disbursement',
        ];
        $disbursementResponse = $this->request(
            'POST',
            '/api/v1/cash-vouchers',
            $disbursementPayload
        );
        self::assertSame(201, $disbursementResponse->getStatusCode(), (string) $disbursementResponse->getBody());
        $disbursementBody = json_decode((string) $disbursementResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $disbursementVoucherId = (int) $disbursementBody['data']['id'];
        $disbursementJournalId = (int) $disbursementBody['data']['journal_entry_id'];
        self::assertJournalBalanced($disbursementJournalId);
        $disbursementLines = self::$db->prepare(
            'SELECT account_id, debit_amount, credit_amount
             FROM journal_entry_lines WHERE journal_entry_id = ? ORDER BY debit_amount DESC'
        );
        $disbursementLines->execute([$disbursementJournalId]);
        $disbursementLines = $disbursementLines->fetchAll();
        self::assertSame(self::$supplierAccountId, (int) $disbursementLines[0]['account_id']);
        self::assertEquals(15, (float) $disbursementLines[0]['debit_amount']);
        self::assertSame(self::$cashAccountId, (int) $disbursementLines[1]['account_id']);
        self::assertEquals(15, (float) $disbursementLines[1]['credit_amount']);
        $disbursementMovement = self::$db->prepare(
            "SELECT type, supplier_id, status FROM cash_transactions
             WHERE tenant_id = ? AND reference_type = 'cash_voucher' AND reference_id = ?"
        );
        $disbursementMovement->execute([self::$tenantId, $disbursementVoucherId]);
        $disbursementMovementRow = $disbursementMovement->fetch();
        self::assertSame('expense', $disbursementMovementRow['type']);
        self::assertSame(self::$supplierId, (int) $disbursementMovementRow['supplier_id']);
        self::assertSame('completed', $disbursementMovementRow['status']);
        self::assertSame(0, $this->paymentCountForVoucher($disbursementVoucherId));

        $disbursementDeleteResponse = $this->request(
            'DELETE',
            '/api/v1/cash-vouchers/' . $disbursementVoucherId
        );
        self::assertSame(200, $disbursementDeleteResponse->getStatusCode(), (string) $disbursementDeleteResponse->getBody());
        self::assertSame('cancelled', $this->cashTransactionStatus($disbursementVoucherId));
        self::assertSame('reversed', $this->rowValue('journal_entries', 'status', $disbursementJournalId));
        self::assertEquals($supplierBalanceBeforeDisbursement, $this->supplierBalance());

        self::$db->prepare(
            "UPDATE settings SET value = '1' WHERE tenant_id = ? AND key_name = 'pos.sessions.enabled'"
        )->execute([self::$tenantId]);
        $beforeMissingSessionAttempt = [
            'cash_vouchers' => $this->countForTenant('cash_vouchers'),
            'journal_entries' => $this->countForTenant('journal_entries'),
            'cash_transactions' => $this->countForTenant('cash_transactions'),
        ];
        $missingSessionResponse = $this->request(
            'POST',
            '/api/v1/cash-vouchers',
            $this->voucherPayload(10, 'Cash voucher requires open session')
        );
        self::assertSame(400, $missingSessionResponse->getStatusCode(), (string) $missingSessionResponse->getBody());
        foreach ($beforeMissingSessionAttempt as $table => $count) {
            self::assertSame($count, $this->countForTenant($table), "Missing session must roll back {$table}.");
        }

        self::$db->prepare(
            "INSERT INTO settings (tenant_id, key_name, value) VALUES (?, 'cashier_session_exempt_users', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        )->execute([self::$tenantId, (string) self::$userId]);
        $exemptCashVoucherResponse = $this->request(
            'POST',
            '/api/v1/cash-vouchers',
            $this->voucherPayload(5, 'Cash voucher by session-exempt user')
        );
        self::assertSame(201, $exemptCashVoucherResponse->getStatusCode(), (string) $exemptCashVoucherResponse->getBody());
        $exemptCashVoucherBody = json_decode(
            (string) $exemptCashVoucherResponse->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertNull($exemptCashVoucherBody['data']['session_id']);
        self::$db->prepare(
            "DELETE FROM settings WHERE tenant_id = ? AND key_name = 'cashier_session_exempt_users'"
        )->execute([self::$tenantId]);

        $bankPayload = $this->voucherPayload(5, 'Bank receipt without cashier session');
        $bankPayload['payment_method_id'] = self::$bankPaymentMethodId;
        $bankPayload['account_id'] = self::$bankAccountId;
        $bankVoucherResponse = $this->request('POST', '/api/v1/cash-vouchers', $bankPayload);
        self::assertSame(201, $bankVoucherResponse->getStatusCode(), (string) $bankVoucherResponse->getBody());
        $bankVoucherBody = json_decode((string) $bankVoucherResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $bankVoucherId = (int) $bankVoucherBody['data']['id'];
        self::assertNull($bankVoucherBody['data']['session_id']);
        $bankMovement = self::$db->prepare(
            "SELECT session_id FROM cash_transactions
             WHERE tenant_id = ? AND reference_type = 'cash_voucher' AND reference_id = ?"
        );
        $bankMovement->execute([self::$tenantId, $bankVoucherId]);
        self::assertNull($bankMovement->fetchColumn());

        self::$db->prepare(
            "INSERT INTO cashier_sessions
                (tenant_id, cashier_id, session_type, opening_cash_amount, status, branch_id, created_by)
             VALUES (?, ?, 'manual', 100, 'open', ?, ?)"
        )->execute([self::$tenantId, self::$userId, self::$branchId, self::$userId]);
        $sessionId = (int) self::$db->lastInsertId();
        $sessionVoucherResponse = $this->request(
            'POST',
            '/api/v1/cash-vouchers',
            $this->voucherPayload(10, 'Voucher linked to cashier session')
        );
        self::assertSame(201, $sessionVoucherResponse->getStatusCode(), (string) $sessionVoucherResponse->getBody());
        $sessionVoucherBody = json_decode((string) $sessionVoucherResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $sessionVoucherId = (int) $sessionVoucherBody['data']['id'];
        self::assertSame($sessionId, (int) $sessionVoucherBody['data']['session_id']);
        $sessionMovement = self::$db->prepare(
            "SELECT session_id, branch_id FROM cash_transactions
             WHERE tenant_id = ? AND reference_type = 'cash_voucher' AND reference_id = ?"
        );
        $sessionMovement->execute([self::$tenantId, $sessionVoucherId]);
        $sessionMovementRow = $sessionMovement->fetch();
        self::assertSame($sessionId, (int) $sessionMovementRow['session_id']);
        self::assertSame(self::$branchId, (int) $sessionMovementRow['branch_id']);

        self::$db->prepare(
            "UPDATE cashier_sessions SET status = 'closed', closed_by = ?, end_time = NOW()
             WHERE id = ? AND tenant_id = ?"
        )->execute([self::$userId, $sessionId, self::$tenantId]);
        $voucherCountsBeforeClosedSessionOperations = [
            'cash_vouchers' => $this->countForTenant('cash_vouchers'),
            'journal_entries' => $this->countForTenant('journal_entries'),
            'cash_transactions' => $this->countForTenant('cash_transactions'),
        ];
        self::assertSame(409, $this->request(
            'PUT',
            '/api/v1/cash-vouchers/' . $sessionVoucherId,
            $this->voucherPayload(11, 'Must not edit closed session voucher')
        )->getStatusCode());
        self::assertSame(409, $this->request(
            'DELETE',
            '/api/v1/cash-vouchers/' . $sessionVoucherId
        )->getStatusCode());
        foreach ($voucherCountsBeforeClosedSessionOperations as $table => $count) {
            self::assertSame($count, $this->countForTenant($table), "Closed session must preserve {$table}.");
        }
        self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $sessionVoucherId));
        self::assertSame('completed', $this->cashTransactionStatus($sessionVoucherId));
        self::$db->prepare(
            "UPDATE settings SET value = '0' WHERE tenant_id = ? AND key_name = 'pos.sessions.enabled'"
        )->execute([self::$tenantId]);

        $beforeClosedPeriodAttempt = [
            'cash_vouchers' => $this->countForTenant('cash_vouchers'),
            'journal_entries' => $this->countForTenant('journal_entries'),
            'cash_transactions' => $this->countForTenant('cash_transactions'),
        ];
        $db = self::$db;
        $db->prepare(
            "INSERT INTO accounting_periods
                (tenant_id, period_name, start_date, end_date, status, created_by)
             VALUES (?, 'Voucher integration closed period', ?, ?, 'closed', ?)"
        )->execute([self::$tenantId, date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), self::$userId]);
        $closedPeriodResponse = $this->request(
            'POST',
            '/api/v1/cash-vouchers',
            $this->voucherPayload(10, 'Must not post in closed period'),
            ['Idempotency-Key' => 'voucher-closed-' . bin2hex(random_bytes(8))]
        );
        self::assertSame(409, $closedPeriodResponse->getStatusCode(), (string) $closedPeriodResponse->getBody());
        foreach ($beforeClosedPeriodAttempt as $table => $count) {
            self::assertSame($count, $this->countForTenant($table), "Closed period must roll back {$table}.");
        }
        $db->prepare(
            "DELETE FROM accounting_periods
             WHERE tenant_id = ? AND period_name = 'Voucher integration closed period'"
        )->execute([self::$tenantId]);
    }

    public function testCreatingPaymentVoucherWithInsufficientLiquidityRollsBackAndReturns402(): void
    {
        $suffix = bin2hex(random_bytes(5));
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-VOUCHER-CREATE-LIQ-' . $suffix,
            'Voucher create liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        $originalBankMethodAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalBankMethodAccount->execute([self::$bankPaymentMethodId, self::$tenantId]);
        $originalAccountId = (int) $originalBankMethodAccount->fetchColumn();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$liquidityAccountId, self::$bankPaymentMethodId, self::$tenantId]);

        try {
            $countsBefore = [
                'cash_vouchers' => $this->countForTenant('cash_vouchers'),
                'journal_entries' => $this->countForTenant('journal_entries'),
                'cash_transactions' => $this->countForTenant('cash_transactions'),
            ];
            $response = $this->request('POST', '/api/v1/cash-vouchers', [
                'type' => 'payment',
                'date' => date('Y-m-d'),
                'amount' => 25,
                'currency' => 'EGP',
                'supplier_id' => self::$supplierId,
                'account_id' => $liquidityAccountId,
                'branch_id' => self::$branchId,
                'payment_method_id' => self::$bankPaymentMethodId,
                'cost_center_id' => self::$costCenterId,
                'notes' => 'Must be rejected for insufficient liquidity',
            ]);

            self::assertSame(402, $response->getStatusCode(), (string) $response->getBody());
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('insufficient_liquidity', $body['error_code'] ?? null);
            self::assertSame($countsBefore['cash_vouchers'], $this->countForTenant('cash_vouchers'));
            self::assertSame($countsBefore['journal_entries'], $this->countForTenant('journal_entries'));
            self::assertSame($countsBefore['cash_transactions'], $this->countForTenant('cash_transactions'));
        } finally {
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalAccountId, self::$bankPaymentMethodId, self::$tenantId]);
        }
    }

    public function testEditingPaymentVoucherWithInsufficientLiquidityRollsBackAndReturns402(): void
    {
        $suffix = bin2hex(random_bytes(5));
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-VOUCHER-EDIT-LIQ-' . $suffix,
            'Voucher edit liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        $originalBankMethodAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalBankMethodAccount->execute([self::$bankPaymentMethodId, self::$tenantId]);
        $originalBankAccountId = (int) $originalBankMethodAccount->fetchColumn();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$liquidityAccountId, self::$bankPaymentMethodId, self::$tenantId]);
        $paymentMethodId = self::$bankPaymentMethodId;

        try {
            self::$db->prepare(
                "INSERT INTO journal_entries
                    (tenant_id, entry_date, description, created_by, status, reference_type, cost_center_id)
                 VALUES (?, ?, 'Voucher edit liquidity opening', ?, 'posted', 'manual', ?)"
            )->execute([self::$tenantId, date('Y-m-d'), self::$userId, self::$costCenterId]);
            $openingJournalId = (int) self::$db->lastInsertId();
            $openingLine = self::$db->prepare(
                'INSERT INTO journal_entry_lines
                    (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $openingLine->execute([
                $openingJournalId,
                $liquidityAccountId,
                30,
                0,
                'Voucher edit liquidity opening',
                self::$tenantId,
                self::$costCenterId,
            ]);
            $openingLine->execute([
                $openingJournalId,
                self::$supplierAccountId,
                0,
                30,
                'Voucher edit liquidity opening offset',
                self::$tenantId,
                self::$costCenterId,
            ]);

            $payload = [
                'type' => 'payment',
                'date' => date('Y-m-d'),
                'amount' => 20,
                'currency' => 'EGP',
                'supplier_id' => self::$supplierId,
                'account_id' => $liquidityAccountId,
                'branch_id' => self::$branchId,
                'payment_method_id' => $paymentMethodId,
                'cost_center_id' => self::$costCenterId,
                'notes' => 'Initial supplier disbursement',
            ];
            $created = $this->request('POST', '/api/v1/cash-vouchers', $payload);
            self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
            $createdBody = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $voucherId = (int) $createdBody['data']['id'];
            $journalId = (int) $createdBody['data']['journal_entry_id'];

            $balanceQuery = self::$db->prepare(
                "SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ? AND je.status = 'posted'"
            );
            $balanceQuery->execute([$liquidityAccountId, self::$tenantId]);
            self::assertEquals(10, (float) $balanceQuery->fetchColumn());

            $updated = $this->request(
                'PUT',
                '/api/v1/cash-vouchers/' . $voucherId,
                array_merge($payload, ['amount' => 50, 'notes' => 'Insufficient replacement amount'])
            );
            self::assertSame(402, $updated->getStatusCode(), (string) $updated->getBody());
            $errorBody = json_decode((string) $updated->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('insufficient_liquidity', $errorBody['error_code'] ?? null);
            self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $voucherId));
            self::assertSame('posted', $this->rowValue('journal_entries', 'status', $journalId));
            self::assertSame('completed', $this->cashTransactionStatus($voucherId));
            self::assertSame(1, $this->countForTenant('cash_vouchers', 'account_id = ' . $liquidityAccountId));
            $balanceQuery->execute([$liquidityAccountId, self::$tenantId]);
            self::assertEquals(10, (float) $balanceQuery->fetchColumn());
        } finally {
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalBankAccountId, self::$bankPaymentMethodId, self::$tenantId]);
        }
    }

    public function testReceiptVoucherCannotBeEditedOrCancelledAfterItsFundsWereSpent(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $originalCashAccount = self::$db->prepare(
            'SELECT account_id FROM payment_methods WHERE id = ? AND tenant_id = ?'
        );
        $originalCashAccount->execute([self::$paymentMethodId, self::$tenantId]);
        $originalCashAccountId = (int) $originalCashAccount->fetchColumn();
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-RECEIPT-REVERSAL-' . $suffix,
            'Receipt reversal liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$liquidityAccountId, self::$paymentMethodId, self::$tenantId]);
        self::$db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, entry_date, description, created_by, status, reference_type, cost_center_id)
             VALUES (?, ?, 'Receipt reversal opening', ?, 'posted', 'manual', ?)"
        )->execute([self::$tenantId, date('Y-m-d'), self::$userId, self::$costCenterId]);
        $openingJournalId = (int) self::$db->lastInsertId();
        $openingLine = self::$db->prepare(
            'INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $openingLine->execute([
            $openingJournalId,
            $liquidityAccountId,
            140,
            0,
            'Receipt reversal opening',
            self::$tenantId,
            self::$costCenterId,
        ]);
        $openingLine->execute([
            $openingJournalId,
            self::$supplierAccountId,
            0,
            140,
            'Receipt reversal opening offset',
            self::$tenantId,
            self::$costCenterId,
        ]);

        try {
            $receiptPayload = $this->voucherPayload(40, 'Receipt funds later spent');
            $receiptPayload['account_id'] = $liquidityAccountId;
            $receiptResponse = $this->request(
                'POST',
                '/api/v1/cash-vouchers',
                $receiptPayload
            );
            self::assertSame(201, $receiptResponse->getStatusCode(), (string) $receiptResponse->getBody());
            $receiptBody = json_decode((string) $receiptResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $receiptId = (int) $receiptBody['data']['id'];
            $receiptJournalId = (int) $receiptBody['data']['journal_entry_id'];

            $expensePayload = [
                'type' => 'payment',
                'date' => date('Y-m-d'),
                'amount' => 180,
                'currency' => 'EGP',
                'supplier_id' => self::$supplierId,
                'account_id' => $liquidityAccountId,
                'branch_id' => self::$branchId,
                'payment_method_id' => self::$paymentMethodId,
                'cost_center_id' => self::$costCenterId,
                'notes' => 'Spend opening and receipt funds',
            ];
            $expenseResponse = $this->request('POST', '/api/v1/cash-vouchers', $expensePayload);
            self::assertSame(201, $expenseResponse->getStatusCode(), (string) $expenseResponse->getBody());
            $expenseBody = json_decode((string) $expenseResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $expenseId = (int) $expenseBody['data']['id'];

            $cashBalance = self::$db->prepare(
                "SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ? AND je.status = 'posted'"
            );
            $cashBalance->execute([$liquidityAccountId, self::$tenantId]);
            self::assertEquals(0, (float) $cashBalance->fetchColumn());

            $before = [
                'vouchers' => $this->countForTenant('cash_vouchers'),
                'journals' => $this->countForTenant('journal_entries'),
                'cash_transactions' => $this->countForTenant('cash_transactions'),
            ];
            foreach ([
                $this->request(
                    'PUT',
                    '/api/v1/cash-vouchers/' . $receiptId,
                    array_merge($receiptPayload, ['amount' => 20, 'notes' => 'Reduced receipt must be rejected'])
                ),
                $this->request('DELETE', '/api/v1/cash-vouchers/' . $receiptId),
            ] as $response) {
                self::assertSame(402, $response->getStatusCode(), (string) $response->getBody());
                $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('insufficient_liquidity', $body['error_code'] ?? null);
                self::assertEquals(0, (float) $body['details']['current_balance']);
                self::assertEquals(40, (float) $body['details']['required_amount']);
                self::assertSame($before['vouchers'], $this->countForTenant('cash_vouchers'));
                self::assertSame($before['journals'], $this->countForTenant('journal_entries'));
                self::assertSame($before['cash_transactions'], $this->countForTenant('cash_transactions'));
                self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $receiptId));
                self::assertSame('posted', $this->rowValue('journal_entries', 'status', $receiptJournalId));
                self::assertSame('completed', $this->cashTransactionStatus($receiptId));
                self::assertSame('posted', $this->rowValue('cash_vouchers', 'status', $expenseId));
                $cashBalance->execute([$liquidityAccountId, self::$tenantId]);
                self::assertEquals(0, (float) $cashBalance->fetchColumn());
            }
        } finally {
            self::$db->prepare(
                'UPDATE payment_methods SET account_id = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$originalCashAccountId, self::$paymentMethodId, self::$tenantId]);
        }
    }

    public function testConcurrentRequestsWithSameIdempotencyKeyCreateOneVoucher(): void
    {
        $gate = 'voucher_idempotency_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE voucher_idempotency_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $key = 'voucher-concurrent-' . bin2hex(random_bytes(8));
        $roleId = (int) self::$db->query(
            'SELECT role_id FROM users WHERE id = ' . self::$userId
        )->fetchColumn();
        $payload = [
            'schema' => self::$schema,
            'tenant_id' => self::$tenantId,
            'user_id' => self::$userId,
            'role_id' => $roleId,
            'branch_id' => self::$branchId,
            'cost_center_id' => self::$costCenterId,
            'customer_id' => self::$customerId,
            'account_id' => self::$cashAccountId,
            'payment_method_id' => self::$paymentMethodId,
            'amount' => 10,
            'date' => date('Y-m-d'),
            'currency' => 'EGP',
            'notes' => 'Concurrent idempotent customer receipt',
            'idempotency_key' => $key,
            'barrier' => $gate,
        ];
        $workers = [];

        try {
            foreach (['first', 'second'] as $workerName) {
                $workerPayload = json_encode(
                    $payload + ['worker' => $workerName],
                    JSON_THROW_ON_ERROR
                );
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/CashVoucherMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent cash voucher worker.');
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

            self::assertEqualsCanonicalizing(
                [200, 201],
                array_column($results, 'status'),
                json_encode($results, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $voucherIds = array_map(
                static fn (array $result): int => (int) ($result['body']['data']['id'] ?? 0),
                $results
            );
            self::assertGreaterThan(0, $voucherIds[0]);
            self::assertSame($voucherIds[0], $voucherIds[1]);
            self::assertSame(1, $this->countForTenant(
                'cash_vouchers',
                'idempotency_key = ' . self::$db->quote($key)
            ));
            self::assertSame(1, $this->countForTenant(
                'cash_transactions',
                "reference_type = 'cash_voucher' AND reference_id = " . $voucherIds[0]
            ));
            self::assertSame(1, $this->countForTenant(
                'journal_entries',
                "reference_type = 'cash_voucher' AND reference_id = " . $voucherIds[0]
            ));
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
            self::$db->exec('DROP TABLE IF EXISTS voucher_idempotency_test_barrier');
        }
    }

    public function testConcurrentPaymentVouchersCannotOverdrawSharedLiquidityAccount(): void
    {
        $suffix = bin2hex(random_bytes(5));
        self::$db->prepare(
            'INSERT INTO accounts (tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([
            self::$tenantId,
            'IT-VOUCHER-RACE-' . $suffix,
            'Concurrent voucher liquidity account',
            'asset',
        ]);
        $liquidityAccountId = (int) self::$db->lastInsertId();
        self::$db->prepare(
            "INSERT INTO payment_methods (name, kind, account_id, tenant_id, is_active, account_type)
             VALUES (?, 'other', ?, ?, 1, 'other')"
        )->execute(['Concurrent voucher payment method ' . $suffix, $liquidityAccountId, self::$tenantId]);
        $paymentMethodId = (int) self::$db->lastInsertId();
        $equityAccountId = (int) self::$db->query(
            'SELECT id FROM accounts WHERE tenant_id = ' . self::$tenantId . " AND code = '3001' LIMIT 1"
        )->fetchColumn();

        self::$db->prepare(
            "INSERT INTO journal_entries
                (tenant_id, entry_date, description, created_by, status, reference_type, cost_center_id)
             VALUES (?, ?, 'Concurrent voucher liquidity opening', ?, 'posted', 'manual', ?)"
        )->execute([self::$tenantId, date('Y-m-d'), self::$userId, self::$costCenterId]);
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
            'Concurrent voucher liquidity opening',
            self::$tenantId,
            self::$costCenterId,
        ]);
        $openingLine->execute([
            $openingJournalId,
            $equityAccountId,
            0,
            100,
            'Concurrent voucher liquidity opening offset',
            self::$tenantId,
            self::$costCenterId,
        ]);

        $gate = 'voucher_liquidity_gate_' . bin2hex(random_bytes(8));
        self::$db->exec(
            'CREATE TABLE voucher_idempotency_test_barrier (
                gate VARCHAR(80) NOT NULL,
                worker VARCHAR(40) NOT NULL,
                PRIMARY KEY (gate, worker)
             ) ENGINE=InnoDB'
        );
        $roleId = (int) self::$db->query(
            'SELECT role_id FROM users WHERE id = ' . self::$userId
        )->fetchColumn();
        $workers = [];
        try {
            foreach (['first', 'second'] as $workerName) {
                $workerPayload = json_encode([
                    'voucher_type' => 'payment',
                    'schema' => self::$schema,
                    'tenant_id' => self::$tenantId,
                    'user_id' => self::$userId,
                    'role_id' => $roleId,
                    'branch_id' => self::$branchId,
                    'cost_center_id' => self::$costCenterId,
                    'supplier_id' => self::$supplierId,
                    'account_id' => $liquidityAccountId,
                    'payment_method_id' => $paymentMethodId,
                    'amount' => 60,
                    'date' => date('Y-m-d'),
                    'currency' => 'EGP',
                    'notes' => 'Concurrent liquidity payment voucher',
                    'idempotency_key' => $gate . '_' . $workerName,
                    'barrier' => $gate,
                    'worker' => $workerName,
                ], JSON_THROW_ON_ERROR);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/CashVoucherMySqlConcurrencyWorker.php'],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process, 'Unable to start an independent voucher liquidity worker.');
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
                self::assertSame(0, $exitCode, "Voucher liquidity worker failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                self::assertNotNull($resultLine, "Voucher worker returned no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
                $results[] = json_decode($resultLine, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertEqualsCanonicalizing([201, 402], array_column($results, 'status'), json_encode($results));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['status'] === 201
            ));
            $voucher = self::$db->prepare(
                "SELECT id, amount, journal_entry_id FROM cash_vouchers
                 WHERE tenant_id = ? AND account_id = ? AND type = 'payment'"
            );
            $voucher->execute([self::$tenantId, $liquidityAccountId]);
            $savedVouchers = $voucher->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(1, $savedVouchers);
            self::assertEquals(60, (float) $savedVouchers[0]['amount']);
            self::assertGreaterThan(0, (int) $savedVouchers[0]['journal_entry_id']);
            self::assertSame(1, $this->countForTenant(
                'cash_transactions',
                'reference_type = ' . self::$db->quote('cash_voucher') .
                ' AND reference_id = ' . (int) $savedVouchers[0]['id']
            ));

            $journalTotals = self::$db->prepare(
                'SELECT SUM(debit_amount), SUM(credit_amount)
                 FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?'
            );
            $journalTotals->execute([(int) $savedVouchers[0]['journal_entry_id'], self::$tenantId]);
            [$debitTotal, $creditTotal] = $journalTotals->fetch(PDO::FETCH_NUM);
            self::assertEquals(60, (float) $debitTotal);
            self::assertEquals(60, (float) $creditTotal);

            $balance = self::$db->prepare(
                'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
                 WHERE jel.account_id = ? AND jel.tenant_id = ? AND je.status = ?'
            );
            $balance->execute([$liquidityAccountId, self::$tenantId, 'posted']);
            self::assertEquals(40, (float) $balance->fetchColumn());
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
            self::$db->exec('DROP TABLE IF EXISTS voucher_idempotency_test_barrier');
        }
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        array $headers = []
    ): \Psr\Http\Message\ResponseInterface {
        $container = new Container();
        $container->set(PDO::class, self::$db);
        $container->set('db', self::$db);
        $container->set('config.security', ['jwt' => ['secret' => self::JWT_SECRET]]);
        $container->set(CashVouchersHandler::class, new CashVouchersHandler(self::$db));

        $app = AppFactory::create(container: $container);
        $app->addRoutingMiddleware();
        $app->group('/api/v1', function ($group) use ($container): void {
            (require dirname(__DIR__, 2) . '/app/routes/cash-vouchers.php')($group, $container);
        });
        $app->add(new SubscriptionMiddleware(self::$db));
        $app->add(new TenantMiddleware($container));
        $app->add(new JwtAuthMiddleware(
            new JwtBlacklistService(self::$db, self::JWT_SECRET),
            null,
            $container
        ));

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withParsedBody($payload);
        $token = JWT::encode([
            'user_id' => self::$userId,
            'tenant_id' => self::$tenantId,
            'branch_id' => self::$branchId,
            'role_id' => (int) self::$db->query(
                'SELECT role_id FROM users WHERE id = ' . self::$userId
            )->fetchColumn(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(8)),
        ], self::JWT_SECRET, 'HS256');
        $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    private function createSale(): int
    {
        self::$db->prepare(
            "INSERT INTO sales
                (tenant_id, customer_id, total_amount, net_total_amount, paid_amount, status,
                 user_id, branch_id, invoice_number, payment_method_id, cost_center_id)
             VALUES (?, ?, 100, 100, 0, 'pending_payment', ?, ?, ?, ?, ?)"
        )->execute([
            self::$tenantId,
            self::$customerId,
            self::$userId,
            self::$branchId,
            'VOUCHER-IT-SALE-' . bin2hex(random_bytes(4)),
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        return (int) self::$db->lastInsertId();
    }

    private function createPurchase(): int
    {
        self::$db->prepare(
            "INSERT INTO purchases
                (tenant_id, supplier_id, invoice_number, total_amount, paid_amount, user_id,
                 status, branch_id, payment_method_id, cost_center_id)
             VALUES (?, ?, ?, 100, 0, ?, 'due', ?, ?, ?)"
        )->execute([
            self::$tenantId,
            self::$supplierId,
            'VOUCHER-IT-PURCHASE-' . bin2hex(random_bytes(4)),
            self::$userId,
            self::$branchId,
            self::$paymentMethodId,
            self::$costCenterId,
        ]);
        return (int) self::$db->lastInsertId();
    }

    private function paymentCountForVoucher(int $voucherId): int
    {
        $statement = self::$db->prepare(
            'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND journal_entry_id = (
                SELECT journal_entry_id FROM cash_vouchers WHERE id = ? AND tenant_id = ?
            )'
        );
        $statement->execute([self::$tenantId, $voucherId, self::$tenantId]);
        return (int) $statement->fetchColumn();
    }

    private function salePaidAmount(int $saleId): float
    {
        $statement = self::$db->prepare('SELECT paid_amount FROM sales WHERE id = ? AND tenant_id = ?');
        $statement->execute([$saleId, self::$tenantId]);
        return (float) $statement->fetchColumn();
    }

    private function purchasePaidAmount(int $purchaseId): float
    {
        $statement = self::$db->prepare('SELECT paid_amount FROM purchases WHERE id = ? AND tenant_id = ?');
        $statement->execute([$purchaseId, self::$tenantId]);
        return (float) $statement->fetchColumn();
    }

    private function voucherPayload(float $amount, string $notes): array
    {
        return [
            'type' => 'receipt',
            'date' => date('Y-m-d'),
            'amount' => $amount,
            'currency' => 'EGP',
            'customer_id' => self::$customerId,
            'account_id' => self::$cashAccountId,
            'branch_id' => self::$branchId,
            'payment_method_id' => self::$paymentMethodId,
            'cost_center_id' => self::$costCenterId,
            'notes' => $notes,
        ];
    }

    private function countForTenant(string $table, string $where = '1 = 1'): int
    {
        if (!in_array($table, ['cash_vouchers', 'journal_entries', 'cash_transactions'], true)) {
            throw new InvalidArgumentException('Unsupported integration-test table.');
        }
        return (int) self::$db->query(
            "SELECT COUNT(*) FROM {$table} WHERE tenant_id = " . self::$tenantId . " AND {$where}"
        )->fetchColumn();
    }

    private function rowValue(string $table, string $column, int $id): string
    {
        if (!in_array($table, ['cash_vouchers', 'journal_entries'], true) || $column !== 'status') {
            throw new InvalidArgumentException('Unsupported integration-test row lookup.');
        }
        $statement = self::$db->prepare(
            "SELECT status FROM {$table} WHERE id = ? AND tenant_id = ?"
        );
        $statement->execute([$id, self::$tenantId]);
        return (string) $statement->fetchColumn();
    }

    private function cashTransactionStatus(int $voucherId): string
    {
        $statement = self::$db->prepare(
            "SELECT status FROM cash_transactions
             WHERE tenant_id = ? AND reference_type = 'cash_voucher' AND reference_id = ?"
        );
        $statement->execute([self::$tenantId, $voucherId]);
        return (string) $statement->fetchColumn();
    }

    private function customerBalance(): float
    {
        $statement = self::$db->prepare(
            "SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
             FROM journal_entry_lines jel
             JOIN journal_entries je ON je.id = jel.journal_entry_id
             WHERE jel.account_id = ? AND je.tenant_id = ?
               AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))"
        );
        $statement->execute([self::$customerAccountId, self::$tenantId]);
        return (float) $statement->fetchColumn();
    }

    private function calculatedCustomerBalance(): float
    {
        return (new App\Services\BalanceCalculationService(self::$db))->getAccountBalanceFromJournalEntries(
            self::$customerAccountId,
            self::$tenantId,
            'customer',
            new DateTime(date('Y-m-d'))
        );
    }

    private function supplierBalance(): float
    {
        $statement = self::$db->prepare(
            "SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0)
             FROM journal_entry_lines jel
             JOIN journal_entries je ON je.id = jel.journal_entry_id
             WHERE jel.account_id = ? AND je.tenant_id = ?
               AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))"
        );
        $statement->execute([self::$supplierAccountId, self::$tenantId]);
        return (float) $statement->fetchColumn();
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
}
