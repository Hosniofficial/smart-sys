<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\AccountManagementService;

/**
 * Manages tenant payment methods and their liquidity-account configuration.
 *
 * Methods are tenant-scoped; create and update validate kind uniqueness,
 * account ownership/type, and kind-specific liquidity settings. Wallet updates
 * may provision a default account through AccountManagementService. Routes and
 * permissions are defined in app/routes/payment-methods.php.
 */
class PaymentMethodsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('payment_methods');
    }

    /** List payment methods belonging to the authenticated tenant. */
    public function list(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $stmt = $this->db->prepare("
                  SELECT id, name, kind, description, payment_terms, account_id,
                      account_type, overdraft_limit, credit_limit, wallet_type,
                       created_at, updated_at,
                       0 AS is_global
                FROM payment_methods
                WHERE tenant_id = ?
                ORDER BY id ASC
            ");
            $stmt->execute([$tenantId]);
            $methods = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $methods, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list payment methods', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب طرق الدفع', 500);
        }
    }

    /** Update a tenant method's kind, account link, and liquidity policy. */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $id = isset($args['id']) ? (int) $args['id'] : 0;
            if ($id <= 0) {
                return $this->errorResponse($response, 'معرف طريقة الدفع غير صالح', 400);
            }

            $body = $request->getParsedBody() ?? [];
            $kind = isset($body['kind']) ? strtolower(trim((string) $body['kind'])) : null;

            $allowed = ['cash', 'bank', 'card', 'credit', 'wallet', 'other'];
            if (!$kind || !in_array($kind, $allowed, true)) {
                return $this->errorResponse(
                    $response,
                    'نوع الطريقة غير صالح. القيم المسموحة: cash, bank, card, credit, wallet, other',
                    400
                );
            }

            // false distinguishes an omitted account_id from an explicit null.
            $accountId = array_key_exists('account_id', $body)
                ? ($body['account_id'] !== null && $body['account_id'] !== '' ? (int) $body['account_id'] : null)
                : false; // false = not sent → don't change

            // Wallet methods without an account receive a provisioned wallet account.
            $walletKinds = ['wallet'];
            if (in_array($kind, $walletKinds, true) && ($accountId === false || $accountId === null)) {
                try {
                    $mgmt      = new AccountManagementService($this->db);
                    $accountId = $mgmt->provisionLiquidityAccount('1003', 'محفظة إلكترونية', $kind, $tenantId);
                } catch (\Throwable $e) {
                    $this->logger->warning('Auto-provision wallet account failed, proceeding without', [
                        'tenant_id' => $tenantId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            // Non-credit payment methods must retain a liquidity account.
            $creditKinds = ['credit'];
            if (!in_array($kind, $creditKinds, true) && $accountId === null) {
                return $this->errorResponse(
                    $response,
                    'لا يمكن إزالة الحساب المحاسبي من طريقة دفع من نوع ' . $kind,
                    400
                );
            }

            // Global seed templates are not editable through this endpoint.
            $tenantStmt = $this->db->prepare("
                SELECT id, kind, account_id, overdraft_limit, credit_limit, wallet_type
                FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1
            ");
            $tenantStmt->execute([$id, $tenantId]);
            $existing = $tenantStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return $this->errorResponse($response, 'لم يتم العثور على طريقة الدفع أو ليست ملك هذا الحساب', 404);
            }
            $this->assertUniqueTenantKind((int) $tenantId, $kind, $id);

            $resolvedAccountId = $kind === 'credit'
                ? 0
                : ($accountId === false ? (int) ($existing['account_id'] ?? 0) : (int) $accountId);
            if ($kind !== 'credit') {
                $this->assertLiquidityAssetAccount((int) $tenantId, $resolvedAccountId ?: null);
            }
            $liquiditySettings = $this->normalizeLiquiditySettings($kind, $body, $existing);

            $stmt = $this->db->prepare("
                UPDATE payment_methods
                SET kind = ?, account_id = ?, account_type = ?, overdraft_limit = ?,
                    credit_limit = ?, wallet_type = ?, allow_negative = 0, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([
                $kind,
                $resolvedAccountId > 0 ? $resolvedAccountId : null,
                $this->accountTypeForKind($kind),
                $liquiditySettings['overdraft_limit'],
                $liquiditySettings['credit_limit'],
                $liquiditySettings['wallet_type'],
                $id,
                $tenantId,
            ]);

            return $this->successResponse($response, [
                'id'         => $id,
                'kind'       => $kind,
                'account_id' => $resolvedAccountId > 0 ? $resolvedAccountId : null,
                'account_type' => $this->accountTypeForKind($kind),
                'overdraft_limit' => $liquiditySettings['overdraft_limit'],
                'credit_limit' => $liquiditySettings['credit_limit'],
                'wallet_type' => $liquiditySettings['wallet_type'],
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->errorResponse($response, 'توجد طريقة دفع أخرى من النوع نفسه لهذه المنشأة.', 409);
            }
            $this->logger->error('Failed to update payment method', [
                'payment_method_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);
            return $this->errorResponse($response, 'حدث خطأ أثناء تحديث طريقة الدفع', 500);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update payment method', [
                'payment_method_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء تحديث طريقة الدفع', 500);
        }
    }

    /** Create a tenant payment method with its account and liquidity settings. */
    public function create(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $body = $request->getParsedBody() ?? [];

            $name = isset($body['name']) ? trim((string) $body['name']) : '';
            $kind = isset($body['kind']) ? strtolower(trim((string) $body['kind'])) : '';
            $description = isset($body['description']) ? trim((string) $body['description']) : null;
            $paymentTerms = isset($body['payment_terms']) ? trim((string) $body['payment_terms']) : null;
            $accountId = isset($body['account_id']) && $body['account_id'] !== ''
                ? (int) $body['account_id']
                : null;
            if ($name === '') {
                return $this->errorResponse($response, 'الاسم مطلوب', 400);
            }

            $allowed = ['cash', 'bank', 'card', 'credit', 'wallet', 'other'];
            if ($kind === '' || !in_array($kind, $allowed, true)) {
                return $this->errorResponse(
                    $response,
                    'نوع الطريقة غير صالح. القيم المسموحة: cash, bank, card, credit, wallet, other',
                    400
                );
            }
            $this->assertUniqueTenantKind((int) $tenantId, $kind);

            // Credit methods represent receivables and do not require a liquidity account.
            $creditKinds = ['credit'];
            if (!in_array($kind, $creditKinds, true) && empty($accountId)) {
                return $this->errorResponse(
                    $response,
                    'الحساب المحاسبي (account_id) مطلوب لطريقة الدفع من نوع ' . $kind,
                    400
                );
            }

            if ($kind !== 'credit') {
                $this->assertLiquidityAssetAccount((int) $tenantId, $accountId);
            }
            $liquiditySettings = $this->normalizeLiquiditySettings($kind, $body);

            $targetTenantId = $tenantId;

            $stmt = $this->db->prepare("
                SELECT id
                FROM payment_methods
                WHERE name = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$name, $targetTenantId]);

            if ($stmt->fetch()) {
                return $this->errorResponse(
                    $response,
                    'طريقة دفع بنفس الاسم موجودة بالفعل في هذا النطاق',
                    409
                );
            }

            $stmt = $this->db->prepare("
                INSERT INTO payment_methods (
                    name,
                    kind,
                    description,
                    payment_terms,
                    account_id,
                    account_type,
                    allow_negative,
                    overdraft_limit,
                    credit_limit,
                    wallet_type,
                    created_at,
                    updated_at,
                    tenant_id
                ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NOW(), NOW(), ?)
            ");
            $stmt->execute([
                $name,
                $kind,
                $description,
                $paymentTerms,
                $accountId,
                $this->accountTypeForKind($kind),
                $liquiditySettings['overdraft_limit'],
                $liquiditySettings['credit_limit'],
                $liquiditySettings['wallet_type'],
                $targetTenantId
            ]);

            $id = (int) $this->db->lastInsertId();

            return $this->successResponse($response, [
                'id' => $id,
                'name' => $name,
                'kind' => $kind,
                'description' => $description,
                'payment_terms' => $paymentTerms,
                'account_id' => $accountId,
                'account_type' => $this->accountTypeForKind($kind),
                'overdraft_limit' => $liquiditySettings['overdraft_limit'],
                'credit_limit' => $liquiditySettings['credit_limit'],
                'wallet_type' => $liquiditySettings['wallet_type'],
                'tenant_id' => $targetTenantId
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->errorResponse($response, 'توجد طريقة دفع أخرى من النوع نفسه لهذه المنشأة.', 409);
            }
            $this->logger->error('Failed to create payment method', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'حدث خطأ أثناء إنشاء طريقة الدفع', 500);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create payment method', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء إنشاء طريقة الدفع', 500);
        }
    }

    private function accountTypeForKind(string $kind): string
    {
        return match ($kind) {
            'cash' => 'cash',
            'bank' => 'bank',
            'card' => 'card',
            'wallet' => 'wallet',
            'credit' => 'payable',
            default => 'other',
        };
    }

    private function assertUniqueTenantKind(int $tenantId, string $kind, ?int $excludeId = null): void
    {
        $sql = 'SELECT id FROM payment_methods WHERE tenant_id = ? AND kind = ?';
        $params = [$tenantId, $kind];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new \InvalidArgumentException('يوجد بالفعل نوع وسيلة دفع مماثل لهذه المنشأة.');
        }
    }

    private function assertLiquidityAssetAccount(int $tenantId, ?int $accountId): void
    {
        if (!$accountId || $accountId <= 0) {
            throw new \InvalidArgumentException('يجب ربط طريقة الدفع بحساب سيولة.');
        }

        $stmt = $this->db->prepare(
            'SELECT type FROM accounts WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1'
        );
        $stmt->execute([$accountId, $tenantId]);
        $accountType = $stmt->fetchColumn();
        if (strtolower((string) $accountType) !== 'asset') {
            throw new \InvalidArgumentException('يجب ربط طريقة الدفع بحساب من نوع أصل (صندوق/بنك/محفظة).');
        }
    }

    private function normalizeLiquiditySettings(string $kind, array $input, array $existing = []): array
    {
        $sameKind = ($existing['kind'] ?? null) === $kind;
        $rawOverdraft = array_key_exists('overdraft_limit', $input)
            ? $input['overdraft_limit']
            : ($sameKind ? ($existing['overdraft_limit'] ?? null) : null);
        $rawCreditLimit = array_key_exists('credit_limit', $input)
            ? $input['credit_limit']
            : ($sameKind ? ($existing['credit_limit'] ?? null) : null);
        $walletType = array_key_exists('wallet_type', $input)
            ? trim((string) $input['wallet_type'])
            : ($sameKind ? (string) ($existing['wallet_type'] ?? 'prepaid') : 'prepaid');

        $overdraftLimit = $kind === 'bank'
            ? $this->normalizeNonNegativeLimit($rawOverdraft, 'حد السحب على المكشوف')
            : null;
        $creditLimit = null;
        if ($kind === 'wallet') {
            if (!in_array($walletType, ['prepaid', 'postpaid'], true)) {
                throw new \InvalidArgumentException('يجب تحديد نوع المحفظة: مسبقة أو لاحقة الدفع.');
            }
            if ($walletType === 'postpaid') {
                $creditLimit = $this->normalizeNonNegativeLimit($rawCreditLimit, 'حد ائتمان المحفظة');
                if ($creditLimit === null || $creditLimit <= 0) {
                    throw new \InvalidArgumentException('يجب إدخال حد ائتمان موجب للمحفظة لاحقة الدفع.');
                }
            }
        } else {
            $walletType = null;
        }

        return [
            'overdraft_limit' => $overdraftLimit,
            'credit_limit' => $creditLimit,
            'wallet_type' => $walletType,
        ];
    }

    private function normalizeNonNegativeLimit($value, string $label): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new \InvalidArgumentException("{$label} يجب أن يكون رقمًا غير سالب.");
        }
        return round((float) $value, 2);
    }
}
