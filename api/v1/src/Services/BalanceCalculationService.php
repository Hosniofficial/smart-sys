<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Exception;
use App\Services\MonologHandler;
use DateTime;

/**
 * Calculates ledger, transaction-history, and document balances for tenant-scoped records.
 */
class BalanceCalculationService
{
    private $pdo;
    private $logger;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->logger = MonologHandler::getInstance('balance_calculation');
    }

    /**
     * Get account balance using journal entry lines for a specific account
     *
     * Used by: CustomersHandler::getCustomers(), SuppliersHandler::getSuppliers(), CashVouchersHandler
     *
     * @param int $accountId - Account ID
     * @param int $tenantId - Tenant ID
     * @param string $type - 'customer' (debit-credit) or 'supplier' (credit-debit)
     * @param DateTime|null $asOf - Optional: Calculate balance as of specific accounting date
     * @return float - Account balance
     */
    public function getAccountBalanceFromJournalEntries(
        int $accountId,
        int $tenantId,
        string $type = 'customer',
        ?DateTime $asOf = null
    ): float {
        try {
            // Determine calculation direction based on type
            if ($type === 'supplier') {
                // For suppliers: credit_amount - debit_amount
                $calculation = 'SUM(jel.credit_amount - jel.debit_amount)';
            } else {
                // For customers: debit_amount - credit_amount
                $calculation = 'SUM(jel.debit_amount - jel.credit_amount)';
            }

            // Build date filter if provided (uses accounting entry_date)
            $dateFilter = '';
            $params = [$accountId, $tenantId];

            if ($asOf && $asOf instanceof DateTime) {
                // Balance as of date: all entries BEFORE the next day at midnight
                $nextDay = (clone $asOf)->modify('+1 day')->format('Y-m-d');
                $dateFilter = ' AND je.entry_date < ?';
                $params[] = $nextDay . ' 00:00:00';
            }

            $sql = "
                SELECT COALESCE({$calculation}, 0) AS balance
                FROM journal_entry_lines jel
                JOIN journal_entries je ON jel.journal_entry_id = je.id
                WHERE jel.account_id = ?
                  AND je.tenant_id = ?
                  AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))
                  {$dateFilter}
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $balance = (float) $stmt->fetchColumn();

            return $balance;

        } catch (PDOException $e) {
            $this->logger->error('Failed to calculate account balance from journal entries', [
                'account_id' => $accountId,
                'tenant_id' => $tenantId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Get account balance from accounts table (stored debit_balance and credit_balance fields)
     *
     * Used by: ReturnsHandler::create() - for faster lookup from normalized accounts table
     *
     * @param int $accountId - Account ID
     * @param int $tenantId - Tenant ID
     * @param string $type - 'customer' (debit-credit) or 'supplier' (credit-debit)
     * @return float - Account balance from stored fields
     */
    public function getAccountBalanceFromTable(
        int $accountId,
        int $tenantId,
        string $type = 'customer'
    ): float {
        try {
            // Determine calculation direction based on type
            if ($type === 'supplier') {
                // For suppliers: credit_balance - debit_balance
                $calculation = 'COALESCE(credit_balance, 0) - COALESCE(debit_balance, 0)';
            } else {
                // For customers: debit_balance - credit_balance
                $calculation = 'COALESCE(debit_balance, 0) - COALESCE(credit_balance, 0)';
            }

            $sql = "
                SELECT {$calculation} AS balance
                FROM accounts
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$accountId, $tenantId]);
            $balance = (float) $stmt->fetchColumn();

            return $balance;

        } catch (PDOException $e) {
            $this->logger->error('Failed to calculate account balance from table', [
                'account_id' => $accountId,
                'tenant_id' => $tenantId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Get running balance for transaction history (iterative calculation)
     *
     * Used by: CustomersHandler::getTransactions(), SuppliersHandler::getTransactions()
     * Calculates cumulative balance for each transaction in chronological order
     *
     * @param int $accountId - Account ID
     * @param int $tenantId - Tenant ID
     * @param string $type - 'customer' (debit-credit) or 'supplier' (credit-debit)
     * @return array - Array of [transaction_data, running_balance]
     */
    public function getTransactionHistoryWithRunningBalance(
        int $accountId,
        int $tenantId,
        string $type = 'customer'
    ): array {
        try {
            // Get all transactions for account in chronological order
            $sql = "
                SELECT jel.id, jel.journal_entry_id, jel.debit_amount, jel.credit_amount, 
                       jel.created_at, je.reference_type, je.reference_id, je.description
                FROM journal_entry_lines jel
                JOIN journal_entries je ON jel.journal_entry_id = je.id
                WHERE jel.account_id = ? AND jel.tenant_id = ?
                  AND je.tenant_id = ?
                  AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))
                ORDER BY jel.created_at ASC, jel.id ASC
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$accountId, $tenantId, $tenantId]);
            $allTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Calculate running balance
            $currentBalance = 0.0;
            $transactionsWithBalance = [];

            foreach ($allTransactions as $transaction) {
                // Calculate balance change based on type
                if ($type === 'supplier') {
                    $currentBalance += ((float)$transaction['credit_amount'] - (float)$transaction['debit_amount']);
                } else {
                    $currentBalance += ((float)$transaction['debit_amount'] - (float)$transaction['credit_amount']);
                }

                $transaction['running_balance'] = $currentBalance;
                $transactionsWithBalance[] = $transaction;
            }

            return $transactionsWithBalance;

        } catch (PDOException $e) {
            $this->logger->error('Failed to get transaction history with running balance', [
                'account_id' => $accountId,
                'tenant_id' => $tenantId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Build the net completed-payment expression for a sales row, including allocated receipts and refunds.
     */
    private function salePaidAmountSubquery(string $tableAlias): string
    {
        return "((SELECT COALESCE(SUM(
                     CASE WHEN pm.type = 'return_payment' THEN -pm.amount ELSE pm.amount END
                 ), 0)
                 FROM payments pm
                 WHERE pm.sale_id = {$tableAlias}.id
                   AND pm.tenant_id = {$tableAlias}.tenant_id
                   AND pm.is_draft = 0
                   AND pm.status = 'completed')
                 + (SELECT COALESCE(SUM(cpa.allocated_amount), 0)
                    FROM customer_payment_allocations cpa
                    JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                    WHERE cpa.sale_id = {$tableAlias}.id
                      AND cpa.tenant_id = {$tableAlias}.tenant_id
                      AND ap.is_draft = 0
                      AND ap.status = 'completed'))";
    }

    /**
     * Calculate one document's outstanding amount after payments and applicable credits.
     */
    public function getAmountDueBalance(
        string $documentType,
        int $documentId,
        int $tenantId
    ): float {
        try {
            $documentType = strtolower($documentType);

            $mapping = [
                'purchase' => [
                    'table' => 'purchases',
                    'totalCol' => 'total_amount',
                    // ── AUDIT FIX (ownership review, CRITICAL) ──────────────────────
                    // كان taxCol='tax_amount' هنا يُضيف الضريبة مرة ثانية فوق
                    // total_amount — تحققتُ من كود الإنشاء الفعلي
                    // (PurchasesHandler::create, calculatePurchaseTotals):
                    // purchases.total_amount = net_total (وnet_total بالفعل =
                    // totalAfterDiscount + taxAmount، أي شامل الضريبة أصلاً).
                    // بعكس sales.net_total_amount (تُخزَّن *قبل* الضريبة عمدًا).
                    // النتيجة قبل هذا الإصلاح: amount_due لكل فاتورة شراء كان
                    // يُحتسَب أكبر من الحقيقي بمقدار ضريبتها بالضبط — قد يُنتج
                    // رفض ائتمان مورد سدَّد بالكامل فعليًا، أو تقارير "مستحق
                    // للموردين" مبالغ فيها بشكل منهجي عبر كل فاتورة شراء تقريبًا.
                    'taxCol' => null,
                    'paidExpr' => "(SELECT COALESCE(SUM(
                        CASE WHEN pm.type = 'payment_for_return' THEN -pm.amount ELSE pm.amount END
                    ), 0) FROM payments pm
                    WHERE pm.purchase_id = t.id AND pm.tenant_id = t.tenant_id
                      AND pm.is_draft = 0 AND pm.status = 'completed')",
                    'creditsSubquery' => "(SELECT COALESCE(SUM(r.grand_total), 0) FROM returns r
                        WHERE r.purchase_id = t.id AND r.tenant_id = t.tenant_id
                          AND r.return_type = 'purchase')"
                ],
                'sale' => [
                    'table' => 'sales',
                    'totalCol' => 'net_total_amount',
                    'taxCol' => 'tax_amount',
                    'paidExpr' => $this->salePaidAmountSubquery('t'),
                    'creditsSubquery' => '(SELECT COALESCE(SUM(rca.allocated_amount), 0) FROM return_credit_allocations rca WHERE rca.sale_id = t.id AND rca.tenant_id = t.tenant_id)'
                ],
                'sales_return' => [
                    'table' => 'returns',
                    'totalCol' => 'grand_total',
                    'taxCol' => null,
                    'paidCol' => 'paid_amount',
                    'returnTypeFilter' => 'sale'
                ],
                'purchase_return' => [
                    'table' => 'returns',
                    'totalCol' => 'grand_total',
                    'taxCol' => null,
                    'paidCol' => 'paid_amount',
                    'returnTypeFilter' => 'purchase'
                ]
            ];

            if (!isset($mapping[$documentType])) {
                $this->logger->warning('Unknown document type for amount due calculation', [
                    'document_type' => $documentType
                ]);
                return 0.0;
            }

            $config = $mapping[$documentType];
            $table = $config['table'];
            $totalExpr = $config['taxCol']
                ? "(COALESCE({$config['totalCol']}, 0) + COALESCE({$config['taxCol']}, 0))"
                : "COALESCE({$config['totalCol']}, 0)";
            // بعض الأنواع (sale) لها تعبير خاص لحساب المدفوع بدل عمود مباشر — راجع
            // salePaidAmountSubquery أعلاه.
            $paidExpr = !empty($config['paidExpr'])
                ? "COALESCE({$config['paidExpr']}, 0)"
                : "COALESCE({$config['paidCol']}, 0)";
            $creditsExpr = !empty($config['creditsSubquery']) ? " - {$config['creditsSubquery']}" : '';

            $sql = "
                SELECT {$totalExpr} - {$paidExpr}{$creditsExpr} AS amount_due
                FROM {$table} t
                WHERE t.id = ? AND t.tenant_id = ?";

            // Add return_type filter for returns table
            if (!empty($config['returnTypeFilter'])) {
                $sql .= " AND t.return_type = ?";
                $params = [$documentId, $tenantId, $config['returnTypeFilter']];
            } else {
                $params = [$documentId, $tenantId];
            }

            $sql .= " LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $amountDue = (float) $stmt->fetchColumn();

            return max(0.0, $amountDue); // Never negative

        } catch (PDOException $e) {
            $this->logger->error('Failed to calculate amount due balance', [
                'document_type' => $documentType,
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Get aggregated balance due for multiple documents
     *
     * Used by: SummaryHandler, ReportingHandler - for balance summaries
     * Sums up outstanding amounts across multiple documents with filtering
     *
     * @param string $documentType - 'purchase', 'sale', etc.
     * @param int $tenantId - Tenant ID
     * @param array $filters - Optional filters ['supplier_id'=>X, 'status'=>'unpaid', 'date_from'=>'2026-01-01']
     * @return float - Total amount due across filtered documents
     */
    public function getAggregatedAmountDue(
        string $documentType,
        int $tenantId,
        array $filters = []
    ): float {
        try {
            $documentType = strtolower($documentType);

            // Map document types — نفس التصحيح: tax_amount ضمن الإجمالي، وخصم
            // return_credit_allocations من إجمالي مستحقات المبيعات.
            $mapping = [
                'purchase' => [
                    'table' => 'purchases',
                    'totalCol' => 'total_amount',
                    // ── AUDIT FIX (ownership review): same double-tax-count bug as
                    // getAmountDueBalance() above — see that fix's comment for the
                    // full evidence trail (purchases.total_amount is already
                    // tax-inclusive at creation time).
                    'taxCol' => null,
                    'paidExpr' => "(SELECT COALESCE(SUM(
                        CASE WHEN pm.type = 'payment_for_return' THEN -pm.amount ELSE pm.amount END
                    ), 0) FROM payments pm
                    WHERE pm.purchase_id = t.id AND pm.tenant_id = t.tenant_id
                      AND pm.is_draft = 0 AND pm.status = 'completed')",
                    'creditsSubquery' => "(SELECT COALESCE(SUM(r.grand_total), 0) FROM returns r
                        WHERE r.purchase_id = t.id AND r.tenant_id = t.tenant_id
                          AND r.return_type = 'purchase')",
                    'partyCol' => 'supplier_id'
                ],
                'sale' => [
                    'table' => 'sales',
                    'totalCol' => 'net_total_amount',
                    'taxCol' => 'tax_amount',
                    'paidCol' => 'paid_amount',
                    'partyCol' => 'customer_id',
                    'creditsSubquery' => '(SELECT COALESCE(SUM(rca.allocated_amount), 0) FROM return_credit_allocations rca WHERE rca.sale_id = t.id AND rca.tenant_id = t.tenant_id)'
                ]
            ];

            if (!isset($mapping[$documentType])) {
                return 0.0;
            }

            $config = $mapping[$documentType];
            $table = $config['table'];
            $totalExpr = !empty($config['taxCol'])
                ? "(COALESCE(t.{$config['totalCol']}, 0) + COALESCE(t.{$config['taxCol']}, 0))"
                : "COALESCE(t.{$config['totalCol']}, 0)";
            $paidExpr = !empty($config['paidExpr'])
                ? "COALESCE({$config['paidExpr']}, 0)"
                : "COALESCE(t.{$config['paidCol']}, 0)";
            $creditsExpr = !empty($config['creditsSubquery']) ? " - {$config['creditsSubquery']}" : '';

            $sql = "
                SELECT COALESCE(SUM(GREATEST({$totalExpr} - {$paidExpr}{$creditsExpr}, 0)), 0) AS total_due
                FROM {$table} t
                WHERE t.tenant_id = ?
            ";

            $params = [$tenantId];

            // Add optional filters
            if (!empty($filters['status'])) {
                $sql .= " AND t.status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['party_id'])) {
                $partyCol = $config['partyCol'];
                $sql .= " AND t.{$partyCol} = ?";
                $params[] = $filters['party_id'];
            }

            if (!empty($filters['date_from'])) {
                $sql .= " AND t.created_at >= ?";
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $sql .= " AND t.created_at <= ?";
                $params[] = $filters['date_to'];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $totalDue = (float) $stmt->fetchColumn();

            return max(0.0, $totalDue);

        } catch (PDOException $e) {
            $this->logger->error('Failed to calculate aggregated amount due', [
                'document_type' => $documentType,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Unified balance getter - auto-detects best method based on context
     *
     * Convenience method that intelligently selects the best calculation method
     * 1. For parties with account_id → uses journal entry method (most accurate)
     * 2. Falls back to accounts table if needed (when journal entries not available)
     *
     * @param int $accountId - Account ID
     * @param int $tenantId - Tenant ID
     * @param string $type - 'customer', 'supplier', or document type
     * @return float - Best-effort balance calculation
     */
    public function getBalance(
        int $accountId,
        int $tenantId,
        string $type = 'customer'
    ): float {
        try {
            // Try journal entry method first (most accurate)
            $balance = $this->getAccountBalanceFromJournalEntries($accountId, $tenantId, $type);

            // If journal entries are available, return that
            if ($balance !== 0.0) {
                return $balance;
            }

            // Fallback to accounts table method
            return $this->getAccountBalanceFromTable($accountId, $tenantId, $type);

        } catch (Exception $e) {
            $this->logger->error('Error in unified balance getter', [
                'account_id' => $accountId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Get amount due for batch of documents (optimized for list views)
     *
     * Used by: List views, Summary reports that need multiple document balances
     * Returns array of [document_id => amount_due]
     *
     * @param string $documentType - 'purchase', 'sale', 'sales_return', 'purchase_return'
     * @param int $tenantId - Tenant ID
     * @param array $documentIds - Array of document IDs to fetch amounts for
     * @return array - Associative array [doc_id => amount_due]
     */
    public function getAmountDueBatch(
        string $documentType,
        int $tenantId,
        array $documentIds
    ): array {
        try {
            if (empty($documentIds)) {
                return [];
            }

            $documentType = strtolower($documentType);

            // Map document types — نفس التصحيح المُطبَّق في getAmountDueBalance:
            // إضافة tax_amount للإجمالي، وطرح return_credit_allocations للمبيعات.
            $mapping = [
                'purchase' => [
                    'table' => 'purchases',
                    'idCol' => 'id',
                    'totalCol' => 'total_amount',
                    // ── AUDIT FIX (ownership review): same double-tax-count bug as
                    // getAmountDueBalance() — see that fix's comment for the
                    // full evidence trail.
                    'taxCol' => null,
                    'paidCol' => 'paid_amount'
                ],
                'sale' => [
                    'table' => 'sales',
                    'idCol' => 'id',
                    'totalCol' => 'net_total_amount',
                    'taxCol' => 'tax_amount',
                    'paidExpr' => $this->salePaidAmountSubquery('t'),
                    'creditsSubquery' => '(SELECT COALESCE(SUM(rca.allocated_amount), 0) FROM return_credit_allocations rca WHERE rca.sale_id = t.id AND rca.tenant_id = t.tenant_id)'
                ],
                'sales_return' => [
                    'table' => 'returns',
                    'idCol' => 'id',
                    'totalCol' => 'grand_total',
                    'taxCol' => null,
                    'paidCol' => 'paid_amount',
                    'returnTypeFilter' => 'sale'
                ],
                'purchase_return' => [
                    'table' => 'returns',
                    'idCol' => 'id',
                    'totalCol' => 'grand_total',
                    'taxCol' => null,
                    'paidCol' => 'paid_amount',
                    'returnTypeFilter' => 'purchase'
                ]
            ];

            if (!isset($mapping[$documentType])) {
                return [];
            }

            $config = $mapping[$documentType];
            $table = $config['table'];
            $idCol = $config['idCol'];
            $totalExpr = $config['taxCol']
                ? "(COALESCE(t.{$config['totalCol']}, 0) + COALESCE(t.{$config['taxCol']}, 0))"
                : "COALESCE(t.{$config['totalCol']}, 0)";
            $paidExpr = !empty($config['paidExpr'])
                ? "COALESCE({$config['paidExpr']}, 0)"
                : "COALESCE(t.{$config['paidCol']}, 0)";
            $creditsExpr = !empty($config['creditsSubquery']) ? " - {$config['creditsSubquery']}" : '';

            // Build placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($documentIds), '?'));

            $sql = "
                SELECT t.{$idCol} AS {$idCol}, {$totalExpr} - {$paidExpr}{$creditsExpr} AS amount_due
                FROM {$table} t
                WHERE t.{$idCol} IN ({$placeholders}) AND t.tenant_id = ?";

            // Add return_type filter for returns table
            if (!empty($config['returnTypeFilter'])) {
                $sql .= " AND t.return_type = ?";
                $params = array_merge($documentIds, [$tenantId, $config['returnTypeFilter']]);
            } else {
                $params = array_merge($documentIds, [$tenantId]);
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[$row[$idCol]] = max(0.0, (float)$row['amount_due']);
            }

            return $results;

        } catch (PDOException $e) {
            $this->logger->error('Failed to get batch amount due', [
                'document_type' => $documentType,
                'tenant_id' => $tenantId,
                'count' => count($documentIds),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get all amounts due by customer across all unpaid documents
     *
     * Aggregates outstanding amounts for all sales/returns
     * Used by: Customer balance summaries, Risk assessment
     *
     * @param int $customerId - Customer ID
     * @param int $tenantId - Tenant ID
     * @param bool $includeReturns - Include sales returns in calculation
     * @return float - Total amount due from customer
     */
    public function getAllCustomerAmountsDue(
        int $customerId,
        int $tenantId,
        bool $includeReturns = true
    ): float {
        try {
            // ⚠️ إصلاح: net_total_amount لا يشمل الضريبة (عمود tax_amount منفصل) — كان هذا
            // يُقلِّل المبلغ المستحق الفعلي بقيمة الضريبة في كل عملية. كما أُضيف خصم
            // return_credit_allocations المرتبطة بكل فاتورة، تماماً كالصيغة المُثبَتة صحتها
            // في SalesHandler::addPayment.
            $sql = "
                SELECT COALESCE(SUM(
                    (s.net_total_amount + COALESCE(s.tax_amount, 0))
                    - COALESCE(s.paid_amount, 0)
                    - (SELECT COALESCE(SUM(rca.allocated_amount), 0)
                       FROM return_credit_allocations rca
                       WHERE rca.sale_id = s.id AND rca.tenant_id = s.tenant_id)
                ), 0) AS total_due
                FROM sales s
                WHERE s.customer_id = ? AND s.tenant_id = ?
            ";

            $params = [$customerId, $tenantId];

            // Get amounts from sales
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $due = (float)$stmt->fetchColumn();

            // ⚠️ إصلاح خطأ في الإشارة (sign error): القيمة غير المُرجَعة نقداً من مرتجع
            // مبيعات هي مبلغ *مستحق للعميل على الشركة* (التزام على الشركة)، وليست مبلغاً
            // إضافياً يدين به العميل — طبقاً للقاعدة الموثّقة صراحةً في
            // ReturnService::allocateCustomerBalance (# CRITICAL ACCOUNTING RULE):
            //   outstanding = grand_total - paid_amount - return_credits
            // الكود الأصلي هنا كان يجمعها (+=) على دين العميل بدل طرحها، وهو ما يُبالغ في
            // تقدير المديونية بضعف قيمة أي مرتجع لم يُردّ نقداً. كما استُبعد الجزء الذي
            // سبق توزيعه فعلاً عبر return_credit_allocations (بحسب return_id) لتفادي
            // احتسابه مرتين — مرة هنا كـ"التزام غير مُسوّى"، ومرة أخرى كخصم من دين الفاتورة
            // التي طُبِّق عليها في الاستعلام أعلاه.
            if ($includeReturns) {
                $sqlReturns = "
                    SELECT COALESCE(SUM(
                        GREATEST(0, COALESCE(r.grand_total, 0) - COALESCE(r.paid_amount, 0)
                            - (SELECT COALESCE(SUM(rca.allocated_amount), 0)
                               FROM return_credit_allocations rca
                               WHERE rca.return_id = r.id AND rca.tenant_id = r.tenant_id))
                    ), 0) AS unrefunded_credit
                    FROM returns r
                    WHERE r.customer_id = ? AND r.tenant_id = ? AND r.return_type = 'sale'
                ";
                $stmtReturns = $this->pdo->prepare($sqlReturns);
                $stmtReturns->execute($params);
                $unrefundedCredit = (float)$stmtReturns->fetchColumn();
                $due -= $unrefundedCredit;
            }

            return max(0.0, $due);

        } catch (PDOException $e) {
            $this->logger->error('Failed to get customer amount due', [
                'customer_id' => $customerId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Get all amounts due by supplier across all unpaid documents
     *
     * Aggregates outstanding payable amounts for all purchases/returns
     * Used by: Supplier balance summaries, Payable tracking
     *
     * @param int $supplierId - Supplier ID
     * @param int $tenantId - Tenant ID
     * @param bool $includeReturns - Include purchase returns in calculation
     * @return float - Total amount due to supplier
     */
    public function getAllSupplierAmountsDue(
        int $supplierId,
        int $tenantId,
        bool $includeReturns = true
    ): float {
        try {
            // Purchase creation stores a tax-inclusive total_amount; this aggregate also adds tax_amount.
            $sql = "
                SELECT COALESCE(SUM(
                    (COALESCE(total_amount, 0) + COALESCE(tax_amount, 0)) - COALESCE(paid_amount, 0)
                ), 0) AS total_due
                FROM purchases
                WHERE supplier_id = ? AND tenant_id = ?
            ";

            $params = [$supplierId, $tenantId];

            // Get amounts from purchases
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $due = (float)$stmt->fetchColumn();

            // ⚠️ إصلاح نفس خطأ الإشارة الموجود في getAllCustomerAmountsDue: القيمة غير
            // المُستردة من مرتجع مشتريات هي مبلغ *مستحق للشركة على المورد* (المورد هو من
            // يدين، وليس العكس) — طبقاً لمنطق $refundModePurchase في ReturnService (رد نقدي
            // من المورد = paid_amount، وإلا = التزام معلّق للمورد اتجاهنا). لذا تُطرح من
            // مديونية المشتريات بدل أن تُضاف إليها.
            if ($includeReturns) {
                $sqlReturns = "
                    SELECT COALESCE(SUM(
                        GREATEST(0, COALESCE(grand_total, 0) - COALESCE(paid_amount, 0))
                    ), 0) AS unrefunded_credit
                    FROM returns
                    WHERE supplier_id = ? AND tenant_id = ? AND return_type = 'purchase'
                ";
                $stmtReturns = $this->pdo->prepare($sqlReturns);
                $stmtReturns->execute($params);
                $due -= (float)$stmtReturns->fetchColumn();
            }

            return max(0.0, $due);

        } catch (PDOException $e) {
            $this->logger->error('Failed to get supplier amount due', [
                'supplier_id' => $supplierId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }
}
