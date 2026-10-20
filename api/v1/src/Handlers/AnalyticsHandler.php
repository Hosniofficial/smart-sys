<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use DateTime;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\CostingService;
use App\Services\MonologHandler;

/**
 * Serves supplier, customer, financial, and aggregate analytics for a tenant.
 *
 * Uses PDO for report queries and costing services for sales and return COGS;
 * routes apply report permissions in app/routes/analytics.php.
 */
class AnalyticsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('analytics');
    }

    /**
     * Analyze supplier-related metrics and performance.
     *
     * Part of the comprehensive analytics aggregation method (getAnalytics),
     * this helper provides supplier-focused analytics including:
     * - Purchase orders and spend
     * - Supplier reliability metrics
     * - Delivery performance
     *
     * Called from:
     * - AnalyticsHandler::getAnalytics() [Line 320]
     *
     * @internal Component of getAnalytics() - handles supplier aggregation
     *
     * @param Request $request HTTP request with optional filters:
     *     - start_date: Optional order date start (YYYY-MM-DD)
     *     - end_date: Optional order date end (YYYY-MM-DD)
     * @param Response $response HTTP response object for formatting
     *
     * @return Response JSON response with supplier analytics:
     *     {
     *         "status": "success",
     *         "data": [
     *             {
     *                 "id": 1,
     *                 "name": "Supplier Name",
     *                 "total_orders": 15,
     *                 "total_spent": 50000,
     *                 "last_order_date": "2024-10-18"
     *             },
     *             ...
     *         ]
     *     }
     *
     * @see getAnalytics() The main analytics aggregation method
     * @see analyzeCustomers() Related customer analytics
     * @see analyzeFinancials() Financial aggregation helper
     */
    public function analyzeSuppliers(Request $request, Response $response)
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
        }

        $queryParams = $request->getQueryParams();
        $where = ["po.tenant_id = ?"];
        $params = [$tenantId];

        if (!empty($queryParams['start_date'])) {
            $where[] = "po.order_date >= ?";
            $params[] = $queryParams['start_date'];
        }

        if (!empty($queryParams['end_date'])) {
            $where[] = "po.order_date <= ?";
            $params[] = $queryParams['end_date'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->db->prepare("
        SELECT 
            s.id,
            s.name,
            COUNT(po.id) as total_orders,
            SUM(po.total_amount) as total_spent,
            MAX(po.order_date) as last_order_date
        FROM suppliers s
        JOIN purchase_orders po ON po.supplier_id = s.id
        $whereClause
        GROUP BY s.id, s.name
        ORDER BY total_spent DESC
    ");

        $stmt->execute($params);
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->successResponse($response, $suppliers, 200);
    }

    /**
     * Analyze customer-related metrics and purchase behavior.
     *
     * Part of getAnalytics(), this helper provides customer analytics:
     * - Customer segmentation
     * - Purchase frequency and value
     * - Customer lifetime value (CLV)
     * - Preferred product categories
     *
     * Called from:
     * - AnalyticsHandler::getAnalytics() [Line 321]
     *
     * @internal Component of getAnalytics() - handles customer aggregation
     *
     * @param Request $request HTTP request with optional filters:
     *     - start_date: Optional purchase date start (YYYY-MM-DD)
     *     - end_date: Optional purchase date end (YYYY-MM-DD)
     * @param Response $response HTTP response object
     *
     * @return Response JSON response with customer analytics
     *
     * @see getAnalytics() Main method
     * @see analyzeSuppliers() Related supplier analysis
     * @see analyzeFinancials() Financial metrics
     */
    public function analyzeCustomers(Request $request, Response $response)
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
        }

        $filters = $request->getQueryParams();

        $where = ["c.tenant_id = ?"];
        $params = [$tenantId];

        if (!empty($filters['start_date'])) {
            $where[] = "o.date >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "o.date <= ?";
            $params[] = $filters['end_date'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->db->prepare("
        SELECT 
            c.id,
            c.name,
            c.email,
            COUNT(DISTINCT o.id) as total_orders,
            SUM(o.total_amount) as total_spent,
            AVG(o.total_amount) as avg_order_value,
            MAX(o.date) as last_order_date,
            (
                SELECT GROUP_CONCAT(DISTINCT p.category_id)
                FROM sales o2
                JOIN sales_items oi ON oi.sale_id = o2.id AND oi.tenant_id = o2.tenant_id
                JOIN products p ON p.id = oi.product_id AND p.tenant_id = oi.tenant_id
                WHERE o2.customer_id = c.id AND o2.tenant_id = ?
            ) as preferred_categories
        FROM customers c
        LEFT JOIN sales o ON o.customer_id = c.id
        $whereClause
        GROUP BY c.id, c.name, c.email
        ORDER BY total_spent DESC
    ");

        $params[] = $tenantId;

        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->successResponse($response, $customers, 200);
    }

    /**
     * Summarize financial metrics: sales, returns, COGS by month and day.
     *
     * Part of getAnalytics(), this helper aggregates financial data:
     * - Monthly and daily sales totals
     * - Return totals and trends
     * - Cost of Goods Sold (COGS) analysis
     * - Profit margins and KPIs
     *
     * Called from:
     * - AnalyticsHandler::getAnalytics() [Line 322]
     *
     * @internal Component of getAnalytics() aggregation
     *
     * @param Request $request HTTP request with period filters
     * @param Response $response HTTP response object
     *
     * @return Response JSON with financial metrics and KPIs
     *
     * @throws InvalidArgumentException If date parameters are invalid
     * @throws Throwable On database or calculation errors
     *
     * @see getAnalytics() The aggregating method
     * @see analyzeSuppliers() Related analytics
     * @see analyzeCustomers() Related customer analytics
     */
    public function analyzeFinancials(Request $request, Response $response)
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
        }

        $filters = $request->getQueryParams();
        try {
            [$from, $toExclusive] = $this->resolveTenantDateRange(
                (int) $tenantId,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        }
        $branchId = null;
        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $branchId = filter_var($filters['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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

        try {
            $salesWhere = [
                's.tenant_id = ?',
                's.created_at >= ?',
                's.created_at < ?',
                "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')",
            ];
            $salesParams = [$tenantId, $from, $toExclusive];
            if ($branchId !== null) {
                $salesWhere[] = 's.branch_id = ?';
                $salesParams[] = $branchId;
            }

            $stmt = $this->db->prepare(
                'SELECT s.id, s.created_at, s.total_amount FROM sales s WHERE '
                . implode(' AND ', $salesWhere)
            );
            $stmt->execute($salesParams);
            $salesByMonth = [];
            $dailySales = [];
            $costing = new CostingService($this->db);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sale) {
                $month = substr((string) $sale['created_at'], 0, 7);
                $day = substr((string) $sale['created_at'], 0, 10);
                $salesByMonth[$month]['total_sales'] = ($salesByMonth[$month]['total_sales'] ?? 0.0)
                    + (float) $sale['total_amount'];
                $salesByMonth[$month]['cogs_total'] = ($salesByMonth[$month]['cogs_total'] ?? 0.0)
                    + $costing->computeCOGSForSale($tenantId, (int) $sale['id'], $sale['created_at']);
                $dailySales[$day] = ($dailySales[$day] ?? 0.0) + (float) $sale['total_amount'];
            }

            $returnsWhere = [
                'r.tenant_id = ?',
                "r.return_type = 'sale'",
                "LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')",
                'r.created_at >= ?',
                'r.created_at < ?',
            ];
            $returnsParams = [$tenantId, $from, $toExclusive];
            if ($branchId !== null) {
                $returnsWhere[] = 'r.branch_id = ?';
                $returnsParams[] = $branchId;
            }

            $stmt = $this->db->prepare(
                'SELECT r.id, r.created_at, r.total_amount FROM returns r WHERE '
                . implode(' AND ', $returnsWhere)
            );
            $stmt->execute($returnsParams);
            $returnsByMonth = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $return) {
                $month = substr((string) $return['created_at'], 0, 7);
                $day = substr((string) $return['created_at'], 0, 10);
                $returnsByMonth[$month]['total_returns'] = ($returnsByMonth[$month]['total_returns'] ?? 0.0)
                    + (float) $return['total_amount'];
                $returnsByMonth[$month]['return_ids'][] = (int) $return['id'];
                $dailySales[$day] = ($dailySales[$day] ?? 0.0) - (float) $return['total_amount'];
            }

            $months = array_unique(array_merge(array_keys($salesByMonth), array_keys($returnsByMonth)));
            rsort($months);
            $financials = [];
            $totals = [
                'total_sales' => 0.0,
                'total_returns' => 0.0,
                'cogs_total' => 0.0,
                'returns_cogs' => 0.0,
                'returns_cost_unresolved_line_count' => 0,
                'returns_cost_unresolved_quantity' => 0.0,
                'net_sales' => 0.0,
                'net_cogs' => 0.0,
                'gross_profit' => 0.0,
            ];
            $returnCosting = new \App\Services\ReturnCostingService($this->db);

            foreach ($months as $month) {
                $monthSales = $salesByMonth[$month]['total_sales'] ?? 0.0;
                $monthReturns = $returnsByMonth[$month]['total_returns'] ?? 0.0;
                $cogsTotal = $salesByMonth[$month]['cogs_total'] ?? 0.0;
                $returnsCostDetails = $returnCosting->calculateDetailsForReturns(
                    $tenantId,
                    $returnsByMonth[$month]['return_ids'] ?? []
                );
                $returnsCOGS = $returnsCostDetails['cost_amount'];
                $netSales = $monthSales - $monthReturns;
                $netCOGS = $cogsTotal - $returnsCOGS;
                $grossProfit = $netSales - $netCOGS;
                $financials[] = [
                    'month' => $month,
                    'total_sales' => round($monthSales, 2),
                    'total_returns' => round($monthReturns, 2),
                    'net_sales' => round($netSales, 2),
                    'total_costs' => round($netCOGS, 2),
                    'cogs_total' => round($cogsTotal, 2),
                    'returns_cogs' => $returnsCOGS,
                    'returns_cost_unresolved_line_count' => $returnsCostDetails['unresolved_line_count'],
                    'returns_cost_unresolved_quantity' => $returnsCostDetails['unresolved_quantity'],
                    'returns_cost_complete' => $returnsCostDetails['unresolved_line_count'] === 0,
                    'net_cogs' => round($netCOGS, 2),
                    'gross_profit' => round($grossProfit, 2),
                    'profit_margin' => $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0,
                ];
                $totals['total_sales'] += $monthSales;
                $totals['total_returns'] += $monthReturns;
                $totals['cogs_total'] += $cogsTotal;
                $totals['returns_cogs'] += $returnsCOGS;
                $totals['returns_cost_unresolved_line_count'] += $returnsCostDetails['unresolved_line_count'];
                $totals['returns_cost_unresolved_quantity'] += $returnsCostDetails['unresolved_quantity'];
                $totals['net_sales'] += $netSales;
                $totals['net_cogs'] += $netCOGS;
                $totals['gross_profit'] += $grossProfit;
            }

            $netSales = $totals['net_sales'];
            $grossProfit = $totals['gross_profit'];
            $netCOGS = $totals['net_cogs'];
            $kpis = [
                'total_revenue' => round($netSales, 2),
                'total_sales' => round($totals['total_sales'], 2),
                'total_returns' => round($totals['total_returns'], 2),
                'total_costs' => round($netCOGS, 2),
                'cogs_total' => round($totals['cogs_total'], 2),
                'returns_cogs' => round($totals['returns_cogs'], 2),
                'returns_cost_unresolved_line_count' => $totals['returns_cost_unresolved_line_count'],
                'returns_cost_unresolved_quantity' => round($totals['returns_cost_unresolved_quantity'], 4),
                'returns_cost_complete' => $totals['returns_cost_unresolved_line_count'] === 0,
                'net_cogs' => round($netCOGS, 2),
                'gross_profit' => round($grossProfit, 2),
                'profit_margin' => $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0,
                'avg_daily_sales' => $dailySales === [] ? 0.0 : round(array_sum($dailySales) / count($dailySales), 2),
            ];

            return $this->successResponse($response, [
                'status' => 'success',
                'monthly_financials' => $financials,
                'kpis' => $kpis,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('analyzeFinancials error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحليل البيانات المالية', 500);
        }
    }

    /**
     * Combine all specialized analytics reports into one comprehensive response.
     *
     * Aggregates sales, inventory, supplier, customer, financial, and trend analysis
     * by calling individual handler methods and merging results.
     *
     * @param Request $request HTTP request with optional filters
     * @param Response $response HTTP response object
     *
     * @return Response JSON with all analytics categories:
     *     {
     *         "status": "success",
     *         "data": {
     *             "sales": {...},
     *             "inventory": {...},
     *             "suppliers": {...},
     *             "customers": {...},
     *             "financials": {...},
     *             "trends": {...}
     *         }
     *     }
     *
     * @see analyzeSales() Sales metrics
     * @see analyzeInventory() Inventory metrics
     * @see analyzeSuppliers() Supplier metrics
     * @see analyzeCustomers() Customer metrics
     * @see analyzeFinancials() Financial metrics
     * @see analyzeTrends() Trend analysis
     *
     * @api Main analytics aggregation endpoint
     */
    public function getAnalytics(Request $request, Response $response): Response
    {
        $salesH     = new SalesAnalyticsHandler($this->db);
        $inventoryH = new InventoryAnalyticsHandler($this->db);
        $trendsH    = new SalesAnalyticsHandler($this->db);

        $extractData = function (Response $resp): array {
            $body    = (string) $resp->getBody();
            $decoded = json_decode($body, true);
            return ($decoded['data'] ?? $decoded) ?: [];
        };

        $salesData      = $extractData($salesH->analyzeSales($request, $response));
        $inventoryData  = $extractData($inventoryH->analyzeInventory($request, $response));
        $suppliersData  = $extractData($this->analyzeSuppliers($request, $response));
        $customersData  = $extractData($this->analyzeCustomers($request, $response));
        $financialsData = $extractData($this->analyzeFinancials($request, $response));
        $trendsData     = $extractData($trendsH->analyzeTrends($request, $response));

        return $this->successResponse($response, [
            'sales'      => $salesData,
            'inventory'  => $inventoryData,
            'suppliers'  => $suppliersData,
            'customers'  => $customersData,
            'financials' => $financialsData,
            'trends'     => $trendsData,
        ], 200);
    }
}
