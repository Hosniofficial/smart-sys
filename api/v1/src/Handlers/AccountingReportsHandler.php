<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Throwable;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Builds tenant-scoped financial and receivables reports from journal, sales, and inventory data.
 *
 * Provides trial balance, ledger, income statement, balance sheet, receivables aging,
 * cash-flow categorization, and inventory NRV reports; posting operations delegate
 * journal creation to AccountingService.
 */
class AccountingReportsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('accounting_reports');
    }

    /** Return debit, credit, and net balances by account for the selected date and branch scope. */
    public function trialBalance(Request $request, Response $response): Response
    {
        $tenantId = null;

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            try {
                $branchId = $this->resolveBranchId($request, (int) $tenantId);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }
            $branchFilter = $this->journalEntryBranchFilter($branchId);

            [$startDateTime, $endExclusive] = $this->resolveDateRange($request, (int) $tenantId);

            $sql = "
                SELECT
                    a.id AS account_id,
                    a.code,
                    a.name,
                    a.type,
                    COALESCE(SUM(
                        CASE
                            WHEN je.id IS NOT NULL THEN COALESCE(jel.debit_amount, 0)
                            ELSE 0
                        END
                    ), 0) AS total_debit,
                    COALESCE(SUM(
                        CASE
                            WHEN je.id IS NOT NULL THEN COALESCE(jel.credit_amount, 0)
                            ELSE 0
                        END
                    ), 0) AS total_credit
                FROM accounts a
                LEFT JOIN journal_entry_lines jel
                    ON jel.account_id = a.id
                   AND jel.tenant_id = a.tenant_id
                LEFT JOIN journal_entries je
                    ON je.id = jel.journal_entry_id
                   AND je.tenant_id = a.tenant_id
                   AND (? IS NULL OR je.entry_date >= ?)
                   AND (? IS NULL OR je.entry_date < ?)
                   {$branchFilter}
                WHERE a.tenant_id = ?
                GROUP BY a.id, a.code, a.name, a.type
                ORDER BY a.code ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $startDateTime,
                $startDateTime,
                $endExclusive,
                $endExclusive,
                $tenantId
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$row) {
                $row['total_debit'] = round((float) $row['total_debit'], 2);
                $row['total_credit'] = round((float) $row['total_credit'], 2);
                $row['balance'] = round(
                    ((float) $row['total_debit']) - ((float) $row['total_credit']),
                    2
                );
            }
            unset($row);

            return $this->successResponse($response, [
                'items' => $rows,
                'filters' => [
                    'start_date' => $startDateTime ? substr($startDateTime, 0, 10) : null,
                    'end_date' => $endExclusive ? (new DateTimeImmutable($endExclusive))->modify('-1 day')->format('Y-m-d') : null
                ]
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->logger->error('Trial balance error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'Error generating trial balance', 500);
        }
    }

    /** Return an account's journal lines with opening, running, and closing balances. */
    public function ledger(Request $request, Response $response, array $args = []): Response
    {
        $tenantId = null;
        $accountId = (int) ($args['account_id'] ?? 0);

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            try {
                $branchId = $this->resolveBranchId($request, (int) $tenantId);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }
            $branchFilter = $this->journalEntryBranchFilter($branchId);

            if ($accountId <= 0) {
                return $this->errorResponse($response, 'مطلوب معرف الحساب (Account ID).', 400);
            }

            [$startDateTime, $endExclusive] = $this->resolveDateRange($request, (int) $tenantId);

            $accountStmt = $this->db->prepare("
                SELECT id, code, name, type
                FROM accounts
                WHERE tenant_id = ? AND id = ?
                LIMIT 1
            ");
            $accountStmt->execute([$tenantId, $accountId]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                return $this->errorResponse($response, 'الحساب غير موجود', 404);
            }

            $openingBalance = 0.0;
            if ($startDateTime !== null) {
                $openingStmt = $this->db->prepare("
                    SELECT
                        COALESCE(SUM(COALESCE(jel.debit_amount, 0)), 0) -
                        COALESCE(SUM(COALESCE(jel.credit_amount, 0)), 0) AS opening_balance
                    FROM journal_entry_lines jel
                    INNER JOIN journal_entries je
                        ON je.id = jel.journal_entry_id
                       AND je.tenant_id = jel.tenant_id
                    WHERE jel.tenant_id = ?
                      AND jel.account_id = ?
                      AND je.entry_date < ?
                      {$branchFilter}
                ");
                $openingStmt->execute([$tenantId, $accountId, $startDateTime]);
                $openingBalance = (float) $openingStmt->fetchColumn();
            }

            $sql = "
                SELECT
                    je.id AS journal_entry_id,
                    je.entry_date,
                    je.description,
                    jel.debit_amount,
                    jel.credit_amount
                FROM journal_entry_lines jel
                INNER JOIN journal_entries je
                    ON je.id = jel.journal_entry_id
                   AND je.tenant_id = jel.tenant_id
                WHERE jel.tenant_id = ?
                  AND jel.account_id = ?
                  AND (? IS NULL OR je.entry_date >= ?)
                  AND (? IS NULL OR je.entry_date < ?)
                  {$branchFilter}
                ORDER BY je.entry_date ASC, je.id ASC, jel.id ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $tenantId,
                $accountId,
                $startDateTime,
                $startDateTime,
                $endExclusive,
                $endExclusive
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $running = $openingBalance;
            foreach ($rows as &$row) {
                $debit = (float) ($row['debit_amount'] ?? 0);
                $credit = (float) ($row['credit_amount'] ?? 0);

                $row['debit_amount'] = round($debit, 2);
                $row['credit_amount'] = round($credit, 2);

                $running += ($debit - $credit);
                $row['running_balance'] = round($running, 2);
            }
            unset($row);

            return $this->successResponse($response, [
                'account' => $account,
                'opening_balance' => round($openingBalance, 2),
                'closing_balance' => round($running, 2),
                'lines' => $rows,
                'filters' => [
                    'start_date' => $startDateTime ? substr($startDateTime, 0, 10) : null,
                    'end_date' => $endExclusive ? (new DateTimeImmutable($endExclusive))->modify('-1 day')->format('Y-m-d') : null
                ]
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->logger->error('Ledger error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'account_id' => $accountId
            ]);

            return $this->errorResponse($response, 'Error generating ledger', 500);
        }
    }

    /** Summarize revenue, expenses, and net income for the selected period. */
    public function incomeStatement(Request $request, Response $response): Response
    {
        $tenantId = null;

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            try {
                $branchId = $this->resolveBranchId($request, (int) $tenantId);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }
            $branchFilter = $this->journalEntryBranchFilter($branchId);

            [$startDateTime, $endExclusive] = $this->resolveDateRange($request, (int) $tenantId);

            $sql = "
                SELECT
                    a.type,
                    COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN COALESCE(jel.debit_amount, 0) ELSE 0 END), 0) AS total_debit,
                    COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN COALESCE(jel.credit_amount, 0) ELSE 0 END), 0) AS total_credit
                FROM accounts a
                LEFT JOIN journal_entry_lines jel
                    ON jel.account_id = a.id
                   AND jel.tenant_id = a.tenant_id
                LEFT JOIN journal_entries je
                    ON je.id = jel.journal_entry_id
                   AND je.tenant_id = a.tenant_id
                   AND (? IS NULL OR je.entry_date >= ?)
                   AND (? IS NULL OR je.entry_date < ?)
                   {$branchFilter}
                WHERE a.tenant_id = ?
                  AND a.type IN ('revenue', 'expense')
                GROUP BY a.type
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $startDateTime,
                $startDateTime,
                $endExclusive,
                $endExclusive,
                $tenantId
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $revenues = 0.0;
            $expenses = 0.0;

            foreach ($rows as $row) {
                $type = (string) ($row['type'] ?? '');
                $debit = (float) ($row['total_debit'] ?? 0);
                $credit = (float) ($row['total_credit'] ?? 0);

                if ($type === 'revenue') {
                    $revenues = $credit - $debit;
                } elseif ($type === 'expense') {
                    $expenses = $debit - $credit;
                }
            }

            $netIncome = $revenues - $expenses;

            return $this->successResponse($response, [
                'revenues' => round($revenues, 2),
                'expenses' => round($expenses, 2),
                'net_income' => round($netIncome, 2),
                'filters' => [
                    'start_date' => $startDateTime ? substr($startDateTime, 0, 10) : null,
                    'end_date' => $endExclusive ? (new DateTimeImmutable($endExclusive))->modify('-1 day')->format('Y-m-d') : null
                ]
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->logger->error('Income statement error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'Error generating income statement', 500);
        }
    }

    /** Return asset, liability, and equity balances as of the requested date. */
    public function balanceSheet(Request $request, Response $response): Response
    {
        $tenantId = null;

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            try {
                $branchId = $this->resolveBranchId($request, (int) $tenantId);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }
            $branchFilter = $this->journalEntryBranchFilter($branchId);

            $qp = $request->getQueryParams();
            $asOfDate = $this->normalizeDateOnly((string) ($qp['as_of_date'] ?? date('Y-m-d')));
            $nextDay = (new DateTimeImmutable($asOfDate))->modify('+1 day')->format('Y-m-d 00:00:00');

            $sql = "
                SELECT
                    a.id,
                    a.code,
                    a.name,
                    a.type,
                    COALESCE(SUM(
                        CASE
                            WHEN je.id IS NOT NULL THEN COALESCE(jel.debit_amount, 0)
                            ELSE 0
                        END
                    ), 0) AS deb,
                    COALESCE(SUM(
                        CASE
                            WHEN je.id IS NOT NULL THEN COALESCE(jel.credit_amount, 0)
                            ELSE 0
                        END
                    ), 0) AS cred
                FROM accounts a
                LEFT JOIN journal_entry_lines jel
                    ON jel.account_id = a.id
                   AND jel.tenant_id = a.tenant_id
                LEFT JOIN journal_entries je
                    ON je.id = jel.journal_entry_id
                   AND je.tenant_id = a.tenant_id
                   AND je.entry_date < ?
                   {$branchFilter}
                WHERE a.tenant_id = ?
                  AND a.type IN ('asset', 'liability', 'equity')
                GROUP BY a.id, a.code, a.name, a.type
                ORDER BY a.code ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $nextDay,
                $tenantId
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $result = [
                'asset' => [],
                'liability' => [],
                'equity' => []
            ];

            $totals = [
                'asset' => 0.0,
                'liability' => 0.0,
                'equity' => 0.0
            ];

            foreach ($rows as $row) {
                $type = (string) $row['type'];
                $debit = (float) ($row['deb'] ?? 0);
                $credit = (float) ($row['cred'] ?? 0);

                $balance = $debit - $credit;
                if ($type !== 'asset') {
                    $balance = $credit - $debit;
                }

                $formatted = [
                    'id' => (int) $row['id'],
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'type' => $type,
                    'balance' => round($balance, 2)
                ];

                $result[$type][] = $formatted;
                $totals[$type] += $balance;
            }

            // The balance sheet must include the current-period result in equity
            // until the period is closed and the result is transferred to a
            // retained-earnings account.
            $stmtResult = $this->db->prepare("
                SELECT
                    a.type,
                    COALESCE(SUM(jel.debit_amount), 0) AS total_debit,
                    COALESCE(SUM(jel.credit_amount), 0) AS total_credit
                FROM accounts a
                JOIN journal_entry_lines jel
                    ON jel.account_id = a.id AND jel.tenant_id = a.tenant_id
                JOIN journal_entries je
                    ON je.id = jel.journal_entry_id AND je.tenant_id = a.tenant_id
                WHERE a.tenant_id = ?
                  AND a.type IN ('revenue', 'expense')
                  AND je.entry_date < ?
                  AND je.status IN ('posted', 'reversed')
                  {$branchFilter}
                GROUP BY a.type
            ");
            $stmtResult->execute([$tenantId, $nextDay]);

            $periodRevenue = 0.0;
            $periodExpenses = 0.0;
            foreach ($stmtResult->fetchAll(PDO::FETCH_ASSOC) ?: [] as $resultRow) {
                $debit = (float) ($resultRow['total_debit'] ?? 0);
                $credit = (float) ($resultRow['total_credit'] ?? 0);
                if ($resultRow['type'] === 'revenue') {
                    $periodRevenue += $credit - $debit;
                } else {
                    $periodExpenses += $debit - $credit;
                }
            }

            $currentPeriodResult = round($periodRevenue - $periodExpenses, 2);
            if (abs($currentPeriodResult) > 0.00001) {
                $result['equity'][] = [
                    'id' => 0,
                    'code' => 'CURRENT-PERIOD',
                    'name' => 'صافي ربح الفترة',
                    'type' => 'equity',
                    'balance' => $currentPeriodResult,
                ];
                $totals['equity'] += $currentPeriodResult;
            }

            return $this->successResponse($response, [
                'as_of_date' => $asOfDate,
                'asset' => $result['asset'],
                'liability' => $result['liability'],
                'equity' => $result['equity'],
                'totals' => [
                    'assets' => round($totals['asset'], 2),
                    'liabilities' => round($totals['liability'], 2),
                    'equity' => round($totals['equity'], 2),
                    'liabilities_and_equity' => round($totals['liability'] + $totals['equity'], 2)
                ]
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->logger->error('Balance sheet error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'Error generating balance sheet', 500);
        }
    }

    private function resolveDateRange(Request $request, int $tenantId): array
    {
        $qp = $request->getQueryParams();

        $start = isset($qp['start_date']) ? (string) $qp['start_date'] : (isset($qp['date_from']) ? (string) $qp['date_from'] : null);
        $end = isset($qp['end_date']) ? (string) $qp['end_date'] : (isset($qp['date_to']) ? (string) $qp['date_to'] : null);

        if (!$start && !$end) {
            return [null, null];
        }

        [$resolvedStart, $resolvedEnd] = $this->resolveTenantDateRange($tenantId, $start, $end);

        return [
            $start ? $resolvedStart : null,
            $end ? $resolvedEnd : null,
        ];
    }

    private function normalizeDateOnly(string $date): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('صيغة التاريخ غير صالحة. الصيغة المطلوبة YYYY-MM-DD');
        }

        return $dt->format('Y-m-d');
    }

    private function resolveBranchId(Request $request, int $tenantId): ?int
    {
        $params = $request->getQueryParams();
        if (!isset($params['branch_id']) || $params['branch_id'] === '') {
            return null;
        }

        $branchId = filter_var($params['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($branchId === false) {
            throw new \InvalidArgumentException('معرف الفرع غير صالح.');
        }

        $stmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$branchId, $tenantId]);
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException('الفرع غير موجود.');
        }

        return (int) $branchId;
    }

    private function journalEntryBranchFilter(?int $branchId): string
    {
        if ($branchId === null) {
            return '';
        }

        return " AND (
            EXISTS (SELECT 1 FROM sales s
                WHERE s.tenant_id = je.tenant_id AND s.branch_id = {$branchId}
                  AND (s.journal_entry_id = je.id OR (je.reference_type IN ('sale', 'cogs') AND s.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM purchases p
                WHERE p.tenant_id = je.tenant_id AND p.branch_id = {$branchId}
                  AND (p.journal_entry_id = je.id OR (je.reference_type = 'purchase' AND p.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM returns r
                WHERE r.tenant_id = je.tenant_id AND r.branch_id = {$branchId}
                  AND (r.journal_entry_id = je.id OR (je.reference_type IN ('return', 'sales_return', 'purchase_return') AND r.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM cash_vouchers cv
                WHERE cv.tenant_id = je.tenant_id AND cv.branch_id = {$branchId}
                  AND (cv.journal_entry_id = je.id OR je.voucher_id = cv.id OR (je.reference_type = 'voucher' AND cv.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM cash_transactions ct
                WHERE ct.tenant_id = je.tenant_id AND ct.branch_id = {$branchId}
                  AND (ct.journal_entry_id = je.id OR (je.reference_type = 'payment' AND ct.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM inventory_transactions it
                WHERE it.tenant_id = je.tenant_id AND it.journal_entry_id = je.id
                  AND (it.branch_from = {$branchId} OR it.branch_to = {$branchId}))
        )";
    }

    // ─── AR Aging Report + Bad Debt Provision (IFRS 9) ──────────────────────
    /** Return receivables grouped into aging buckets with estimated expected credit loss. */
    public function arAgingReport(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }
            try {
                $branchId = $this->resolveBranchId($request, (int) $tenantId);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }
            $branchClause = $branchId !== null ? ' AND s.branch_id = ' . $branchId : '';

            $params   = $request->getQueryParams();
            $asOf     = isset($params['as_of']) && $params['as_of'] !== ''
                ? $params['as_of'] : date('Y-m-d');

            $eclRates = [
                'current'    => ['label' => 'جارٍ (0-30 يوم)',        'min' => 0,   'max' => 30,  'rate' => 0.01],
                'days_31_60' => ['label' => 'متأخر 31-60 يوم',        'min' => 31,  'max' => 60,  'rate' => 0.05],
                'days_61_90' => ['label' => 'متأخر 61-90 يوم',        'min' => 61,  'max' => 90,  'rate' => 0.10],
                'days_91_180' => ['label' => 'متأخر 91-180 يوم',       'min' => 91,  'max' => 180, 'rate' => 0.25],
                'over_180'   => ['label' => 'متأخر أكثر من 180 يوم',  'min' => 181, 'max' => 9999,'rate' => 0.50],
            ];

            $sql = "
                SELECT
                    s.id            AS sale_id,
                    s.invoice_number,
                    s.sale_date,
                    DATEDIFF(?, s.sale_date) AS days_outstanding,
                    c.id            AS customer_id,
                    c.name          AS customer_name,
                    s.net_total_amount,
                    COALESCE(s.paid_amount, 0)                           AS paid_amount,
                    COALESCE(SUM(rca.allocated_amount), 0)              AS return_credits,
                    s.net_total_amount - COALESCE(s.paid_amount, 0) - COALESCE(SUM(rca.allocated_amount), 0) AS outstanding
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
                LEFT JOIN return_credit_allocations rca ON rca.sale_id = s.id AND rca.tenant_id = s.tenant_id
                WHERE s.tenant_id = ?
                  AND s.status NOT IN ('cancelled','draft')
                  AND s.sale_date <= ?
                  {$branchClause}
                GROUP BY s.id
                HAVING (
                    MAX(s.net_total_amount) > COALESCE(MAX(s.paid_amount), 0)
                    OR COALESCE(SUM(rca.allocated_amount), 0) > 0
                )
                ORDER BY days_outstanding DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$asOf, (int) $tenantId, $asOf]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $buckets = [];
            foreach ($eclRates as $key => $cfg) {
                $buckets[$key] = array_merge($cfg, [
                    'invoices'          => [],
                    'total_outstanding' => 0.0,
                    'ecl_provision'     => 0.0,
                ]);
            }

            foreach ($rows as $row) {
                $days = max(0, (int) $row['days_outstanding']);
                $out  = round((float) $row['outstanding'], 2);

                $bucketKey = 'over_180';
                foreach ($eclRates as $key => $cfg) {
                    if ($days >= $cfg['min'] && $days <= $cfg['max']) {
                        $bucketKey = $key;
                        break;
                    }
                }

                $buckets[$bucketKey]['invoices'][]          = [
                    'sale_id'         => $row['sale_id'],
                    'invoice_number'  => $row['invoice_number'],
                    'customer_name'   => $row['customer_name'] ?? 'عميل نقدي',
                    'sale_date'       => $row['sale_date'] ? substr($row['sale_date'], 0, 10) : null,
                    'days_outstanding' => $days,
                    'net_total'       => round((float) $row['net_total_amount'], 2),
                    'paid'            => round((float) $row['paid_amount'], 2),
                    'outstanding'     => $out,
                    'ecl_rate'        => $eclRates[$bucketKey]['rate'],
                    'ecl_provision'   => round($out * $eclRates[$bucketKey]['rate'], 2),
                ];
                $buckets[$bucketKey]['total_outstanding'] += $out;
                $buckets[$bucketKey]['ecl_provision']     += $out * $eclRates[$bucketKey]['rate'];
            }

            $totalOutstanding = 0.0;
            $totalProvision   = 0.0;
            foreach ($buckets as &$b) {
                $b['total_outstanding'] = round($b['total_outstanding'], 2);
                $b['ecl_provision']     = round($b['ecl_provision'], 2);
                $totalOutstanding      += $b['total_outstanding'];
                $totalProvision        += $b['ecl_provision'];
            }
            unset($b);

            return $this->successResponse($response, [
                'buckets'           => $buckets,
                'total_outstanding' => round($totalOutstanding, 2),
                'total_provision'   => round($totalProvision, 2),
                'as_of'             => $asOf,
                'note'              => 'IFRS 9 Simplified Approach — Provisioning Matrix. معدلات ECL قابلة للتعديل حسب سياسة الشركة.',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** Post a bad-debt provision journal entry for the requested amount and date. */
    public function postBadDebtProvision(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $userId = $this->extractUserId($request);
            $body   = $request->getParsedBody() ?? [];
            $amount = isset($body['amount']) ? round((float) $body['amount'], 2) : null;
            $asOf   = isset($body['as_of']) && $body['as_of'] !== '' ? $body['as_of'] : date('Y-m-d');

            if (!$amount || $amount <= 0) {
                return $this->errorResponse($response, 'مبلغ المخصص مطلوب وأكبر من صفر', 422);
            }

            $badDebtExpenseId  = $this->accounting->getAccountIdFallbackPublic((int) $tenantId, ['5301', '5300']);
            $badDebtAllowanceId = $this->accounting->getAccountIdFallbackPublic((int) $tenantId, ['1202', '1200']);

            if (!$badDebtExpenseId || !$badDebtAllowanceId) {
                return $this->errorResponse(
                    $response,
                    'لم يُعثر على حسابات الديون المشكوك فيها (5301 / 1202). يرجى إنشاؤها أولاً.',
                    422
                );
            }

            $idempotencyKey = "bad_debt_provision_{$tenantId}_{$asOf}";

            $jeId = $this->accounting->postJournalEntry(
                (int) $tenantId,
                'bad_debt_provision',
                null,
                "قيد مخصص الديون المشكوك فيها IFRS 9 — بتاريخ {$asOf}",
                [
                    ['account_id' => $badDebtExpenseId,   'debit' => $amount, 'credit' => 0,       'description' => 'مصروف الديون المشكوك فيها (IFRS 9)'],
                    ['account_id' => $badDebtAllowanceId, 'debit' => 0,       'credit' => $amount, 'description' => 'مخصص الديون المشكوك فيها'],
                ],
                $asOf,
                $userId,
                null,
                $idempotencyKey
            );

            if (!$jeId) {
                return $this->errorResponse($response, 'تم تسجيل هذا المخصص مسبقاً لنفس التاريخ (idempotency)', 409);
            }

            return $this->successResponse($response, [
                'journal_entry_id' => $jeId,
                'amount'           => $amount,
                'as_of'            => $asOf,
                'message'          => 'تم تسجيل قيد مخصص الديون المشكوك فيها بنجاح وفق IFRS 9.',
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** Group journal activity on liquidity accounts into operating, investing, and financing sections. */
    public function cashFlow(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }
            try {
                $branchId = $this->resolveBranchId($request, (int) $tenantId);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }
            $branchFilter = $this->journalEntryBranchFilter($branchId);

            [$startDateTime, $endExclusive] = $this->resolveDateRange($request, (int) $tenantId);

            $operatingTypes  = "'sale','payment','cogs','return','purchase','nrv_writedown','voucher','sales_return','purchase_return'";
            $investingTypes  = "'asset_purchase','asset_sale','fixed_asset'";
            $financingTypes  = "'capital','opening_balance','loan','loan_repayment','dividend'";

            $sql = "
                SELECT
                    a.code   AS account_code,
                    a.name   AS account_name,
                    je.reference_type,
                    CASE
                        WHEN je.reference_type IN ($operatingTypes)  THEN 'operating'
                        WHEN je.reference_type IN ($investingTypes)  THEN 'investing'
                        WHEN je.reference_type IN ($financingTypes)  THEN 'financing'
                        ELSE 'operating'
                    END AS category,
                    COALESCE(SUM(jel.debit_amount),  0) AS total_debit,
                    COALESCE(SUM(jel.credit_amount), 0) AS total_credit,
                    COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) AS net_cash
                FROM journal_entries je
                JOIN journal_entry_lines jel
                    ON jel.journal_entry_id = je.id AND jel.tenant_id = je.tenant_id
                JOIN accounts a
                    ON a.id = jel.account_id AND a.tenant_id = je.tenant_id
                WHERE je.tenant_id = ?
                  AND je.status   IN ('posted', 'reversed')
                  AND (a.code LIKE '1001%' OR a.code LIKE '1002%' OR a.code LIKE '1003%' OR a.type = 'cash')
                  AND (? IS NULL OR je.entry_date >= ?)
                  AND (? IS NULL OR je.entry_date <  ?)
                  {$branchFilter}
                GROUP BY a.id, a.code, a.name, je.reference_type, category
                ORDER BY category, je.reference_type
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                (int) $tenantId,
                $startDateTime, $startDateTime,
                $endExclusive, $endExclusive,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $sections = [
                'operating' => ['label' => 'التدفقات النقدية من أنشطة التشغيل', 'items' => [], 'total' => 0.0],
                'investing'  => ['label' => 'التدفقات النقدية من أنشطة الاستثمار', 'items' => [], 'total' => 0.0],
                'financing'  => ['label' => 'التدفقات النقدية من أنشطة التمويل', 'items' => [], 'total' => 0.0],
            ];

            $labelMap = [
                'sale'             => 'مقبوضات من العملاء',
                'payment'          => 'مدفوعات / مقبوضات متنوعة',
                'cogs'             => 'تكلفة البضاعة المباعة (غير نقدي)',
                'return'           => 'مرتجعات مبيعات',
                'sales_return'     => 'مرتجعات مبيعات',
                'purchase'         => 'مدفوعات للموردين',
                'purchase_return'  => 'مرتجعات مشتريات',
                'voucher'          => 'سندات صرف وقبض',
                'nrv_writedown'    => 'تخفيض قيمة المخزون (IAS 2 — غير نقدي)',
                'asset_purchase'   => 'شراء أصول ثابتة',
                'asset_sale'       => 'بيع أصول ثابتة',
                'fixed_asset'      => 'أصول ثابتة',
                'capital'          => 'مساهمات رأس المال',
                'opening_balance'  => 'رصيد افتتاحي',
                'loan'             => 'قروض مستلمة',
                'loan_repayment'   => 'سداد قروض',
                'dividend'         => 'توزيعات أرباح',
            ];

            foreach ($rows as $row) {
                $cat     = $row['category'];
                $refType = $row['reference_type'];
                $net     = round((float) $row['net_cash'], 2);

                if (!isset($sections[$cat])) {
                    $cat = 'operating';
                }

                $sections[$cat]['items'][] = [
                    'reference_type' => $refType,
                    'label'          => $labelMap[$refType] ?? $refType,
                    'account_code'   => $row['account_code'],
                    'account_name'   => $row['account_name'],
                    'inflow'         => round((float) $row['total_debit'], 2),
                    'outflow'        => round((float) $row['total_credit'], 2),
                    'net'            => $net,
                ];
                $sections[$cat]['total'] += $net;
            }

            foreach ($sections as &$sec) {
                $sec['total'] = round($sec['total'], 2);
            }
            unset($sec);

            $netChange = round(
                $sections['operating']['total'] +
                $sections['investing']['total'] +
                $sections['financing']['total'],
                2
            );

            return $this->successResponse($response, [
                'sections'   => $sections,
                'net_change' => $netChange,
                'filters'    => [
                    'start_date' => $startDateTime ? substr($startDateTime, 0, 10) : null,
                    'end_date'   => $endExclusive
                        ? (new DateTimeImmutable($endExclusive))->modify('-1 day')->format('Y-m-d')
                        : null,
                ],
                'note' => 'قائمة التدفقات النقدية — الطريقة المباشرة (IAS 7). تشمل حركات الحسابات النقدية والبنكية فقط.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** List active stocked products whose average cost exceeds their current sale price. */
    public function nrvReport(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $params   = $request->getQueryParams();
            $branchId = isset($params['branch_id']) && $params['branch_id'] !== ''
                ? (int) $params['branch_id'] : null;

            $sql = "
                SELECT
                    p.id            AS product_id,
                    p.name          AS product_name,
                    p.barcode,
                    b.id            AS branch_id,
                    b.name          AS branch_name,
                    bp.quantity     AS qty_on_hand,
                    pbm.average_cost AS unit_cost,
                    p.sale_price    AS sale_price,
                    p.sale_price - pbm.average_cost AS margin_per_unit,
                    bp.quantity * pbm.average_cost  AS total_cost_value,
                    bp.quantity * p.sale_price      AS total_nrv,
                    bp.quantity * (pbm.average_cost - p.sale_price) AS impairment_amount
                FROM products p
                JOIN branch_products bp
                    ON bp.product_id = p.id AND bp.tenant_id = p.tenant_id
                JOIN branches b ON b.id = bp.branch_id
                JOIN product_branch_gl_mapping pbm
                    ON pbm.product_id = p.id AND pbm.branch_id = bp.branch_id AND pbm.tenant_id = p.tenant_id
                WHERE p.tenant_id = ?
                  AND p.active = 1
                  AND bp.quantity > 0
                  AND pbm.average_cost > 0
                  AND pbm.average_cost > p.sale_price
            ";
            $bind = [(int) $tenantId];

            if ($branchId) {
                $sql .= " AND bp.branch_id = ?";
                $bind[] = $branchId;
            }
            $sql .= " ORDER BY impairment_amount ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $totalImpairment = array_sum(array_column($rows, 'impairment_amount'));

            return $this->successResponse($response, [
                'items'            => $rows,
                'total_impairment' => round($totalImpairment, 2),
                'count'            => count($rows),
                'note'             => 'المنتجات المُدرجة تستوجب تخفيض قيمة المخزون وفق IAS 2 (التكلفة > صافي القيمة البيعية)',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** Delegate posting of inventory NRV write-down entries to AccountingService. */
    public function postNrvWriteDown(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $userId   = $this->extractUserId($request);
            $params   = $request->getParsedBody() ?? [];
            $branchId = isset($params['branch_id']) && $params['branch_id'] !== ''
                ? (int) $params['branch_id'] : null;

            $result = $this->accounting->postNrvWriteDown((int) $tenantId, $userId, $branchId);

            if (empty($result['posted'])) {
                return $this->successResponse($response, [
                    'posted'       => [],
                    'skipped'      => $result['skipped'],
                    'total_amount' => 0.0,
                    'message'      => 'لا توجد منتجات تستوجب تخفيضاً، أو تم تسجيل قيد اليوم مسبقاً.',
                ]);
            }

            return $this->successResponse($response, [
                'posted'            => $result['posted'],
                'journal_entry_ids' => $result['posted'],
                'skipped'           => $result['skipped'],
                'total_amount'      => $result['total_amount'],
                'message'           => 'تم تسجيل قيود تخفيض قيمة المخزون بنجاح وفق IAS 2.',
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }
}
