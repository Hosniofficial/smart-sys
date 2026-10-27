<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Builds tenant-scoped profit-and-loss reports from sales, returns, configured expenses, and optional COGS.
 * Report routes apply their permissions in app/routes/reports.php.
 */
class AdvancedReportsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('advanced_reports');
    }

    /** Build a tenant P&L from sales, returns, configured expenses, and optional COGS. */
    public function getProfitLossReport(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();

            $mode = strtolower((string) ($params['mode'] ?? 'basic'));
            $expenseSource = strtolower((string) ($params['expense_source'] ?? 'vouchers'));

            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            [$from, $toExclusive] = $this->resolveTenantDateRange(
                (int) $tenantId,
                $params['start_date'] ?? null,
                $params['end_date'] ?? null
            );
            $startDateDisplay = substr($from, 0, 10);
            $endDateDisplay = date('Y-m-d', strtotime($toExclusive . ' -1 day'));
            $branchId = null;
            if (isset($params['branch_id']) && $params['branch_id'] !== '') {
                $branchId = filter_var($params['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($branchId === false) {
                    return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
                }
                $branchStmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchStmt->execute([$branchId, $tenantId]);
                if (!$branchStmt->fetchColumn()) {
                    return $this->errorResponse($response, 'الفرع غير موجود.', 404);
                }
                $branchId = (int) $branchId;
            }
            $salesBranchClause = $branchId !== null ? " AND s.branch_id = {$branchId}" : '';
            $returnsBranchClause = $branchId !== null ? " AND r.branch_id = {$branchId}" : '';
            $voucherBranchClause = $branchId !== null ? " AND cv.branch_id = {$branchId}" : '';
            $cashTransactionBranchClause = $branchId !== null ? " AND branch_id = {$branchId}" : '';
            $revenueSql = "
                SELECT
                    COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount)), 0) AS total_sales,
                    COALESCE(SUM(s.tax_amount), 0) AS total_tax,
                    COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount) + s.tax_amount), 0) AS grand_total
                FROM sales s
                WHERE s.tenant_id = :tenant_id
                  AND s.created_at >= :from
                  AND s.created_at <  :to_exclusive
                                    AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')
                  {$salesBranchClause}
            ";

            $stmt = $this->db->prepare($revenueSql);
            $stmt->execute([
                ':tenant_id'    => $tenantId,
                ':from'         => $from,
                ':to_exclusive' => $toExclusive,
            ]);
            $salesData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $returnsSql = "
                SELECT
                    COALESCE(SUM(r.total_amount), 0) AS total_returns,
                    COALESCE(SUM(r.tax_amount), 0) AS returns_tax,
                    COALESCE(SUM(r.total_amount + r.tax_amount), 0) AS returns_grand_total
                FROM returns r
                WHERE r.tenant_id = :tenant_id
                  AND r.return_type = 'sale'
                  AND LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')
                  AND r.created_at >= :from
                  AND r.created_at <  :to_exclusive
                  {$returnsBranchClause}
            ";

            $stmt = $this->db->prepare($returnsSql);
            $stmt->execute([
                ':tenant_id'    => $tenantId,
                ':from'         => $from,
                ':to_exclusive' => $toExclusive,
            ]);
            $returnsData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $totalRevenueVal = (float) ($salesData['total_sales'] ?? 0) - (float) ($returnsData['total_returns'] ?? 0);

            $merged = [];

            if (in_array($expenseSource, ['vouchers', 'both'], true)) {
                $expensesSql1 = "
                    SELECT
                        COALESCE(a.name, 'مصروفات أخرى') AS expense_category,
                        COALESCE(SUM(cv.amount), 0) AS total
                    FROM cash_vouchers cv
                    LEFT JOIN accounts a
                      ON a.id = cv.account_id
                     AND a.tenant_id = cv.tenant_id
                    WHERE cv.tenant_id = :tenant_id
                      AND cv.status = 'posted'
                      AND cv.type = 'payment'
                      AND (a.type = 'expense' OR a.type IS NULL)
                      AND cv.date >= :from_date AND cv.date < :to_date
                      {$voucherBranchClause}
                    GROUP BY COALESCE(a.name, 'مصروفات أخرى')
                ";

                $stmt = $this->db->prepare($expensesSql1);
                $stmt->execute([
                    ':tenant_id' => $tenantId,
                    ':from_date' => substr($from, 0, 10),
                    ':to_date'   => substr($toExclusive, 0, 10),
                ]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = $row['expense_category'] ?? 'other';
                    if (!isset($merged[$key])) {
                        $merged[$key] = ['expense_category' => $key, 'total' => 0.0];
                    }
                    $merged[$key]['total'] += (float) $row['total'];
                }
            }

            if (in_array($expenseSource, ['transactions', 'both'], true)) {
                $expensesSql2 = "
                    SELECT
                        COALESCE(reference_type, 'other') AS expense_category,
                        COALESCE(SUM(amount), 0) AS total
                    FROM cash_transactions
                    WHERE tenant_id = :tenant_id
                      AND COALESCE(status, '') <> 'cancelled'
                      AND type = 'expense'
                      AND created_at >= :from
                      AND created_at <  :to_exclusive
                      {$cashTransactionBranchClause}
                    GROUP BY COALESCE(reference_type, 'other')
                ";

                $stmt = $this->db->prepare($expensesSql2);
                $stmt->execute([
                    ':tenant_id'    => $tenantId,
                    ':from'         => $from,
                    ':to_exclusive' => $toExclusive,
                ]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = $row['expense_category'] ?? 'other';
                    if (!isset($merged[$key])) {
                        $merged[$key] = ['expense_category' => $key, 'total' => 0.0];
                    }
                    $merged[$key]['total'] += (float) $row['total'];
                }
            }

            $expenses = array_values($merged);
            usort($expenses, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

            $totalExpenses = array_sum(array_column($expenses, 'total'));

            $cogsSource = 'derived_sales';
            $cogs = 0.0;
            $returnsCOGS = 0.0;
            $returnsCostDetails = null;

            if ($mode === 'cogs') {
                $stmt = $this->db->prepare("
                    SELECT DISTINCT r.id
                    FROM returns r
                    WHERE r.tenant_id = :tenant_id
                      AND r.return_type = 'sale'
                      AND LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')
                      AND r.created_at >= :from
                      AND r.created_at <  :to_exclusive
                      {$returnsBranchClause}
                ");
                $stmt->execute([
                    ':tenant_id'    => $tenantId,
                    ':from'         => $from,
                    ':to_exclusive' => $toExclusive,
                ]);
                $returnIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $returnsCostDetails = (new \App\Services\ReturnCostingService($this->db))
                    ->calculateDetailsForReturns((int) $tenantId, $returnIds);
                $returnsCOGS = $returnsCostDetails['cost_amount'];

                $costing = new \App\Services\CostingService($this->db);
                $stmt = $this->db->prepare("
                    SELECT s.id, s.created_at
                    FROM sales s
                    WHERE s.tenant_id = :tenant_id
                      AND s.created_at >= :from
                      AND s.created_at < :to_exclusive
                      AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')
                      {$salesBranchClause}
                ");
                $stmt->execute([
                    ':tenant_id' => $tenantId,
                    ':from' => $from,
                    ':to_exclusive' => $toExclusive,
                ]);
                $periodSales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $saleIds = array_map(static fn(array $sale): int => (int) $sale['id'], $periodSales);
                $fallbackCogs = [];
                if ($saleIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
                    $fallbackStmt = $this->db->prepare("
                        SELECT sale_id, COALESCE(SUM(quantity * purchase_price), 0) AS cogs
                        FROM sales_items
                        WHERE tenant_id = ?
                          AND sale_id IN ($placeholders)
                        GROUP BY sale_id
                    ");
                    $fallbackStmt->execute(array_merge([(int) $tenantId], $saleIds));
                    foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $fallbackCogs[(int) $row['sale_id']] = (float) $row['cogs'];
                    }
                }

                $cogs = 0.0;
                foreach ($periodSales as $saleRow) {
                    $saleId = (int) $saleRow['id'];
                    $saleCogs = (float) $costing->computeCOGSForSale(
                        (int) $tenantId,
                        $saleId,
                        $saleRow['created_at'] ?: null
                    );
                    if ($saleCogs <= 0.0000001) {
                        $saleCogs = $fallbackCogs[$saleId] ?? 0.0;
                    }
                    $cogs += $saleCogs;
                }
                $cogs = round($cogs, 2);
                $cogsSource = 'costing_engine_wac';

                $netCOGS = $cogs - $returnsCOGS;
                $grossProfit = $totalRevenueVal - $netCOGS;
                $netProfit = $grossProfit - $totalExpenses;
            } else {
                $netCOGS = 0.0;
                $grossProfit = $totalRevenueVal;
                $cogs = 0.0;
                $netProfit = $totalRevenueVal - $totalExpenses;
            }

            $result = [
                'period' => [
                    'start_date' => $startDateDisplay,
                    'end_date'   => $endDateDisplay,
                ],
                'revenue' => [
                    'total_revenue' => $totalRevenueVal,
                    'gross_profit' => $grossProfit,
                    'cogs' => $netCOGS,
                    'returns_cogs' => $returnsCOGS,
                    'returns_cost_unresolved_line_count' => $returnsCostDetails['unresolved_line_count'] ?? null,
                    'returns_cost_unresolved_quantity' => $returnsCostDetails['unresolved_quantity'] ?? null,
                    'returns_cost_complete' => $returnsCostDetails === null
                        ? null
                        : $returnsCostDetails['unresolved_line_count'] === 0,
                    'net_cogs' => $netCOGS,
                    'cogs_source' => $cogsSource
                ],
                'account_transactions' => $expenses,
                'summary' => [
                    'total_revenue' => $totalRevenueVal,
                    'total_expenses' => (float) $totalExpenses,
                    'gross_profit' => $grossProfit,
                    'cogs' => $netCOGS,
                    'returns_cogs' => $returnsCOGS,
                    'returns_cost_unresolved_line_count' => $returnsCostDetails['unresolved_line_count'] ?? null,
                    'returns_cost_unresolved_quantity' => $returnsCostDetails['unresolved_quantity'] ?? null,
                    'returns_cost_complete' => $returnsCostDetails === null
                        ? null
                        : $returnsCostDetails['unresolved_line_count'] === 0,
                    'net_cogs' => $netCOGS,
                    'net_profit' => (float) $netProfit
                ]
            ];

            return $this->successResponse($response, $result);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\PDOException $e) {
            $this->logger->error('getProfitLossReport - Database error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'فشل في جلب تقرير الأرباح والخسائر', 500);
        } catch (Throwable $e) {
            $this->logger->error('getProfitLossReport - General error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ غير متوقع', 500);
        }
    }
}
