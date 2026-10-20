<?php

declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use App\Services\MonologHandler;
use App\Services\LabelService;
use App\Services\BalanceCalculationService;

/**
 * Builds account statements from journal entry lines and optional party references.
 *
 * Applies tenant, branch, date, status, cost-center, and reference-type filters;
 * returns opening/closing balances, transactions, daily balances, and customer or
 * supplier documents. Delegates account lookup and customer receivable calculation
 * to AccountingService and BalanceCalculationService.
 */
class AccountStatementHandler extends BaseHandler
{
    private array $accountCache = [];
    private BalanceCalculationService $balanceCalcService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('account_statement');
        $this->balanceCalcService = new BalanceCalculationService($db);
    }

    /**
     * Return an account ledger for the requested period, with optional customer or supplier references.
     *
     * Account identifiers may come from route arguments or query parameters; date, status,
     * branch, cost-center, reference-type, and pagination filters are read from the query.
     */
    public function getStatement(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID)', 403);
            }

            $accountId = $args['account_id'] ?? null;
            $partyType = $args['party_type'] ?? null; // e.g., 'customer' | 'supplier'
            $partyId = $args['party_id'] ?? null;

            $params = $request->getQueryParams();
            [$branchId, $branchCostCenterId] = $this->resolveBranchScope($request, (int) $tenantId);
            if (!$partyType && isset($params['party_type'])) {
                $partyType = $params['party_type'];
            }
            if (!$partyId && isset($params['party_id'])) {
                $partyId = $params['party_id'];
            }
            $startDate = $params['start_date'] ?? date('Y-m-01');
            $endDate = $params['end_date'] ?? date('Y-m-d');
            if (!$this->validateDateRange($startDate, $endDate)) {
                return $this->errorResponse($response, 'نطاق التاريخ غير صالح', 400);
            }
            $statusAny = isset($params['status']) && strtolower($params['status']) === 'any';

            $includeTypes = isset($params['include_types']) ? $this->parseTypes($params['include_types']) : [];
            $excludeTypes = isset($params['exclude_types']) ? $this->parseTypes($params['exclude_types']) : [];

            $costCenterId = null;
            if (isset($params['cost_center_id']) && $params['cost_center_id'] !== '') {
                $parsed = (int)$params['cost_center_id'];
                $costCenterId = $parsed > 0 ? $parsed : null;
            }

            $perPage = isset($params['per_page']) ? max(1, (int)$params['per_page']) : null;
            $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
            $limit = $perPage;
            $offset = $perPage ? ($page - 1) * $perPage : null;

            if (!$accountId) {
                $accountCode = $args['account_code'] ?? ($params['account_code'] ?? null);
                if ($accountCode) {
                    $accountId = $this->resolveAccountIdByCode($accountCode, $tenantId);
                }
            }
            if (!$accountId) {
                return $this->errorResponse($response, 'مطلوب رقم الحساب أو account_code', 400);
            }
            $acceptLang = $request->getHeaderLine('Accept-Language');
            $locale = (stripos($acceptLang, 'ar') === 0) ? 'ar' : 'en';
            $accountStmt = $this->db->prepare(
                "SELECT id, name, code, type FROM accounts WHERE id = :id AND tenant_id = :tenant_id"
            );
            $accountStmt->execute([':id' => $accountId, ':tenant_id' => $tenantId]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                return $this->errorResponse($response, 'لم يتم العثور على الحساب', 404);
            }

            $openingBalance = $this->calculateOpeningBalance(
                $accountId,
                $startDate,
                $tenantId,
                $statusAny,
                $includeTypes,
                $excludeTypes,
                $costCenterId,
                $branchId,
                $branchCostCenterId
            );

            $transactionsData = $this->getTransactions(
                $accountId,
                $startDate,
                $endDate,
                $tenantId,
                $statusAny,
                $includeTypes,
                $excludeTypes,
                $limit,
                $offset,
                $costCenterId,
                $branchId,
                $branchCostCenterId
            );
            if (!empty($transactionsData['transactions'])) {
                foreach ($transactionsData['transactions'] as &$tx) {
                    $type = isset($tx['transaction_type']) ? strtolower((string)$tx['transaction_type']) : null;
                    if (!$type && isset($tx['reference_type'])) {
                        $type = strtolower((string)$tx['reference_type']);
                    }

                    $statusCode = isset($tx['status_code']) ? strtolower((string)$tx['status_code']) : null;
                    $referenceType = strtolower((string) ($tx['reference_type'] ?? ''));
                    if ($referenceType === 'customer_payment_reclassification') {
                        $type = 'customer_payment_reclassification';
                        $tx['transaction_type'] = $type;
                        $tx['description'] = $locale === 'ar'
                            ? 'إثبات تحصيل سابق في حساب العميل — تسوية محاسبية وليست قبضًا جديدًا'
                            : 'Previously recorded receipt applied to the customer account — accounting reclassification, not a new receipt';
                    } elseif (
                        $referenceType === 'customer_payment'
                        && ($tx['payment_type'] ?? null) === 'customer'
                        && empty($tx['payment_sale_id'])
                    ) {
                        $tx['description'] = $locale === 'ar'
                            ? 'تحصيل دفعة من العميل'
                            : 'General customer payment';
                    }
                    $tx['reference_label'] = $referenceType === 'customer_payment'
                        && ($tx['payment_type'] ?? null) === 'customer'
                        && empty($tx['payment_sale_id'])
                        ? ($locale === 'ar' ? 'تحصيل دفعة' : 'Customer Payment')
                        : ($referenceType === 'customer_payment_reclassification'
                            ? ($locale === 'ar' ? 'تسوية قيد التحصيل' : 'Receipt Reclassification')
                            : $this->referenceLabel($type, $locale));
                    if (in_array($type, ['payment', 'receipt', 'refund', 'cash_voucher'])) {
                        $tx['status_label'] = $locale === 'ar' ? 'مُرحّل' : 'Posted';
                    } else {
                        $tx['status_label'] = $this->statusLabel($statusCode, $locale);
                    }
                }
                unset($tx);
            }
            $transactions = $transactionsData['transactions'];
            $dailyBalances = $this->fillDailyGaps($transactionsData['daily_balances'], $startDate, $endDate);

            $onlyNonZero = isset($params['only_nonzero']) && (string)$params['only_nonzero'] === '1';
            if ($onlyNonZero && !empty($dailyBalances)) {
                $dailyBalances = array_values(array_filter($dailyBalances, function ($d) {
                    return isset($d['transaction_count']) && (int)$d['transaction_count'] > 0;
                }));
            }
            $closingBalance = !empty($dailyBalances) ? end($dailyBalances)['closing_balance'] : $openingBalance;

            $extras = [];
            if ($partyType === 'customer' && $partyId) {
                $extras['sales_only'] = $this->getCustomerSalesOnly($partyId, $startDate, $endDate, $tenantId, $branchId);
                $extras['references'] = $this->getCustomerReferences($partyId, $startDate, $endDate, $tenantId, $costCenterId, $branchId);
            } elseif ($partyType === 'supplier' && $partyId) {
                $extras['references'] = $this->getSupplierReferences($partyId, $startDate, $endDate, $tenantId, $branchId);
            }

            $responseData = [
                'status' => 'success',
                'data' => [
                    'account' => [
                        'id' => $account['id'],
                        'name' => $account['name'],
                        'code' => $account['code'],
                        'type' => $account['type']
                    ],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'opening_balance' => (float) $openingBalance,
                    'transactions' => $transactions,
                    'daily_balances' => $dailyBalances,
                    'total_debit' => $transactionsData['total_debit'],
                    'total_credit' => $transactionsData['total_credit'],
                    'transaction_count' => $transactionsData['transaction_count'],
                    '_transaction_count_note' => 'Includes all journal entry lines (debits and credits). For customer statements: this includes sales invoices, receipts, returns, and refunds. Each transaction may appear as multiple lines (debit + credit lines).',
                    'closing_balance' => (float) $closingBalance
                ]
            ];

            if ($perPage) {
                $responseData['data']['pagination'] = [
                    'page' => $page,
                    'per_page' => $perPage,
                    'returned' => count($transactions)
                ];
            }

            if (isset($extras['sales_only'])) {
                $responseData['data']['sales_only'] = $extras['sales_only'];
            }
            if (isset($extras['references'])) {
                if (!empty($extras['references']['items'])) {
                    foreach ($extras['references']['items'] as &$ref) {
                        $type = isset($ref['type']) ? strtolower((string)$ref['type']) : null;
                        $statusCode = isset($ref['status']) ? strtolower((string)$ref['status']) : null;
                        $ref['reference_label'] = $this->referenceLabel($type, $locale);
                        if (in_array($type, ['payment', 'receipt', 'refund', 'cash_voucher'])) {
                            $ref['status_label'] = $locale === 'ar' ? 'مُرحّل' : 'Posted';
                        } else {
                            $ref['status_label'] = $this->statusLabel($statusCode, $locale);
                        }
                    }
                    unset($ref);
                }
                $extras['references']['_note'] = 'Combined view: sales invoices, returns, receipts, and refunds. Use transaction_subtype and return_group_id to link related items (e.g., return #5 with refund #8 both having return_group_id=5).';
                $responseData['data']['references'] = $extras['references'];
            }

            return $this->successResponse($response, $responseData['data'], 200);
        } catch (\DomainException $e) {
            $statusCode = (int) $e->getCode();
            return $this->errorResponse($response, $e->getMessage(), in_array($statusCode, [400, 403], true) ? $statusCode : 400);
        } catch (\Throwable $e) {
            $this->logger->error('Account statement error', ['error' => $e->getMessage(), 'tenant_id' => $tenantId ?? null]);
            return $this->errorResponse($response, 'فشل في إنشاء كشف الحساب', 500);
        }
    }

    private function resolveBranchScope(Request $request, int $tenantId): array
    {
        $user = $request->getAttribute('user');
        $isBranchPrivileged = \App\Utils\SuperAdminHelper::isAdminOrAbove(
            is_array($user) ? $user : null
        ) || (
            is_array($user)
            && (int) ($user['tenant_id'] ?? 0) > 0
            && strtolower((string) ($user['role'] ?? '')) === 'manager'
        );

        $branchId = null;
        if ($isBranchPrivileged) {
            $requestedBranchId = trim((string) ($request->getQueryParams()['branch_id'] ?? ''));
            if ($requestedBranchId !== '') {
                if (!ctype_digit($requestedBranchId) || (int) $requestedBranchId <= 0) {
                    throw new \DomainException('معرف الفرع غير صالح.', 400);
                }
                $branchId = (int) $requestedBranchId;
            }
        } else {
            $userId = $this->extractUserId($request);
            if (!$userId) {
                throw new \DomainException('تعذر تحديد المستخدم الحالي.', 403);
            }
            $userBranchStmt = $this->db->prepare(
                'SELECT branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $userBranchStmt->execute([$userId, $tenantId]);
            $branchId = (int) $userBranchStmt->fetchColumn();
            if ($branchId <= 0) {
                throw new \DomainException('لم يتم تعيين فرع لحسابك.', 403);
            }
        }

        if ($branchId === null) {
            return [null, null];
        }

        $branchStmt = $this->db->prepare(
            'SELECT cost_center_id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $branchStmt->execute([$branchId, $tenantId]);
        $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch) {
            throw new \DomainException('الفرع المحدد غير متاح لهذا المستأجر.', 403);
        }

        return [$branchId, !empty($branch['cost_center_id']) ? (int) $branch['cost_center_id'] : null];
    }

    private function buildBranchScopeSql(?int $branchId, ?int $branchCostCenterId, string $jeAlias = 'je', string $lineAlias = 'jel'): string
    {
        if ($branchId === null) {
            return '';
        }

        $branch = (int) $branchId;
        $conditions = [
            "{$jeAlias}.voucher_id IN (SELECT cv.id FROM cash_vouchers cv WHERE cv.tenant_id = {$jeAlias}.tenant_id AND cv.branch_id = {$branch})",
            "({$jeAlias}.reference_type = 'cash_voucher' AND EXISTS (SELECT 1 FROM cash_vouchers cv WHERE cv.id = {$jeAlias}.reference_id AND cv.tenant_id = {$jeAlias}.tenant_id AND cv.branch_id = {$branch}))",
            "({$jeAlias}.reference_type = 'sale' AND EXISTS (SELECT 1 FROM sales bs WHERE bs.id = {$jeAlias}.reference_id AND bs.tenant_id = {$jeAlias}.tenant_id AND bs.branch_id = {$branch}))",
            "({$jeAlias}.reference_type = 'purchase' AND EXISTS (SELECT 1 FROM purchases bp WHERE bp.id = {$jeAlias}.reference_id AND bp.tenant_id = {$jeAlias}.tenant_id AND bp.branch_id = {$branch}))",
            "({$jeAlias}.reference_type IN ('return', 'sale_return', 'purchase_return') AND EXISTS (SELECT 1 FROM returns br WHERE br.id = {$jeAlias}.reference_id AND br.tenant_id = {$jeAlias}.tenant_id AND br.branch_id = {$branch}))",
            "({$jeAlias}.reference_type IN ('customer_payment', 'customer_payment_reclassification', 'supplier_payment', 'payment', 'receipt', 'return_payment') AND EXISTS (SELECT 1 FROM payments bp WHERE bp.id = {$jeAlias}.reference_id AND bp.tenant_id = {$jeAlias}.tenant_id AND (bp.branch_id = {$branch} OR EXISTS (SELECT 1 FROM sales bs WHERE bs.id = bp.sale_id AND bs.tenant_id = bp.tenant_id AND bs.branch_id = {$branch}) OR EXISTS (SELECT 1 FROM purchases bpu WHERE bpu.id = bp.purchase_id AND bpu.tenant_id = bp.tenant_id AND bpu.branch_id = {$branch}) OR EXISTS (SELECT 1 FROM returns br WHERE br.id = bp.return_id AND br.tenant_id = bp.tenant_id AND br.branch_id = {$branch}))))",
            "EXISTS (SELECT 1 FROM sales bs WHERE bs.journal_entry_id = {$jeAlias}.id AND bs.tenant_id = {$jeAlias}.tenant_id AND bs.branch_id = {$branch})",
            "EXISTS (SELECT 1 FROM purchases bp WHERE bp.journal_entry_id = {$jeAlias}.id AND bp.tenant_id = {$jeAlias}.tenant_id AND bp.branch_id = {$branch})",
            "EXISTS (SELECT 1 FROM returns br WHERE br.journal_entry_id = {$jeAlias}.id AND br.tenant_id = {$jeAlias}.tenant_id AND br.branch_id = {$branch})",
            "EXISTS (SELECT 1 FROM payments bp WHERE bp.journal_entry_id = {$jeAlias}.id AND bp.tenant_id = {$jeAlias}.tenant_id AND bp.branch_id = {$branch})",
            "EXISTS (SELECT 1 FROM cash_vouchers cv WHERE cv.journal_entry_id = {$jeAlias}.id AND cv.tenant_id = {$jeAlias}.tenant_id AND cv.branch_id = {$branch})",
        ];
        if ($branchCostCenterId !== null) {
            $costCenter = (int) $branchCostCenterId;
            $conditions[] = "{$jeAlias}.cost_center_id = {$costCenter}";
            $conditions[] = "{$lineAlias}.cost_center_id = {$costCenter}";
        }

        return ' AND (' . implode(' OR ', $conditions) . ')';
    }

    private function validateDateRange($startDate, $endDate): bool
    {
        $start = \DateTime::createFromFormat('Y-m-d', $startDate);
        $end   = \DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$start || !$end) {
            return false;
        }

        return $start <= $end;
    }

    /** Derive a display status from the invoice balance, payments, and returns. */
    private function normalizeInvoiceStatus(
        string $status,
        float $paid,
        float $returnCredits,
        float $grandTotal,
        bool $hasReturns,
        float $returnedAmount = 0.0
    ): string {

        // Purchase returns are passed as returnedAmount; sales use allocated return credits.
        $outstanding = max(0.0, round($grandTotal - $paid - $returnCredits - $returnedAmount, 2));

        // Credit-note settlement without an outstanding balance.
        if ($returnCredits > 0.01 && $outstanding < 0.01 && !$hasReturns) {
            return 'settled_by_credit';
        }

        // Cash-settled invoices remain marked as returned when a return exists.
        if ($outstanding < 0.01 && $paid >= $grandTotal - 0.01) {
            return $hasReturns || $returnCredits > 0.01 ? 'returned' : 'paid';
        }

        if ($outstanding < 0.01 && $paid > 0.01 && ($returnCredits > 0.01 || $returnedAmount > 0.01)) {
            return 'settled_mixed';
        }

        if ($outstanding < 0.01 && $paid < 0.01) {
            // A fully returned, unpaid purchase is reported as returned, not merely closed.
            if ($returnedAmount > 0.01 && $returnedAmount >= $grandTotal - 0.01) {
                return 'returned';
            }
            if ($returnCredits > 0.01 && !$hasReturns) {
                return 'settled_by_credit';
            }
            return 'closed_by_return';
        }

        if ($paid <= 0.01) {
            return $status;
        }

        return $status;
    }

    private function calculateOpeningBalance($accountId, $startDate, $tenantId, $statusAny = false, array $includeTypes = [], array $excludeTypes = [], $costCenterId = null, ?int $branchId = null, ?int $branchCostCenterId = null)
    {
        $sql = "
            SELECT 
                COALESCE(SUM(jel.debit_amount), 0) - COALESCE(SUM(jel.credit_amount), 0) as balance
            FROM 
                journal_entry_lines jel
                JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE 
                jel.account_id = :account_id
                AND je.tenant_id = :tenant_id
                AND je.entry_date < :start_date
        ";
        if (!$statusAny) {
            $sql .= " AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))";
        }
        $params = [
            ':account_id' => $accountId,
            ':tenant_id' => $tenantId,
            ':start_date' => $startDate . ' 00:00:00',
        ];
        if ($costCenterId !== null) {
            $sql .= " AND je.cost_center_id = :cost_center_id";
            $params[':cost_center_id'] = $costCenterId;
        }
        $sql .= $this->buildBranchScopeSql($branchId, $branchCostCenterId);
        if (!empty($includeTypes)) {
            $inPlaceholders = [];
            foreach ($includeTypes as $i => $t) {
                $inPlaceholders[] = ":inc_$i";
                $params[":inc_$i"] = $t;
            }
            $sql .= " AND (je.reference_type IN (" . implode(',', $inPlaceholders) . "))";
        } elseif (!empty($excludeTypes)) {
            $exPlaceholders = [];
            foreach ($excludeTypes as $i => $t) {
                $exPlaceholders[] = ":exc_$i";
                $params[":exc_$i"] = $t;
            }
            $sql .= " AND (je.reference_type NOT IN (" . implode(',', $exPlaceholders) . "))";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (float) $stmt->fetchColumn();
    }

    private function getTransactions($accountId, $startDate, $endDate, $tenantId, $statusAny = false, array $includeTypes = [], array $excludeTypes = [], $limit = null, $offset = null, $costCenterId = null, ?int $branchId = null, ?int $branchCostCenterId = null)
    {
        $sql = "
            SELECT
                je.entry_date as date,
                je.reference_type as reference_type,
                je.reference_id as reference_id,
                je.status as status_code,
                CASE
                    WHEN linked_sale.id IS NOT NULL THEN CONCAT('sale#', linked_sale.id)
                    ELSE CONCAT(COALESCE(je.reference_type, ''), '#', COALESCE(je.reference_id, ''))
                END AS reference,
                linked_sale.id AS sale_id,
                linked_sale.invoice_number AS invoice_number,
                payment_reference.reference_number AS payment_reference_number,
                payment_reference.type AS payment_type,
                payment_reference.sale_id AS payment_sale_id,
                jel.description,
                jel.debit_amount as debit,
                jel.credit_amount as credit,
                'journal' as source_type,
                je.id as source_id,
                je.cost_center_id as cost_center_id,
                -- Detect settlement lines directly based on debit/credit
                -- For a settlement line on customer account: it's a credit (money in/out)
                -- Classify based on transaction reference type and direction
                CASE 
                    -- Sale settlement line (credit > 0 = payment received from customer)
                    -- Using 0 instead of 0.01 to catch even small payments
                    WHEN je.reference_type = 'sale' AND jel.credit_amount > 0 THEN 'receipt'
                    WHEN je.reference_type IN ('customer_payment', 'customer_payment_reclassification')
                         AND jel.credit_amount > 0 THEN 'receipt'
                    -- Purchase settlement line (debit > 0 = payment made to supplier)
                    WHEN je.reference_type = 'purchase' AND jel.debit_amount > 0 THEN 'payment'
                    WHEN je.reference_type = 'supplier_payment' AND jel.debit_amount > 0 THEN 'payment'
                    -- For receipt/payment/refund types, keep as-is
                    WHEN je.reference_type IN ('receipt', 'payment', 'refund', 'cash_voucher') THEN je.reference_type
                    -- Default: return null so it falls back to reference_type
                    ELSE NULL
                END AS actual_payment_type
            FROM
                journal_entry_lines AS jel
            JOIN
                journal_entries AS je ON jel.journal_entry_id = je.id
            LEFT JOIN payments AS payment_reference
                ON je.reference_type IN ('customer_payment', 'customer_payment_reclassification')
                AND payment_reference.id = je.reference_id
                AND payment_reference.tenant_id = je.tenant_id
            LEFT JOIN sales AS linked_sale
                ON linked_sale.id = payment_reference.sale_id
                AND linked_sale.tenant_id = payment_reference.tenant_id
            WHERE
                jel.account_id = :account_id
                AND je.tenant_id = :tenant_id
                AND je.entry_date >= :start_date
                AND je.entry_date < :end_date_plus_one
        ";
        if (!$statusAny) {
            $sql .= " AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))";
        }
        $params = [
            ':account_id' => $accountId,
            ':tenant_id' => $tenantId,
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date_plus_one' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
        ];
        if ($costCenterId !== null) {
            $sql .= " AND je.cost_center_id = :cost_center_id";
            $params[':cost_center_id'] = $costCenterId;
        }
        $sql .= $this->buildBranchScopeSql($branchId, $branchCostCenterId);
        if (!empty($includeTypes)) {
            $inPlaceholders = [];
            foreach ($includeTypes as $i => $t) {
                $inPlaceholders[] = ":inc_$i";
                $params[":inc_$i"] = $t;
            }
            $sql .= " AND (je.reference_type IN (" . implode(',', $inPlaceholders) . "))";
        } elseif (!empty($excludeTypes)) {
            $exPlaceholders = [];
            foreach ($excludeTypes as $i => $t) {
                $exPlaceholders[] = ":exc_$i";
                $params[":exc_$i"] = $t;
            }
            $sql .= " AND (je.reference_type NOT IN (" . implode(',', $exPlaceholders) . "))";
        }
        $sql .= " ORDER BY je.entry_date ASC, jel.id ASC";
        if ($limit !== null) {
            $sql .= " LIMIT :_limit";
            $params[':_limit'] = (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET :_offset";
                $params[':_offset'] = (int)$offset;
            }
        }
        $sql .= ";";

        try {
            $stmt = $this->db->prepare($sql);
            // ربط باراميترات limit/offset كأعداد صحيحة عند الحاجة
            foreach ($params as $k => $v) {
                if ($k === ':_limit' || $k === ':_offset') {
                    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($k, $v);
                }
            }
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            $this->logger->error('Error fetching account statement transactions', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'branch_id' => $branchId,
            ]);
            throw $e;
        }

        $runningBalance = $this->calculateOpeningBalance(
            $accountId,
            $startDate,
            $tenantId,
            $statusAny,
            $includeTypes,
            $excludeTypes,
            $costCenterId,
            $branchId,
            $branchCostCenterId
        );
        $dailyBalances = [];
        $currentDay = null;
        $dayTransactions = [];
        $dayStartBalance = $runningBalance;

        foreach ($transactions as &$transaction) {
            $actualPaymentType = $transaction['actual_payment_type'] ?? null;

            if (!$actualPaymentType) {
                $refType = strtolower((string)($transaction['reference_type'] ?? ''));
                $credit = (float)($transaction['credit'] ?? 0);
                $debit = (float)($transaction['debit'] ?? 0);

                if ($refType === 'sale' && $credit > 0) {
                    $actualPaymentType = 'receipt';
                } elseif (in_array($refType, ['purchase', 'supplier_payment'], true) && $debit > 0) {
                    $actualPaymentType = 'payment';
                } elseif (in_array($refType, ['receipt', 'payment', 'refund', 'cash_voucher'])) {
                    $actualPaymentType = $refType;
                }
            }

            $refType = $actualPaymentType ? strtolower((string)$actualPaymentType) : strtolower((string)($transaction['reference_type'] ?? ''));
            $transaction['transaction_type'] = $refType ?: 'unknown';

            $transactionDate = $transaction['date'];

            if ($currentDay !== $transactionDate) {
                if ($currentDay !== null) {
                    $dailyBalances[] = [
                        'date' => $currentDay,
                        'opening_balance' => $dayStartBalance,
                        'closing_balance' => $runningBalance,
                        'transaction_count' => count($dayTransactions),
                        'day_debit' => array_sum(array_column($dayTransactions, 'debit')),
                        'day_credit' => array_sum(array_column($dayTransactions, 'credit'))
                    ];
                }
                $currentDay = $transactionDate;
                $dayStartBalance = $runningBalance;
                $dayTransactions = [];
            }

            $transaction['balance'] = $runningBalance + $transaction['debit'] - $transaction['credit'];

            $balance = $transaction['balance'];
            if (abs($balance) < 0.01) {
                $transaction['balance_nature'] = 'zero';
            } elseif ($balance > 0) {
                $transaction['balance_nature'] = 'debit';
            } else {
                $transaction['balance_nature'] = 'credit';
            }

            $runningBalance = $transaction['balance'];
            $dayTransactions[] = $transaction;
        }

        if ($currentDay !== null) {
            $dailyBalances[] = [
                'date' => $currentDay,
                'opening_balance' => $dayStartBalance,
                'closing_balance' => $runningBalance,
                'transaction_count' => count($dayTransactions),
                'day_debit' => array_sum(array_column($dayTransactions, 'debit')),
                'day_credit' => array_sum(array_column($dayTransactions, 'credit'))
            ];
        }

        return [
            'transactions' => $transactions,
            'daily_balances' => $dailyBalances,
            'total_debit' => (float)array_sum(array_column($transactions, 'debit')),
            'total_credit' => (float)array_sum(array_column($transactions, 'credit')),
            'transaction_count' => count($transactions)
        ];
    }

    // قسم مرجعي: فواتير العميل خلال الفترة دون تأثير على الأرصدة
    private function getCustomerSalesOnly($customerId, $startDate, $endDate, $tenantId, ?int $branchId = null)
    {
        $branchFilterSql = $branchId !== null ? ' AND s.branch_id = :branch_id' : '';
        $sql = "
            SELECT 
                s.id,
                s.created_at AS date,
                s.invoice_number,
                s.status,
                s.total_amount,
                s.net_total_amount,
                s.tax_amount,
                s.discount_value,
                s.discount_type,
                s.branch_id,
                s.user_id,
                s.journal_entry_id,
                -- Net completed refunds against payments and include allocated customer payments.
                COALESCE((
                    SELECT COALESCE(SUM(
                        CASE WHEN p.type = 'return_payment' THEN -p.amount ELSE p.amount END
                    ), 0)
                    FROM payments p
                    WHERE p.sale_id = s.id 
                      AND p.tenant_id = s.tenant_id
                      AND p.is_draft = 0
                      AND p.status = 'completed'
                ), 0)
                + COALESCE((
                    SELECT SUM(cpa.allocated_amount)
                    FROM customer_payment_allocations cpa
                    JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                    WHERE cpa.sale_id = s.id AND cpa.tenant_id = s.tenant_id
                      AND ap.is_draft = 0 AND ap.status = 'completed'
                ), 0) AS calculated_paid_amount,
                -- Due date calculation (default to +30 days from creation)
                DATE_ADD(s.created_at, INTERVAL 30 DAY) AS due_date,
                -- Calculate days overdue
                DATEDIFF(CURDATE(), DATE_ADD(s.created_at, INTERVAL 30 DAY)) AS days_overdue,
                (
                    SELECT COUNT(*) 
                    FROM sales_items si 
                    WHERE si.sale_id = s.id AND si.tenant_id = s.tenant_id
                ) AS items_count,
                (
                    SELECT GROUP_CONCAT(r.id) FROM returns r 
                    WHERE r.return_type = 'sale' 
                      AND r.sale_id = s.id 
                      AND r.tenant_id = s.tenant_id
                ) AS return_ids,
                COALESCE(SUM(rca.allocated_amount), 0) AS return_credits_applied
            FROM sales s
            LEFT JOIN return_credit_allocations rca ON rca.sale_id = s.id 
                AND rca.tenant_id = s.tenant_id
            WHERE s.tenant_id = :tenant_id 
              AND s.customer_id = :customer_id
              AND s.created_at >= :start_date AND s.created_at <= :end_date_full
              AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')
              {$branchFilterSql}
            GROUP BY s.id
            ORDER BY s.created_at ASC, s.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $queryParams = [
            ':tenant_id' => $tenantId,
            ':customer_id' => $customerId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($branchId !== null) {
            $queryParams[':branch_id'] = $branchId;
        }
        $stmt->execute($queryParams);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $saleIds = array_column($rows, 'id');
        $amountDueMap = !empty($saleIds)
            ? $this->balanceCalcService->getAmountDueBatch('sale', (int) $tenantId, array_map('intval', $saleIds))
            : [];

        $items = array_map(function ($r) use ($amountDueMap) {
            $net = isset($r['net_total_amount']) ? (float)$r['net_total_amount'] : 0.0;
            $paid = isset($r['calculated_paid_amount']) ? (float)$r['calculated_paid_amount'] : (isset($r['paid_amount']) ? (float)$r['paid_amount'] : 0.0);
            $returnCredits = isset($r['return_credits_applied']) ? (float)$r['return_credits_applied'] : 0.0;

            $grand_total = $net + ((float)($r['tax_amount'] ?? 0));
            $outstanding = $amountDueMap[(int) $r['id']] ?? max(0.0, $grand_total - $paid - $returnCredits);

            $status = $r['status'];
            $hasReturns = !empty($r['return_ids']);

            $status = $this->normalizeInvoiceStatus(
                $status,
                $paid,
                $returnCredits,
                $grand_total,
                $hasReturns
            );

            $returnGroupId = null;
            if ($hasReturns) {
                $returnIds = explode(',', $r['return_ids']);
                if (!empty($returnIds)) {
                    $returnGroupId = (int)$returnIds[0];
                }
            }

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'invoice_number' => $r['invoice_number'],
                'reference' => 'sale#' . $r['id'],
                'status' => $status,
                'total_amount' => isset($r['total_amount']) ? (float)$r['total_amount'] : 0.0,
                'net_total_amount' => $net,
                'discount_value' => isset($r['discount_value']) ? (float)$r['discount_value'] : 0.0,
                'discount_type' => $r['discount_type'] ?? null,
                'tax_amount' => isset($r['tax_amount']) ? (float)$r['tax_amount'] : 0.0,
                'paid_amount' => $paid,  // Calculated from payments table
                'outstanding' => $outstanding,  // Accurate based on actual payments
                'due_date' => isset($r['due_date']) ? $r['due_date'] : null,
                'days_overdue' => isset($r['days_overdue']) ? (int)$r['days_overdue'] : 0,
                'items_count' => isset($r['items_count']) ? (int)$r['items_count'] : 0,
                'has_returns' => !empty($r['return_ids']) ? true : false,
                'return_group_id' => $returnGroupId,
                'branch_id' => isset($r['branch_id']) ? (int)$r['branch_id'] : null,
                'user_id' => isset($r['user_id']) ? (int)$r['user_id'] : null,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => isset($r['journal_entry_id']) && $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
            ];
        }, $rows);

        return [
            'count' => count($items),
            'items' => $items,
            '_note' => 'Sales invoices only. Referenced payments (receipts/refunds) shown separately in "references" section.'
        ];
    }

    // مرجع موحد: فواتير مبيعات + مرتجعات مبيعات + إيصالات القبض للعميل خلال الفترة
    private function getCustomerReferences($customerId, $startDate, $endDate, $tenantId, $costCenterId = null, ?int $branchId = null)
    {
        // Calculate paid amounts from completed payments and exclude canceled invoices.
        $costCenterFilterSql = $costCenterId !== null ? " AND s.cost_center_id = :cost_center_id" : '';
        $branchFilterSql = $branchId !== null ? ' AND s.branch_id = :branch_id' : '';
        $sqlSales = "
            SELECT 
                s.id,
                s.created_at AS date,
                s.invoice_number,
                s.status,
                s.total_amount,
                s.net_total_amount,
                s.tax_amount,
                s.journal_entry_id,
                s.cost_center_id,
                -- Net completed refunds against payments and include allocated customer payments.
                COALESCE((
                    SELECT COALESCE(SUM(
                        CASE WHEN p2.type = 'return_payment' THEN -p2.amount ELSE p2.amount END
                    ), 0)
                    FROM payments p2
                    WHERE p2.sale_id = s.id
                      AND p2.tenant_id = s.tenant_id
                      AND p2.is_draft = 0
                      AND p2.status = 'completed'
                ), 0)
                + COALESCE((
                    SELECT SUM(cpa.allocated_amount)
                    FROM customer_payment_allocations cpa
                    JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                    WHERE cpa.sale_id = s.id AND cpa.tenant_id = s.tenant_id
                      AND ap.is_draft = 0 AND ap.status = 'completed'
                ), 0) AS paid_amount,
                (
                    SELECT COUNT(*) 
                    FROM returns r 
                    WHERE r.return_type = 'sale' 
                      AND r.sale_id = s.id 
                      AND r.tenant_id = s.tenant_id
                ) > 0 AS has_returns,
                COALESCE(SUM(rca.allocated_amount), 0) AS return_credits_applied
            FROM sales s
            LEFT JOIN return_credit_allocations rca ON rca.sale_id = s.id 
                AND rca.tenant_id = s.tenant_id
            WHERE s.tenant_id = :tenant_id 
              AND s.customer_id = :customer_id
              AND s.created_at >= :start_date AND s.created_at <= :end_date_full
              AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')
              {$costCenterFilterSql}
              {$branchFilterSql}
            GROUP BY s.id
            ORDER BY s.created_at ASC, s.id ASC
        ";
        $stmtSales = $this->db->prepare($sqlSales);
        $salesParams = [
            ':tenant_id' => $tenantId,
            ':customer_id' => $customerId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($costCenterId !== null) {
            $salesParams[':cost_center_id'] = $costCenterId;
        }
        if ($branchId !== null) {
            $salesParams[':branch_id'] = $branchId;
        }
        $stmtSales->execute($salesParams);
        $sales = array_map(function ($r) {
            // Normalize status using unified logic
            $net = isset($r['net_total_amount']) ? (float)$r['net_total_amount'] : 0.0;
            $paid = isset($r['paid_amount']) ? (float)$r['paid_amount'] : 0.0;
            $returnCredits = isset($r['return_credits_applied']) ? (float)$r['return_credits_applied'] : 0.0;
            $tax = isset($r['tax_amount']) ? (float)$r['tax_amount'] : 0.0;
            $grandTotal = $net + $tax;
            $hasReturns = (bool)$r['has_returns'];

            $normalizedStatus = $this->normalizeInvoiceStatus(
                $r['status'],
                $paid,
                $returnCredits,
                $grandTotal,
                $hasReturns
            );

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'type' => 'sale',
                'invoice_number' => $r['invoice_number'],
                'reference' => 'sale#' . $r['id'],
                'status' => $normalizedStatus,  // ← استخدام الـ status المطبع
                'total_amount' => isset($r['total_amount']) ? (float)$r['total_amount'] : null,
                'net_total_amount' => isset($r['net_total_amount']) ? (float)$r['net_total_amount'] : null,
                'paid_amount' => isset($r['paid_amount']) ? (float)$r['paid_amount'] : null,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
                'cost_center_id' => $r['cost_center_id'] ?? null,
            ];
        }, ($stmtSales->fetchAll(PDO::FETCH_ASSOC) ?: []));

        // 2) مرتجعات المبيعات
        $returnBranchFilterSql = $branchId !== null ? ' AND r.branch_id = :branch_id' : '';
        $sqlReturns = "
            SELECT 
                r.id,
                r.created_at AS date,
                r.return_number,
                r.invoice_number,
                r.status,
                r.grand_total AS total_amount,
                r.journal_entry_id,
                EXISTS(
                    SELECT 1
                    FROM payments p
                    WHERE p.return_id = r.id
                      AND p.tenant_id = r.tenant_id
                      AND p.amount > 0.01
                ) AS has_refund
            FROM returns r
            WHERE r.tenant_id = :tenant_id 
              AND r.return_type = 'sale'
              AND r.customer_id = :customer_id
              AND r.created_at >= :start_date AND r.created_at <= :end_date_full
              {$returnBranchFilterSql}
        ";
        $stmtReturns = $this->db->prepare($sqlReturns);
        $returnParams = [
            ':tenant_id' => $tenantId,
            ':customer_id' => $customerId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($branchId !== null) {
            $returnParams[':branch_id'] = $branchId;
        }
        $stmtReturns->execute($returnParams);
        $returns = array_map(function ($r) {
            // Distinguish a return with an actual refund payment.
            $subtype = 'sales_return_only';
            $hasRefund = isset($r['has_refund']) ? (int)$r['has_refund'] : 0;

            if ($hasRefund > 0) {
                $subtype = 'sales_return_refund';
            }

            $typeLabel = 'مرتجع بيع';
            if ($subtype === 'sales_return_refund') {
                $typeLabel = 'استرداد مرتجع';
            }

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'type' => 'sales_return',
                'type_label' => $typeLabel,
                'invoice_number' => $r['invoice_number'] ?: $r['return_number'],
                'reference' => 'sales_return#' . $r['id'],
                'status' => $r['status'] ?? null,
                'total_amount' => isset($r['total_amount']) ? (float)$r['total_amount'] : null,
                'net_total_amount' => null,
                'paid_amount' => null,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => isset($r['journal_entry_id']) && $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
                'transaction_subtype' => $subtype,
                'return_group_id' => (int)$r['id'], // Link to itself as group identifier
            ];
        }, ($stmtReturns->fetchAll(PDO::FETCH_ASSOC) ?: []));

        // 3) إيصالات القبض والصرف (payments) - differentiate between receipts and refunds
        $paymentBranchFilterSql = $branchId !== null
            ? ' AND (p.branch_id = :payment_branch_id OR s.branch_id = :sale_branch_id OR r.branch_id = :return_branch_id)'
            : '';
        $sqlReceipts = "
            SELECT 
                p.id,
                p.created_at AS date,
                p.reference_number,
                p.amount,
                p.journal_entry_id,
                p.sale_id,
                p.return_id,
                p.type AS source_payment_type,
                CASE 
                    WHEN p.return_id IS NOT NULL OR p.type IN ('return_payment', 'refund') THEN 'refund'
                    ELSE 'receipt'
                END AS payment_type,
                r.refund_amount,
                r.refund_method,
                s.invoice_number AS related_sale_invoice_number,
                (
                    SELECT GROUP_CONCAT(DISTINCT allocated_sale.invoice_number
                        ORDER BY allocated_sale.invoice_number SEPARATOR ', ')
                    FROM customer_payment_allocations cpa
                    JOIN sales allocated_sale
                      ON allocated_sale.id = cpa.sale_id
                     AND allocated_sale.tenant_id = cpa.tenant_id
                    WHERE cpa.payment_id = p.id
                      AND cpa.tenant_id = p.tenant_id
                ) AS allocated_invoice_numbers
            FROM payments p
            LEFT JOIN returns r ON p.return_id = r.id AND p.tenant_id = r.tenant_id
            LEFT JOIN sales s ON p.sale_id = s.id AND p.tenant_id = s.tenant_id
            WHERE p.tenant_id = :tenant_id 
              AND p.customer_id = :customer_id
              AND p.created_at >= :start_date AND p.created_at <= :end_date_full
              AND p.is_draft = 0
              AND p.status = 'completed'
              {$paymentBranchFilterSql}
        ";
        $stmtReceipts = $this->db->prepare($sqlReceipts);
        $receiptParams = [
            ':tenant_id' => $tenantId,
            ':customer_id' => $customerId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($branchId !== null) {
            $receiptParams[':payment_branch_id'] = $branchId;
            $receiptParams[':sale_branch_id'] = $branchId;
            $receiptParams[':return_branch_id'] = $branchId;
        }
        $stmtReceipts->execute($receiptParams);
        $receipts = array_map(function ($r) {
            $amount = isset($r['amount']) ? (float)$r['amount'] : 0.0;
            $paymentType = $r['payment_type'] ?? 'receipt';
            $sourcePaymentType = (string) ($r['source_payment_type'] ?? '');
            $referencePrefix = $paymentType === 'refund' ? 'refund' : 'receipt';

            if ($paymentType === 'refund' && $amount < 0.01) {
                return null;
            }

            // Distinguish a return with an actual cash/bank refund from a non-cash return.
            $transactionSubtype = null;
            if ($paymentType === 'refund') {
                $refundAmount = isset($r['refund_amount']) ? (float)$r['refund_amount'] : 0;
                $refundMethod = isset($r['refund_method']) ? $r['refund_method'] : null;

                if ($refundAmount >= 0.01) {
                    if ($refundMethod === 'cash') {
                        $transactionSubtype = 'sales_return_refund';
                    } elseif ($refundMethod === 'bank_transfer') {
                        $transactionSubtype = 'sales_return_bank_refund';
                    }
                }
            }

            $paymentTypeLabel = $paymentType === 'refund' ? 'سند صرف' : 'سند قبض';
            if ($paymentType === 'refund' && $transactionSubtype === 'sales_return_refund') {
                $paymentTypeLabel = 'استرجاع نقدي للعميل';
            }

            $invoiceNumber = $r['related_sale_invoice_number'] ?? null;
            $sourceLabel = $paymentType === 'refund'
                ? 'استرداد مرتجع'
                : match ($sourcePaymentType) {
                    'sale' => 'تحصيل مرتبط بفاتورة',
                    'customer', 'debt_payment' => 'تحصيل من حساب العميل',
                    'voucher' => 'سند قبض',
                    default => 'تحصيل',
                };

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'type' => $paymentType,
                'type_label' => $paymentTypeLabel,
                'source_label' => $sourceLabel,
                'payment_reference_number' => $r['reference_number'] ?? null,
                'invoice_number' => $invoiceNumber,
                'allocated_invoice_numbers' => $r['allocated_invoice_numbers'] ?? null,
                'reference' => $referencePrefix . '#' . $r['id'],
                'status' => null,
                'total_amount' => $amount,
                'net_total_amount' => $amount,
                'paid_amount' => $amount,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => isset($r['journal_entry_id']) && $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
                'transaction_subtype' => $transactionSubtype,
                'return_group_id' => $paymentType === 'refund' && $r['return_id'] ? (int)$r['return_id'] : null,
            ];
        }, ($stmtReceipts->fetchAll(PDO::FETCH_ASSOC) ?: []));

        $receipts = array_filter($receipts, fn ($item) => $item !== null);

        $all = array_merge($sales, $returns, $receipts);
        usort($all, function ($a, $b) {
            if ($a['date'] === $b['date']) {
                return 0;
            }
            return ($a['date'] < $b['date']) ? -1 : 1;
        });

        return [
            'count' => count($all),
            'items' => $all,
        ];
    }

    // مرجع موحد للمورد: فواتير مشتريات + مرتجعات مشتريات + مدفوعات المورد خلال الفترة
    private function getSupplierReferences($supplierId, $startDate, $endDate, $tenantId, ?int $branchId = null)
    {
        // Purchase returns are summed from returns; return-credit allocations are sales-only.
        $purchaseBranchFilterSql = $branchId !== null ? ' AND p.branch_id = :branch_id' : '';
        $sqlPurchases = "
            SELECT 
                p.id,
                p.invoice_date AS date,
                p.invoice_number,
                p.status,
                p.total_amount,
                p.tax_amount,
                p.discount_value,
                (
                    SELECT COALESCE(SUM(
                        CASE WHEN pay.type = 'payment_for_return' THEN -pay.amount ELSE pay.amount END
                    ), 0)
                    FROM payments pay
                    WHERE pay.purchase_id = p.id
                      AND pay.tenant_id = p.tenant_id
                      AND pay.is_draft = 0
                      AND pay.status = 'completed'
                ) AS actual_paid_amount,
                p.journal_entry_id,
                (
                    SELECT COALESCE(SUM(r.grand_total), 0)
                    FROM returns r 
                    WHERE r.return_type = 'purchase' 
                      AND r.purchase_id = p.id 
                      AND r.tenant_id = p.tenant_id
                ) AS returned_amount
            FROM purchases p
            WHERE p.tenant_id = :tenant_id
              AND p.supplier_id = :supplier_id
              AND p.invoice_date >= :start_date AND p.invoice_date <= :end_date_full
              {$purchaseBranchFilterSql}
        ";
        $stmtPurchases = $this->db->prepare($sqlPurchases);
        $purchaseParams = [
            ':tenant_id' => $tenantId,
            ':supplier_id' => $supplierId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($branchId !== null) {
            $purchaseParams[':branch_id'] = $branchId;
        }
        $stmtPurchases->execute($purchaseParams);
        $purchases = array_map(function ($r) {
            $totalAmount = (float) ($r['total_amount'] ?? 0);
            $tax = isset($r['tax_amount']) ? (float)$r['tax_amount'] : 0.0;
            $net = max(0.0, round($totalAmount - $tax, 2));
            $paid = isset($r['actual_paid_amount']) ? (float)$r['actual_paid_amount'] : 0.0;
            $returnedAmount = isset($r['returned_amount']) ? (float)$r['returned_amount'] : 0.0;
            $hasReturns = $returnedAmount > 0.009;
            $grandTotal = $totalAmount;

            $returnCredits = 0.0;

            $normalizedStatus = $this->normalizeInvoiceStatus(
                $r['status'],
                $paid,
                $returnCredits,
                $grandTotal,
                $hasReturns,
                $returnedAmount
            );

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'type' => 'purchase',
                'invoice_number' => $r['invoice_number'],
                'reference' => 'purchase#' . $r['id'],
                'status' => $normalizedStatus,
                'total_amount' => $totalAmount,
                'net_total_amount' => $net,
                'tax_amount' => $tax,
                'paid_amount' => $paid,
                'outstanding' => max(0.0, round($grandTotal - $paid - $returnedAmount, 2)),
                'returned_amount' => $returnedAmount,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => isset($r['journal_entry_id']) && $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
            ];
        }, ($stmtPurchases->fetchAll(PDO::FETCH_ASSOC) ?: []));

        // 2) مرتجعات المشتريات
        $purchaseReturnBranchFilterSql = $branchId !== null ? ' AND r.branch_id = :branch_id' : '';
        $sqlPurchaseReturns = "
            SELECT 
                r.id,
                r.created_at AS date,
                r.return_number,
                r.invoice_number,
                r.status,
                r.grand_total AS total_amount,
                r.journal_entry_id,
                r.refund_amount,
                r.refund_method
            FROM returns r
            WHERE r.tenant_id = :tenant_id 
              AND r.return_type = 'purchase'
              AND r.supplier_id = :supplier_id
              AND r.created_at >= :start_date AND r.created_at <= :end_date_full
              {$purchaseReturnBranchFilterSql}
        ";
        $stmtPR = $this->db->prepare($sqlPurchaseReturns);
        $purchaseReturnParams = [
            ':tenant_id' => $tenantId,
            ':supplier_id' => $supplierId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($branchId !== null) {
            $purchaseReturnParams[':branch_id'] = $branchId;
        }
        $stmtPR->execute($purchaseReturnParams);
        $purchaseReturns = array_map(function ($r) {
            // Distinguish a return with an actual cash or bank refund.
            $subtype = 'purchase_return_only';

            $refundAmount = isset($r['refund_amount']) ? (float)$r['refund_amount'] : 0;
            $refundMethod = isset($r['refund_method']) ? $r['refund_method'] : null;

            if ($refundAmount >= 0.01) {
                if ($refundMethod === 'cash') {
                    $subtype = 'purchase_return_refund';
                } elseif ($refundMethod === 'bank_transfer') {
                    $subtype = 'purchase_return_bank_refund';
                }
            }

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'type' => 'purchase_return',
                'invoice_number' => $r['invoice_number'] ?: $r['return_number'],
                'reference' => 'purchase_return#' . $r['id'],
                'status' => $r['status'] ?? null,
                'total_amount' => isset($r['total_amount']) ? (float)$r['total_amount'] : null,
                'net_total_amount' => null,
                'paid_amount' => null,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => isset($r['journal_entry_id']) && $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
                'transaction_subtype' => $subtype,
                'return_group_id' => (int)$r['id'],
            ];
        }, ($stmtPR->fetchAll(PDO::FETCH_ASSOC) ?: []));

        // 3) مدفوعات المورد: استخدم supplier_id مباشرة من جدول payments - differentiate between payments and refunds
        $supplierPaymentBranchFilterSql = $branchId !== null
            ? ' AND (pay.branch_id = :payment_branch_id OR EXISTS (SELECT 1 FROM purchases bp WHERE bp.id = pay.purchase_id AND bp.tenant_id = pay.tenant_id AND bp.branch_id = :purchase_branch_id) OR EXISTS (SELECT 1 FROM returns br WHERE br.id = pay.return_id AND br.tenant_id = pay.tenant_id AND br.branch_id = :return_branch_id))'
            : '';
        $sqlSupplierPayments = "
            SELECT 
                pay.id,
                pay.created_at AS date,
                pay.reference_number,
                pay.amount,
                pay.journal_entry_id,
                pay.purchase_id,
                pay.return_id,
                CASE 
                    WHEN pay.return_id IS NOT NULL THEN 'refund'
                    WHEN pay.purchase_id IS NOT NULL THEN 'payment'
                    ELSE 'payment'
                END AS payment_type
            FROM payments pay
            WHERE pay.tenant_id = :tenant_id
              AND pay.supplier_id = :supplier_id
              AND pay.created_at >= :start_date AND pay.created_at <= :end_date_full
              AND pay.is_draft = 0
              AND pay.status = 'completed'
              {$supplierPaymentBranchFilterSql}
        ";
        $stmtSPay = $this->db->prepare($sqlSupplierPayments);
        $supplierPaymentParams = [
            ':tenant_id' => $tenantId,
            ':supplier_id' => $supplierId,
            ':start_date' => $startDate,
            ':end_date_full' => $endDate . ' 23:59:59',
        ];
        if ($branchId !== null) {
            $supplierPaymentParams[':payment_branch_id'] = $branchId;
            $supplierPaymentParams[':purchase_branch_id'] = $branchId;
            $supplierPaymentParams[':return_branch_id'] = $branchId;
        }
        $stmtSPay->execute($supplierPaymentParams);
        $supplierPayments = array_map(function ($r) {
            $amount = isset($r['amount']) ? (float)$r['amount'] : 0.0;
            $paymentType = $r['payment_type'] ?? 'payment';
            $referencePrefix = $paymentType === 'refund' ? 'refund' : 'payment';

            if ($paymentType === 'refund' && $amount < 0.01) {
                return null;
            }

            return [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'type' => $paymentType,
                'invoice_number' => $r['reference_number'],
                'reference' => $referencePrefix . '#' . $r['id'],
                'status' => null,
                'total_amount' => $amount,
                'net_total_amount' => $amount,
                'paid_amount' => $amount,
                'has_journal' => !empty($r['journal_entry_id']),
                'journal_entry_id' => isset($r['journal_entry_id']) && $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null,
                'transaction_subtype' => $paymentType === 'refund' ? 'purchase_return_refund' : null,
                'return_group_id' => $paymentType === 'refund' && $r['return_id'] ? (int)$r['return_id'] : null,
            ];
        }, ($stmtSPay->fetchAll(PDO::FETCH_ASSOC) ?: []));

        $supplierPayments = array_filter($supplierPayments, fn ($item) => $item !== null);

        $all = array_merge($purchases, $purchaseReturns, $supplierPayments);
        usort($all, function ($a, $b) {
            if ($a['date'] === $b['date']) {
                return 0;
            }
            return ($a['date'] < $b['date']) ? -1 : 1;
        });

        return [
            'count' => count($all),
            'items' => $all,
        ];
    }

    /** Return active accounts belonging to the current tenant. */
    public function getAccounts(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $stmt = $this->db->prepare("
                    SELECT 
                        id, 
                        name, 
                        code,
                        SUBSTRING(code, 1, 2) AS account_group_code
                    FROM 
                        accounts 
                    WHERE 
                        tenant_id = :tenant_id AND is_active = 1 
                    ORDER BY 
                        code ASC
                ");

            $stmt->execute(['tenant_id' => $tenantId]);
            $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $this->successResponse($response, $accounts);
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching accounts', ['error' => $e->getMessage(), 'tenant_id' => $tenantId ?? null]);
            return $this->errorResponse($response, 'فشل في جلب الحسابات', 500);
        }
    }

    private function parseTypes($csv)
    {
        if (is_array($csv)) {
            return array_values(array_filter(array_map('trim', $csv)));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$csv))));
    }

    private function resolveAccountIdByCode($code, $tenantId)
    {
        $cacheKey = $tenantId . '_' . $code;
        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        // Delegates to AccountingService for consistent resolution (tenant-specific → global fallback)
        $id = $this->accounting->getAccountByCode((int) $tenantId, (string) $code);

        $this->accountCache[$cacheKey] = $id;
        return $id;
    }

    private function fillDailyGaps(array $dailyBalances, $startDate, $endDate)
    {
        $byDate = [];
        foreach ($dailyBalances as $row) {
            if (isset($row['date'])) {
                $byDate[$row['date']] = $row;
            }
        }

        $result = [];
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        $initialOpening = 0.0;
        if (!empty($dailyBalances)) {
            $initialOpening = isset($dailyBalances[0]['opening_balance']) ? (float)$dailyBalances[0]['opening_balance'] : 0.0;
        }

        while ($current <= $end) {
            $d = $current->format('Y-m-d');
            if (isset($byDate[$d])) {
                $result[] = $byDate[$d];
            } else {
                $prev = end($result);
                $opening = $prev ? (float)$prev['closing_balance'] : $initialOpening;
                $result[] = [
                    'date' => $d,
                    'opening_balance' => $opening,
                    'closing_balance' => $opening,
                    'transaction_count' => 0,
                    'day_debit' => 0.0,
                    'day_credit' => 0.0,
                ];
            }
            $current->modify('+1 day');
        }
        return $result;
    }

    private function referenceLabel(?string $type, string $locale = 'ar'): string
    {
        return LabelService::refLabel($type, $locale);
    }

    private function statusLabel(?string $code, string $locale = 'ar'): string
    {
        return LabelService::statusLabel($code, $locale);
    }

    /** Return tenant-owned and global accounts in separate collections. */
    public function getGroupedAccounts(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID)', 403);
            }

            $tenantStmt = $this->db->prepare(
                "SELECT id, code, name, type FROM accounts 
                 WHERE tenant_id = ? ORDER BY COALESCE(code, name) ASC"
            );
            $tenantStmt->execute([$tenantId]);
            $tenantAccounts = $tenantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $globalStmt = $this->db->prepare(
                "SELECT id, code, name, type FROM accounts 
                 WHERE tenant_id IS NULL ORDER BY COALESCE(code, name) ASC"
            );
            $globalStmt->execute();
            $globalAccounts = $globalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $data = [
                'tenant_accounts' => $tenantAccounts,
                'global_accounts' => $globalAccounts,
            ];
            return $this->successResponse($response, $data);
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching grouped accounts', ['error' => $e->getMessage(), 'tenant_id' => $tenantId ?? null]);
            return $this->errorResponse($response, 'فشل في جلب الحسابات المجمعة', 500);
        }
    }
}
