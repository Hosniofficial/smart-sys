<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Builds tenant-scoped operational and financial reports from database records.
 *
 * Financial cost-of-goods-sold calculations use CostingService and
 * ReturnCostingService; report routes apply their permissions in app/routes/reports.php.
 */
class AdvancedReportsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('advanced_reports');
    }

    /**
     * Resolve a tenant account configuration through the accounting service.
     *
     * @internal Helper method for resolving configured GL accounts
     * @param int $tenantId Tenant identifier
     * @param string $settingKeyName Configuration key name
     * @param string $fallbackCode Fallback account code if not configured
     * @return int|null Resolved account ID or null
     */
    private function resolveAccountId(int $tenantId, string $settingKeyName, string $fallbackCode): ?int
    {
        return $this->accounting->resolveAccountId($tenantId, $settingKeyName, $fallbackCode);
    }

    /**
     * Return today's sales, returns, cash sales, and five most recent activities.
     *
     * This internal helper provides a daily dashboard view combining:
     * - Total sales revenue for today
     * - Total returns for today
     * - Net sales (after returns)
     * - Cash in drawer (cash payments only)
     * - Five most recent transactions (sales and returns)
     *
     * Called from:
     * - Route: GET /daily-performance (registered in app/routes/reports.php)
     * - Frontend: Dashboard daily summary display
     *
     * @internal Used internally for dashboard initialization
     *
     * @param Request $request HTTP request with tenant context
     * @param Response $response HTTP response object
     *
     * @return Response JSON response with daily metrics:
     *     {
     *         "status": "success",
     *         "data": {
     *             "totalSales": 5000.00,
     *             "totalReturns": 200.00,
     *             "netSales": 4800.00,
     *             "cashInDrawer": 3500.00,
     *             "recentActivities": [...]
     *         }
     *     }
     *
     * @throws Throwable On database errors
     *
     * @see getProfitLossReport() Related comprehensive report
     */
    public function getDailyPerformance(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $todayStart = date('Y-m-d 00:00:00');
            $todayEnd = date('Y-m-d 23:59:59');

            // Revenue includes settled sales; returned sales are excluded.
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(COALESCE(net_total_amount, total_amount)), 0)
                FROM sales
                WHERE created_at BETWEEN ? AND ?
                  AND status IN ('paid', 'settled_by_credit', 'closed_by_return', 'settled_mixed')
                  AND tenant_id = ?
            ");
            $stmt->execute([$todayStart, $todayEnd, $tenantId]);
            $totalSales = (float) $stmt->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(grand_total), 0)
                FROM returns
                WHERE created_at BETWEEN ? AND ?
                  AND return_type = 'sale'
                  AND tenant_id = ?
            ");
            $stmt->execute([$todayStart, $todayEnd, $tenantId]);
            $totalReturns = (float) $stmt->fetchColumn();

            $netSales = $totalSales - $totalReturns;

            // Cash-in-drawer includes only fully paid sales using a cash payment method.
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount)), 0) AS cash_total
                FROM sales s
                LEFT JOIN payment_methods pm
                  ON pm.id = s.payment_method_id
                 AND pm.tenant_id = s.tenant_id
                WHERE s.created_at BETWEEN ? AND ?
                  AND s.status = 'paid'
                  AND s.tenant_id = ?
                  AND pm.kind = 'cash'
            ");
            $stmt->execute([$todayStart, $todayEnd, $tenantId]);
            $cashInDrawer = (float) $stmt->fetchColumn();

            $sql = "
                (SELECT
                    id,
                    COALESCE(net_total_amount, total_amount) AS amount,
                    created_at,
                    'بيع فاتورة #' AS description_prefix,
                    'sale' AS type
                 FROM sales
                 WHERE status IN ('paid', 'settled_by_credit', 'closed_by_return', 'settled_mixed')
                   AND created_at BETWEEN :start_date1 AND :end_date1
                   AND tenant_id = :tenant_id1)
                UNION ALL
                (SELECT
                    id,
                    -grand_total AS amount,
                    created_at,
                    'مرتجع فاتورة #' AS description_prefix,
                    'return' AS type
                 FROM returns
                 WHERE created_at BETWEEN :start_date2 AND :end_date2
                   AND return_type = 'sale'
                   AND tenant_id = :tenant_id2)
                ORDER BY created_at DESC
                LIMIT 5
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':start_date1' => $todayStart,
                ':end_date1' => $todayEnd,
                ':tenant_id1' => $tenantId,
                ':start_date2' => $todayStart,
                ':end_date2' => $todayEnd,
                ':tenant_id2' => $tenantId
            ]);

            $rawActivities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $recentActivities = array_map(
                static fn (array $activity): array => [
                    'id' => $activity['type'] . '_' . $activity['id'],
                    'description' => $activity['description_prefix'] . $activity['id'],
                    'amount' => (float) $activity['amount'],
                    'time' => date('h:i A', strtotime($activity['created_at']))
                ],
                $rawActivities
            );

            return $this->successResponse($response, [
                'totalSales' => $totalSales,
                'totalReturns' => $totalReturns,
                'netSales' => $netSales,
                'cashInDrawer' => $cashInDrawer,
                'recentActivities' => $recentActivities
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Daily Performance Report Error', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'تعذّر جلب تقرير الأداء اليومي.', 500);
        }
    }

    /**
     * Aggregate tenant sales by calendar period and summarize top products.
     *
     * This internal helper method is called from getProfitLossReport() to gather
     * sales performance metrics for the specified date range. It groups sales by
     * the requested period (day, week, month, quarter, year) and calculates:
     * - Total sales count
     * - Revenue (net total amount)
     * - Profit
     * - Unique customers
     * - Average sale value
     * - Top products
     *
     * Called from:
     * - AdvancedReportsHandler::getAdvancedInventoryReport() [Line 262]
     * - AdvancedReportsHandler::getProfitLossReport() [Line 357]
     *
     * @internal Used internally for report generation - do not expose directly
     *
     * @param array $params {
     *     @type string $start_date Starting date (YYYY-MM-DD). Default: 30 days ago
     *     @type string $end_date Ending date (YYYY-MM-DD). Default: today
     *     @type string $group_by Grouping period: 'day', 'week', 'month', 'quarter', 'year'. Default: 'day'
     * }
     * @param int $tenantId Tenant identifier to filter sales by
     *
     * @return array Array of sales performance records grouped by period:
     *     [
     *         [
     *             'period' => '2024-10',
     *             'total_sales' => 42,
     *             'revenue' => 15000.50,
     *             'profit' => 3500.00,
     *             'unique_customers' => 28,
     *             'average_sale' => 357.15,
     *             'top_products' => 'Product A, Product B, Product C'
     *         ],
     *         ...
     *     ]
     *
     * @throws PDOException If database query fails
     *
     * @see getProfitLossReport() The main method that calls this helper
     * @see getInventoryAnalysis() Related helper method
     *
     * @version 1.0
     */
    public function getSalesPerformance(array $params, int $tenantId): array
    {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $groupBy = $params['group_by'] ?? 'day';

        $groupFormats = [
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            'quarter' => '%Y-%m',
            'year' => '%Y'
        ];
        $groupFormat = $groupFormats[$groupBy] ?? $groupFormats['day'];

        $sql = "
            SELECT
                DATE_FORMAT(s.created_at, '{$groupFormat}') AS period,
                COUNT(DISTINCT s.id) AS total_sales,
                COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount)), 0) AS revenue,
                COALESCE(SUM(s.total_profit), 0) AS profit,
                COUNT(DISTINCT s.customer_id) AS unique_customers,
                ROUND(AVG(COALESCE(s.net_total_amount, s.total_amount)), 2) AS average_sale,
                (
                    SELECT GROUP_CONCAT(p.name)
                    FROM sales_items si
                    JOIN products p ON p.id = si.product_id
                    WHERE si.sale_id IN (SELECT id FROM sales WHERE DATE_FORMAT(created_at, '{$groupFormat}') = DATE_FORMAT(s.created_at, '{$groupFormat}') AND tenant_id = :tenant_id)
                    GROUP BY si.sale_id
                    ORDER BY COUNT(*) DESC
                    LIMIT 1
                ) AS top_products
            FROM sales s
            WHERE s.created_at >= :start_date
              AND s.created_at < :end_date_plus_one
              AND s.tenant_id = :tenant_id
            GROUP BY period
            ORDER BY period DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date_plus_one' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ':tenant_id' => $tenantId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Summarize active-product stock, cost value, recent sales, and margin percentages.
     *
     * This internal helper method analyzes inventory status for the tenant,
     * providing real-time stock valuation and sales performance metrics.
     *
     * Called from:
     * - AdvancedReportsHandler::getAdvancedInventoryReport() [Line 262]
     *
     * @internal Used internally for inventory analysis - do not expose directly
     *
     * @param array $params {
     *     @type string $branch_id Filter by branch (optional)
     *     @type string $category_id Filter by category (optional)
     *     @type bool $low_stock Include only low-stock items
     * }
     * @param int $tenantId Tenant identifier
     *
     * @return array Inventory analysis data:
     *     [
     *         'total_items' => 1250,
     *         'total_stock_value' => 125000.00,
     *         'average_cost' => 100.00,
     *         'items' => [
     *             [
     *                 'product_id' => 45,
     *                 'name' => 'Product Name',
     *                 'current_stock' => 100,
     *                 'cost_value' => 10000.00,
     *                 'recent_sales' => 25,
     *                 'margin_percent' => 35.50,
     *                 'status' => 'active'
     *             ],
     *             ...
     *         ]
     *     ]
     *
     * @see getAdvancedInventoryReport() The main method that calls this
     * @see getSalesPerformance() Related sales performance analysis
     */
    public function getInventoryAnalysis(array $params, int $tenantId): array
    {
        $sql = "
            SELECT
                p.id,
                p.name,
                (
                    SELECT COALESCE(SUM(wp.quantity), 0)
                    FROM branch_products wp
                    WHERE wp.product_id = p.id
                      AND wp.tenant_id = p.tenant_id
                ) AS total_stock_quantity,
                p.minimum_stock,
                p.unit_price,
                p.cost_price,
                (
                    SELECT COUNT(*)
                    FROM sales_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE si.product_id = p.id
                      AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND s.tenant_id = :tenant_id
                ) AS sales_last_30_days,
                (
                    SELECT COALESCE(SUM(si.quantity), 0)
                    FROM sales_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE si.product_id = p.id
                      AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND s.tenant_id = :tenant_id
                ) AS units_sold_last_30_days,
                (
                    (SELECT COALESCE(SUM(wp.quantity), 0)
                     FROM branch_products wp
                     WHERE wp.product_id = p.id AND wp.tenant_id = p.tenant_id) * p.cost_price
                ) AS inventory_value,
                CASE
                    WHEN p.cost_price > 0 THEN ROUND((p.unit_price - p.cost_price) / p.cost_price * 100, 2)
                    ELSE 0
                END AS margin_percentage
            FROM products p
            WHERE p.active = 1
              AND p.tenant_id = :tenant_id
            ORDER BY sales_last_30_days DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Expose the advanced tenant inventory analysis through the registered report route.
     *
     * This is a wrapper method that exposes getInventoryAnalysis() as an HTTP endpoint.
     * It validates tenant context and returns comprehensive inventory metrics.
     *
     * Called from:
     * - Route: GET /advanced-inventory-report (registered in app/routes/reports.php)
     * - Frontend: Inventory analysis and reporting module
     *
     * @internal Route wrapper for inventory analysis
     *
     * @param Request $request HTTP request with query params and tenant context
     * @param Response $response HTTP response object
     *
     * @return Response JSON response with inventory data or error
     *
     * @see getInventoryAnalysis() The internal helper that performs the analysis
     *
     * @api Public HTTP endpoint
     */
    public function getAdvancedInventoryReport(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            return $this->successResponse(
                $response,
                $this->getInventoryAnalysis($request->getQueryParams(), (int) $tenantId)
            );
        } catch (Throwable $e) {
            $this->logger->error('Advanced inventory report failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر جلب تقرير المخزون المتقدم.', 500);
        }
    }

    /**
     * Aggregate customer purchases, product preferences, and purchase recency.
     *
     * This internal helper generates customer insights including:
     * - Total purchases and spend
     * - Product preferences and categories
     * - Purchase recency metrics
     * - Customer segmentation
     *
     * Called from:
     * - AdvancedReportsHandler::getProfitLossReport() [Line 355]
     *
     * @internal Used internally for P&L report generation
     *
     * @param array $params {
     *     @type string $start_date Period start (default: -365 days)
     *     @type string $end_date Period end (default: today)
     *     @type int $limit Maximum customers to return (default: 100)
     * }
     * @param int $tenantId Tenant identifier
     *
     * @return array Customer insights data
     *
     * @see getProfitLossReport() Calling method
     * @see getFinancialSummary() Related financial analysis
     */
    public function getCustomerInsights(array $params, int $tenantId): array
    {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-365 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                c.id,
                c.name,
                c.email,
                c.phone,
                COUNT(DISTINCT s.id) AS total_purchases,
                COALESCE(SUM(s.total_amount), 0) AS total_spent,
                ROUND(AVG(s.total_amount), 2) AS average_purchase,
                MAX(s.created_at) AS last_purchase_date,
                (
                    SELECT GROUP_CONCAT(DISTINCT p.name)
                    FROM sales_items si
                    JOIN sales s2 ON s2.id = si.sale_id
                    JOIN products p ON p.id = si.product_id
                    WHERE s2.customer_id = c.id
                    AND s2.tenant_id = :tenant_id
                    GROUP BY s2.customer_id
                    LIMIT 5
                ) AS favorite_products,
                (
                    SELECT COUNT(DISTINCT MONTH(s3.created_at))
                    FROM sales s3
                    WHERE s3.customer_id = c.id
                    AND s3.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    AND s3.tenant_id = :tenant_id
                ) AS active_months,
                DATEDIFF(NOW(), MIN(s.created_at)) AS days_since_first_purchase,
                DATEDIFF(NOW(), MAX(s.created_at)) AS days_since_last_purchase
            FROM customers c
            LEFT JOIN sales s ON s.customer_id = c.id AND s.tenant_id = :tenant_id
            WHERE c.tenant_id = :tenant_id AND s.created_at BETWEEN :start_date AND :end_date
            GROUP BY c.id
            ORDER BY total_spent DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59',
            ':tenant_id' => $tenantId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Combine sales, receivables, inventory, and expense counts with P&L report.
     *
     * This internal helper aggregates financial summaries for the P&L report,
     * combining operational metrics (sales count, AR aging, inventory levels)
     * with financial data.
     *
     * Called from:
     * - AdvancedReportsHandler::getProfitLossReport() [Line 357]
     *
     * @internal Used internally - do not expose as public API
     *
     * @param array $params Report parameters
     * @param int $tenantId Tenant identifier
     * @param array $profitLossData The P&L data from getProfitLossReport()
     *
     * @return array Combined financial summary
     *
     * @see getProfitLossReport() Main report generation method
     */
    public function getFinancialSummary(array $params, int $tenantId, array $profitLossData): array
    {
        $period = $profitLossData['period'] ?? [];
        $startDate = (string) ($period['start_date'] ?? substr((string) ($params['start_date'] ?? date('Y-m-d')), 0, 10));
        $endDate = (string) ($period['end_date'] ?? substr((string) ($params['end_date'] ?? date('Y-m-d')), 0, 10));
        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
        $endDateExclusive = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $branchId = isset($params['branch_id']) && $params['branch_id'] !== ''
            ? (int) $params['branch_id']
            : null;

        $salesSql = "
            SELECT
                COUNT(*) AS total_sales,
                COALESCE(SUM(COALESCE(net_total_amount, total_amount)), 0) AS total_revenue,
                ROUND(AVG(COALESCE(net_total_amount, total_amount)), 2) AS average_sale,
                COUNT(DISTINCT customer_id) AS unique_customers
            FROM sales
            WHERE created_at >= :start_date
              AND created_at < :end_date
              AND tenant_id = :tenant_id
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
        ";
        $salesParams = [
            ':start_date' => $startDateTime,
            ':end_date' => $endDateTime,
            ':tenant_id' => $tenantId,
        ];
        if ($branchId !== null) {
            $salesSql .= ' AND branch_id = :branch_id';
            $salesParams[':branch_id'] = $branchId;
        }
        $stmt = $this->db->prepare($salesSql);
        $stmt->execute($salesParams);
        $salesSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $arSql = "
            SELECT
                COUNT(*) AS unpaid_invoices,
                COALESCE(SUM(COALESCE(net_total_amount, total_amount) - paid_amount), 0) AS total_receivable
            FROM sales
            WHERE created_at < :end_date
              AND tenant_id = :tenant_id
              AND COALESCE(payment_status, '') != 'paid'
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')
        ";
        $arParams = [':end_date' => $endDateTime, ':tenant_id' => $tenantId];
        if ($branchId !== null) {
            $arSql .= ' AND branch_id = :branch_id';
            $arParams[':branch_id'] = $branchId;
        }
        $stmt = $this->db->prepare($arSql);
        $stmt->execute($arParams);
        $arSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $inventorySql = "
            SELECT
                COUNT(DISTINCT p.id) AS total_products,
                COALESCE(SUM(wp.quantity * p.cost_price), 0) AS total_inventory_value
            FROM products p
            JOIN branch_products wp
              ON wp.product_id = p.id
             AND wp.tenant_id = p.tenant_id
            WHERE p.active = 1
              AND p.tenant_id = :tenant_id
        ";
        $inventoryParams = [':tenant_id' => $tenantId];
        if ($branchId !== null) {
            $inventorySql .= ' AND wp.branch_id = :branch_id';
            $inventoryParams[':branch_id'] = $branchId;
        }
        $stmt = $this->db->prepare($inventorySql);
        $stmt->execute($inventoryParams);
        $inventorySummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $revenue = $profitLossData['revenue'] ?? [];
        $summary = $profitLossData['summary'] ?? [];
        $totalProfit = (float) ($summary['gross_profit'] ?? 0);
        $totalExpenses = (float) ($summary['total_expenses'] ?? 0);
        $totalRevenue = (float) ($revenue['total_revenue'] ?? 0);
        $salesSummary['gross_revenue'] = (float) ($salesSummary['total_revenue'] ?? 0);
        $salesSummary['total_revenue'] = $totalRevenue;
        $salesSummary['total_profit'] = $totalProfit;
        $netProfit = (float) ($summary['net_profit'] ?? ($totalProfit - $totalExpenses));

        $expenseCount = 0;
        $expenseSource = $params['expense_source'] ?? 'vouchers';
        if (in_array($expenseSource, ['vouchers', 'both'], true)) {
            $voucherCountSql = "
                SELECT COUNT(*)
                FROM cash_vouchers cv
                LEFT JOIN accounts a
                  ON a.id = cv.account_id
                 AND a.tenant_id = cv.tenant_id
                WHERE cv.tenant_id = :tenant_id
                  AND cv.status = 'posted'
                  AND cv.type = 'payment'
                  AND (a.type = 'expense' OR a.type IS NULL)
                  AND cv.date >= :from_date
                  AND cv.date < :to_date
            ";
            $voucherCountParams = [
                ':tenant_id' => $tenantId,
                ':from_date' => $startDate,
                ':to_date' => $endDateExclusive,
            ];
            if ($branchId !== null) {
                $voucherCountSql .= ' AND cv.branch_id = :branch_id';
                $voucherCountParams[':branch_id'] = $branchId;
            }
            $stmt = $this->db->prepare($voucherCountSql);
            $stmt->execute($voucherCountParams);
            $expenseCount += (int) $stmt->fetchColumn();
        }
        if (in_array($expenseSource, ['transactions', 'both'], true)) {
            $transactionCountSql = "
                SELECT COUNT(*)
                FROM cash_transactions
                WHERE tenant_id = :tenant_id
                  AND COALESCE(status, '') <> 'cancelled'
                  AND type = 'expense'
                  AND created_at >= :from
                  AND created_at < :to_exclusive
            ";
            $transactionCountParams = [
                ':tenant_id' => $tenantId,
                ':from' => $startDateTime,
                ':to_exclusive' => $endDateTime,
            ];
            if ($branchId !== null) {
                $transactionCountSql .= ' AND branch_id = :branch_id';
                $transactionCountParams[':branch_id'] = $branchId;
            }
            $stmt = $this->db->prepare($transactionCountSql);
            $stmt->execute($transactionCountParams);
            $expenseCount += (int) $stmt->fetchColumn();
        }
        $expensesSummary = [
            'total_expenses' => $totalExpenses,
            'expense_count' => $expenseCount,
        ];

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'sales' => $salesSummary,
            'account_transactions' => $expensesSummary,
            'accounts_receivable' => $arSummary,
            'inventory' => $inventorySummary,
            'net_profit' => $netProfit,
            'profit_margin' => $totalRevenue > 0
                ? round(($netProfit / $totalRevenue) * 100, 2)
                : 0
        ];
    }

    /**
     * Report branch stock value and inventory-transfer activity.
     *
     * This internal helper provides branch-level inventory metrics:
     * - Stock valuation per branch
     * - Inventory transfer activity
     * - Branch performance comparison
     *
     * Called from:
     * - AdvancedReportsHandler::getProfitLossReport() [Line 359]
     *
     * @internal Used internally for comprehensive reporting
     *
     * @param array $params Filter and period parameters
     * @param int $tenantId Tenant identifier
     *
     * @return array Branch performance and inventory data
     *
     * @see getProfitLossReport() Main calling method
     * @see getSupplierPerformance() Related performance analysis
     */
    public function getBranchPerformance(array $params, int $tenantId): array
    {
        $sql = "
            SELECT
                b.id,
                b.name,
                COUNT(DISTINCT wp.product_id) AS total_products,
                COALESCE(SUM(wp.quantity), 0) AS total_items,
                COALESCE(SUM(wp.quantity * p.cost_price), 0) AS inventory_value,
                (
                    SELECT COUNT(*)
                    FROM inventory_transactions it
                    WHERE it.branch_to = b.id
                      AND it.tenant_id = b.tenant_id
                      AND it.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ) AS inbound_last_30_days,
                (
                    SELECT COUNT(*)
                    FROM inventory_transactions it
                    WHERE it.branch_from = b.id
                      AND it.tenant_id = b.tenant_id
                      AND it.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ) AS outbound_last_30_days,
                (
                    SELECT COUNT(DISTINCT wp2.product_id)
                    FROM branch_products wp2
                    JOIN products p2 ON p2.id = wp2.product_id AND p2.tenant_id = wp2.tenant_id
                    WHERE wp2.branch_id = b.id AND wp2.tenant_id = b.tenant_id
                    GROUP BY wp2.product_id
                    HAVING SUM(wp2.quantity) <= p2.minimum_stock
                ) AS low_stock_items,
                (
                    SELECT AVG(DATEDIFF(
                        it.created_at,
                        LAG(it.created_at) OVER (ORDER BY it.created_at)
                    ))
                    FROM inventory_transactions it
                    WHERE (it.branch_from = b.id OR it.branch_to = b.id) AND it.tenant_id = b.tenant_id
                    AND it.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                ) AS avg_days_between_transactions
            FROM branches b
            LEFT JOIN branch_products wp ON wp.branch_id = b.id AND wp.tenant_id = b.tenant_id
            LEFT JOIN products p ON p.id = wp.product_id AND p.tenant_id = b.tenant_id
            WHERE b.tenant_id = :tenant_id
            GROUP BY b.id
            ORDER BY inventory_value DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aggregate supplier purchases, active products, delivery intervals, and returns.
     *
     * This internal helper generates supplier performance metrics for vendor evaluation:
     * - Purchase history and spend
     * - Active products with this supplier
     * - Average delivery intervals
     * - Return rates and quality metrics
     *
     * Called from:
     * - AdvancedReportsHandler::getProfitLossReport() [Line 361]
     *
     * @internal Used internally - supplier data is sensitive
     *
     * @param array $params {
     *     @type string $start_date Period start
     *     @type string $end_date Period end
     * }
     * @param int $tenantId Tenant identifier
     *
     * @return array Supplier performance metrics
     *
     * @see getProfitLossReport() Main report method
     * @see getCustomerInsights() Related customer analytics
     */
    public function getSupplierPerformance(array $params, int $tenantId): array
    {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-365 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                s.id,
                s.name,
                COUNT(DISTINCT p.id) AS total_orders,
                COALESCE(SUM(p.total), 0) AS total_spent,
                ROUND(AVG(p.total), 2) AS average_order,
                MAX(p.created_at) AS last_order_date,
                (
                    SELECT COUNT(*)
                    FROM products pr
                    WHERE pr.supplier_id = s.id
                    AND pr.active = 1
                ) AS active_products,
                (
                    SELECT AVG(DATEDIFF(
                        delivery_date,
                        order_date
                    ))
                    FROM purchases p2
                    WHERE p2.supplier_id = s.id
                    AND p2.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                ) AS avg_delivery_days,
                (
                    SELECT COUNT(*)
                    FROM returns r
                    JOIN purchases p3 ON p3.id = r.purchase_id
                    WHERE p3.supplier_id = s.id
                    AND r.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                ) AS returns_last_year
            FROM suppliers s
            LEFT JOIN purchases p ON p.supplier_id = s.id AND p.tenant_id = :tenant_id
            WHERE s.tenant_id = :tenant_id AND p.created_at BETWEEN :start_date AND :end_date
            GROUP BY s.id
            ORDER BY total_spent DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59',
            ':tenant_id' => $tenantId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
