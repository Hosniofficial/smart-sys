<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MonologHandler;
use App\Services\CostingService;
use App\Services\ReturnCostingService;

/**
 * Provides tenant-scoped sales trends, summaries, and payment breakdowns.
 *
 * Queries sales, returns, and completed payments directly; CostingService and
 * ReturnCostingService provide inventory-cost figures. Routes and permissions
 * are defined in analytics.php and reports.php.
 */
class SalesAnalyticsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('analytics');
    }

    /** Aggregate monthly sales and product trends over the requested date range. */
    public function analyzeTrends(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
        }

        $filters = $request->getQueryParams();
        $where   = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"];
        $params  = [$tenantId];

        if (!empty($filters['start_date'])) {
            $where[] = 's.date >= ?';
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $where[] = 's.date <= ?';
            $params[] = $filters['end_date'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT MONTH(s.date) as month, YEAR(s.date) as year,
                   COUNT(DISTINCT s.id) as total_orders,
                   SUM(si.quantity) as total_items,
                   SUM(COALESCE(si.net_total, si.quantity * si.sale_price)) as total_revenue
            FROM sales s
            JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id
            {$whereClause}
            GROUP BY YEAR(s.date), MONTH(s.date)
            ORDER BY year DESC, month DESC
        ");
        $stmt->execute($params);
        $seasonalTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("
            SELECT p.id, p.name, DATE_FORMAT(s.date, '%Y-%m') as month,
                   SUM(si.quantity) as total_quantity,
                   AVG(COALESCE(si.net_price, si.sale_price)) as avg_price
            FROM sales s
            JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id
            JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id
            {$whereClause}
            GROUP BY p.id, p.name, month
            ORDER BY p.id, month DESC
        ");
        $stmt->execute($params);
        $productTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->successResponse($response, [
            'seasonal_trends' => $seasonalTrends,
            'product_trends'  => $productTrends,
        ], 200);
    }

    /** Summarize daily sales, returns, costs, branches, payment methods, and recent invoices. */
    public function dailySalesSummary(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $params = $request->getQueryParams();
            $date   = isset($params['date']) ? trim((string) $params['date']) : null;
            if (!$date) {
                return $this->errorResponse($response, 'date (YYYY-MM-DD) is required', 400);
            }

            [$dayStart, $dayEndExclusive] = $this->resolveTenantDateRange(
                (int) $tenantId,
                $date,
                $date,
                0
            );
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
            $branchClause = $branchId !== null ? " AND s.branch_id = {$branchId}" : '';
            $returnBranchClause = $branchId !== null ? " AND r.branch_id = {$branchId}" : '';

            $summaryDate = substr($dayStart, 0, 10);
            $posIds     = [];
            $paymentKind = null;
            $productId  = null;
            $categoryId = null;

            if (isset($params['pos_id'])) {
                $posIds = is_array($params['pos_id']) ? $params['pos_id'] : [$params['pos_id']];
            } elseif (isset($params['pos_ids'])) {
                $posIds = is_array($params['pos_ids']) ? $params['pos_ids'] : [$params['pos_ids']];
            }
            $posIds = array_values(array_filter(array_map('intval', $posIds), fn ($v) => $v > 0));

            if (!empty($params['payment_kind'])) {
                $paymentKind = strtolower(trim((string) $params['payment_kind']));
            }
            if (isset($params['product_id'])) {
                $productId  = (int) $params['product_id'];
            }
            if (isset($params['category_id'])) {
                $categoryId = (int) $params['category_id'];
            }

            $whereSales  = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')", 's.created_at >= ? AND s.created_at < ?'];
            $salesParams = [$tenantId, $dayStart, $dayEndExclusive];
            $joins       = ['JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id'];

            if ($productId) {
                $whereSales[] = 'si.product_id = ?';
                $salesParams[] = $productId;
            }
            if ($categoryId) {
                $joins[]      = 'JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id';
                $whereSales[] = 'p.category_id = ?';
                $salesParams[] = $categoryId;
            }
            if (!empty($posIds)) {
                $whereSales[] = 's.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                foreach ($posIds as $pid) {
                    $salesParams[] = $pid;
                }
            }
            if ($branchId !== null) {
                $whereSales[] = 's.branch_id = ?';
                $salesParams[] = $branchId;
            }
            if ($paymentKind) {
                $whereSales[] = "EXISTS (SELECT 1 FROM payments pmt LEFT JOIN payment_methods pm ON pm.id = pmt.payment_method_id AND pm.tenant_id = s.tenant_id WHERE pmt.sale_id = s.id AND pmt.is_draft = 0 AND pmt.status = 'completed' AND LOWER(pm.kind) = ?)";
                $salesParams[] = $paymentKind;
            }

            $sqlSales = 'SELECT COALESCE(SUM(COALESCE(si.net_total, si.quantity * si.sale_price)), 0) AS total_sales_amount, COUNT(DISTINCT s.id) AS order_count FROM sales s ' . implode(' ', $joins) . ' WHERE ' . implode(' AND ', $whereSales);
            $stmt = $this->db->prepare($sqlSales);
            $stmt->execute($salesParams);
            $salesRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalSalesAmount = (float) ($salesRow['total_sales_amount'] ?? 0);
            $orderCount = (int) ($salesRow['order_count'] ?? 0);

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(ri.quantity * ri.unit_price), 0) AS total_returns_amount FROM returns r JOIN return_items ri ON ri.return_id = r.id AND ri.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND r.return_type = 'sale' AND LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected') AND r.created_at >= ? AND r.created_at < ? {$returnBranchClause}");
            $stmt->execute([$tenantId, $dayStart, $dayEndExclusive]);
            $totalReturnsAmount = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total_returns_amount'] ?? 0);

            $cogsTotal = 0.0;
            try {
                $costing    = new CostingService($this->db);
                $whereIds   = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')", 's.created_at >= ? AND s.created_at < ?'];
                $idParams   = [$tenantId, $dayStart, $dayEndExclusive];
                $idJoins    = [];

                if ($productId) {
                    $idJoins[] = 'JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id';
                    $whereIds[] = 'si.product_id = ?';
                    $idParams[] = $productId;
                }
                if ($categoryId) {
                    if (!in_array('JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id', $idJoins)) {
                        $idJoins[] = 'JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id';
                    }
                    $idJoins[] = 'JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id';
                    $whereIds[] = 'p.category_id = ?';
                    $idParams[] = $categoryId;
                }
                if (!empty($posIds)) {
                    $whereIds[] = 's.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                    foreach ($posIds as $pid) {
                        $idParams[] = $pid;
                    }
                }
                if ($branchId !== null) {
                    $whereIds[] = 's.branch_id = ?';
                    $idParams[] = $branchId;
                }
                if ($paymentKind) {
                    $whereIds[] = "EXISTS (SELECT 1 FROM payments pmt LEFT JOIN payment_methods pm ON pm.id = pmt.payment_method_id AND pm.tenant_id = s.tenant_id WHERE pmt.sale_id = s.id AND pmt.is_draft = 0 AND pmt.status = 'completed' AND LOWER(pm.kind) = ?)";
                    $idParams[] = $paymentKind;
                }

                $sqlIds = 'SELECT s.id, s.created_at FROM sales s ' . implode(' ', $idJoins) . ' WHERE ' . implode(' AND ', $whereIds);
                $stmt   = $this->db->prepare($sqlIds);
                $stmt->execute($idParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
                    $cogsTotal += (float) $costing->computeCOGSForSale((int) $tenantId, (int) $s['id'], $s['created_at'] ?? null);
                }
                $cogsTotal = round($cogsTotal, 2);
            } catch (\Throwable $e) {
                $this->logger->warning('Error computing COGS for daily summary', [
                    'message' => $e->getMessage()
                ]);
                $cogsTotal = 0.0;
            }

            $returnsCOGS = 0.0;
            $whereReturnIds = [
                'r.tenant_id = ?',
                "r.return_type = 'sale'",
                "LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')",
                'r.created_at >= ? AND r.created_at < ?',
            ];
            $returnIdParams = [$tenantId, $dayStart, $dayEndExclusive];
            $returnJoins = [
                'JOIN return_items ri ON ri.return_id = r.id AND ri.tenant_id = r.tenant_id',
            ];

            if ($productId) {
                $whereReturnIds[] = 'ri.product_id = ?';
                $returnIdParams[] = $productId;
            }
            if ($categoryId) {
                $returnJoins[] = 'JOIN products rp ON rp.id = ri.product_id AND rp.tenant_id = ri.tenant_id';
                $whereReturnIds[] = 'rp.category_id = ?';
                $returnIdParams[] = $categoryId;
            }
            if (!empty($posIds)) {
                $whereReturnIds[] = 'r.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                array_push($returnIdParams, ...$posIds);
            }
            if ($branchId !== null) {
                $whereReturnIds[] = 'r.branch_id = ?';
                $returnIdParams[] = $branchId;
            }

            $stmt = $this->db->prepare(
                'SELECT DISTINCT r.id FROM returns r ' . implode(' ', $returnJoins)
                . ' WHERE ' . implode(' AND ', $whereReturnIds)
            );
            $stmt->execute($returnIdParams);
            $returnsCostDetails = (new ReturnCostingService($this->db))->calculateDetailsForReturns(
                (int) $tenantId,
                array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])
            );
            $returnsCOGS = $returnsCostDetails['cost_amount'];

            $netCOGS = round($cogsTotal - $returnsCOGS, 2);

            $salesByPos = [];
            $sqlByPos = "SELECT s.branch_id, b.name as branch_name, 
                         COALESCE(SUM(COALESCE(si.net_total, si.quantity * si.sale_price)), 0) AS total_sales,
                         COUNT(DISTINCT s.id) AS order_count
                         FROM sales s 
                         JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id
                         LEFT JOIN branches b ON b.id = s.branch_id AND b.tenant_id = s.tenant_id
                         WHERE s.tenant_id = ? AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled') AND s.created_at >= ? AND s.created_at < ? {$branchClause}
                         GROUP BY s.branch_id, b.name";
            $stmt = $this->db->prepare($sqlByPos);
            $stmt->execute([$tenantId, $dayStart, $dayEndExclusive]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $salesByPos[] = [
                    'pos_id' => (int) $row['branch_id'],
                    'name' => $row['branch_name'] ?: 'فرع #' . $row['branch_id'],
                    'totalSales' => (float) $row['total_sales'],
                    'orderCount' => (int) $row['order_count']
                ];
            }

            $salesByPayment = [];
            $sqlByPayment = "SELECT pm.name, pm.kind,
                             COALESCE(SUM(pmt.amount), 0) AS total_amount
                             FROM payments pmt
                             LEFT JOIN payment_methods pm ON pm.id = pmt.payment_method_id AND pm.tenant_id = pmt.tenant_id
                             JOIN sales s ON s.id = pmt.sale_id AND s.tenant_id = pmt.tenant_id
                             WHERE pmt.tenant_id = ?
                             AND pmt.is_draft = 0 
                             AND pmt.status = 'completed'
                             AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')
                             AND s.created_at >= ? AND s.created_at < ? {$branchClause}
                             GROUP BY pm.id, pm.name, pm.kind";
            $stmt = $this->db->prepare($sqlByPayment);
            $stmt->execute([$tenantId, $dayStart, $dayEndExclusive]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $salesByPayment[] = [
                    'name' => $row['name'] ?: ($row['kind'] ?: 'غير محدد'),
                    'totalSales' => (float) $row['total_amount']
                ];
            }

            $recentTransactions = [];
            $sqlRecent = "SELECT s.id, s.created_at, COALESCE(s.net_total_amount, s.total_amount) AS net_total_amount, b.name as pos_name,
                          COALESCE(pm.name, pm.kind, 'غير محدد') as payment_method,
                          s.payment_method_id
                          FROM sales s
                          LEFT JOIN branches b ON b.id = s.branch_id AND b.tenant_id = s.tenant_id
                          LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id AND pm.tenant_id = s.tenant_id
                          WHERE s.tenant_id = ? AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled') AND s.created_at >= ? AND s.created_at < ? {$branchClause}
                          ORDER BY s.created_at DESC
                          LIMIT 20";
            $stmt = $this->db->prepare($sqlRecent);
            $stmt->execute([$tenantId, $dayStart, $dayEndExclusive]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $saleId = (int) $row['id'];
                $saleDate = $row['created_at'];

                $saleCogs = 0.0;
                try {
                    $costing = new CostingService($this->db);
                    $saleCogs = (float) $costing->computeCOGSForSale($tenantId, $saleId, $saleDate);
                } catch (\Throwable $e) {
                    $this->logger->warning('Error computing COGS for individual sale', [
                        'sale_id' => $saleId,
                        'message' => $e->getMessage()
                    ]);
                    $saleCogs = 0.0;
                }

                $amount = (float) $row['net_total_amount'];
                $profit = $amount - $saleCogs;

                $recentTransactions[] = [
                    'id' => $saleId,
                    'time' => $row['created_at'],
                    'posName' => $row['pos_name'] ?: 'غير محدد',
                    'amount' => $amount,
                    'cogs' => round($saleCogs, 2),
                    'profit' => round($profit, 2),
                    'paymentMethod' => $row['payment_method']
                ];
            }

            return $this->successResponse($response, [
                'date'                 => $summaryDate,
                'total_sales_amount'   => $totalSalesAmount,
                'total_returns_amount' => $totalReturnsAmount,
                'cogs_total'           => $cogsTotal,
                'returns_cogs'         => $returnsCOGS,
                'returns_cost_unresolved_line_count' => $returnsCostDetails['unresolved_line_count'],
                'returns_cost_unresolved_quantity' => $returnsCostDetails['unresolved_quantity'],
                'returns_cost_complete' => $returnsCostDetails['unresolved_line_count'] === 0,
                'net_cogs'             => $netCOGS,
                'gross_profit'         => round(($totalSalesAmount - $totalReturnsAmount) - $netCOGS, 2),
                'order_count'          => $orderCount,
                'salesByPos'           => $salesByPos,
                'salesByPayment'       => $salesByPayment,
                'recentTransactions'   => $recentTransactions,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->logger->error('dailySalesSummary error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في حساب الملخص اليومي', 500);
        }
    }

    /** Aggregate completed payments, refunds, credit balances, and returns by type. */
    public function breakdownSalesPayments(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
            }

            $params      = $request->getQueryParams();
            $where       = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"];
            $queryParams = [$tenantId];

            $hasStartDate = !empty($params['start_date']);
            $hasEndDate = !empty($params['end_date']);
            if ($hasStartDate || $hasEndDate) {
                [$from, $toExclusive] = $this->resolveTenantDateRange(
                    (int) $tenantId,
                    $hasStartDate ? (string) $params['start_date'] : null,
                    $hasEndDate ? (string) $params['end_date'] : null
                );
                if ($hasStartDate) {
                    $where[] = 's.created_at >= ?';
                    $queryParams[] = $from;
                }
                if ($hasEndDate) {
                    $where[] = 's.created_at < ?';
                    $queryParams[] = $toExclusive;
                }
            }
            if (!empty($params['session_id'])) {
                $where[] = 's.session_id = ?';
                $queryParams[] = $params['session_id'];
            }
            if (!empty($params['branch_id'])) {
                $where[] = 's.branch_id = ?';
                $queryParams[] = $params['branch_id'];
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $invoiceOutstandingSql = \App\Services\SalesOutstandingBalance::sqlExpression();
            $stmt = $this->db->prepare("
                SELECT {$invoiceOutstandingSql} AS remaining
                FROM sales s
                {$whereSql}
                  AND LOWER(COALESCE(s.status, '')) NOT IN (
                      'draft', 'pending', 'pending_approval', 'awaiting_approval',
                      'rejected', 'canceled', 'cancelled', 'returned'
                  )
            ");
            $stmt->execute($queryParams);
            $creditSales = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Return totals use the return's date and branch, matching sales analytics.
            $returnsWhere = [
                'r.tenant_id = ?',
                "r.return_type = 'sale'",
                "LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')",
            ];
            $returnsParams = [$tenantId];
            if ($hasStartDate) {
                $returnsWhere[] = 'r.created_at >= ?';
                $returnsParams[] = $from;
            }
            if ($hasEndDate) {
                $returnsWhere[] = 'r.created_at < ?';
                $returnsParams[] = $toExclusive;
            }
            if (!empty($params['session_id'])) {
                $returnsWhere[] = 'EXISTS (
                    SELECT 1 FROM sales return_sale
                    WHERE return_sale.id = r.sale_id
                      AND return_sale.tenant_id = r.tenant_id
                      AND return_sale.session_id = ?
                )';
                $returnsParams[] = $params['session_id'];
            }
            if (!empty($params['branch_id'])) {
                $returnsWhere[] = 'r.branch_id = ?';
                $returnsParams[] = $params['branch_id'];
            }
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(r.total_amount + COALESCE(r.tax_amount, 0)), 0) AS total
                FROM returns r
                WHERE " . implode(' AND ', $returnsWhere)
            );
            $stmt->execute($returnsParams);
            $returnRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cashTotal = $bankTotal = $cardTotal = $walletTotal = $creditTotal = $returnsTotal = 0;
            foreach ($returnRows as $row) {
                $returnsTotal += (float) $row['total'];
            }

            $paymentWhere = [
                'p.tenant_id = ?',
                "p.status = 'completed'",
                'COALESCE(p.is_draft, 0) = 0',
                "(p.sale_id IS NOT NULL OR p.type IN ('debt_payment', 'customer', 'return_payment'))",
                "(p.sale_id IS NULL OR LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled'))",
            ];
            $paymentParams = [$tenantId];
            if ($hasStartDate || $hasEndDate) {
                [$from, $toExclusive] = $this->resolveTenantDateRange(
                    (int) $tenantId,
                    $hasStartDate ? (string) $params['start_date'] : null,
                    $hasEndDate ? (string) $params['end_date'] : null
                );
                if ($hasStartDate) {
                    $paymentWhere[] = 'p.created_at >= ?';
                    $paymentParams[] = $from;
                }
                if ($hasEndDate) {
                    $paymentWhere[] = 'p.created_at < ?';
                    $paymentParams[] = $toExclusive;
                }
            }
            if (!empty($params['session_id'])) {
                $paymentWhere[] = 'COALESCE(p.session_id, s.session_id) = ?';
                $paymentParams[] = $params['session_id'];
            }
            if (!empty($params['branch_id'])) {
                $paymentWhere[] = '(p.branch_id = ? OR s.branch_id = ? OR EXISTS (
                    SELECT 1 FROM customer_payment_allocations cpa
                    JOIN sales allocated_sale
                      ON allocated_sale.id = cpa.sale_id AND allocated_sale.tenant_id = cpa.tenant_id
                    WHERE cpa.tenant_id = p.tenant_id AND cpa.payment_id = p.id
                      AND allocated_sale.branch_id = ?
                ))';
                array_push($paymentParams, $params['branch_id'], $params['branch_id'], $params['branch_id']);
            }
            $stmt = $this->db->prepare("
                SELECT LOWER(COALESCE(pm.kind, 'other')) AS kind,
                       COALESCE(SUM(CASE WHEN p.type = 'return_payment' THEN -p.amount ELSE p.amount END), 0) AS total
                FROM payments p
                LEFT JOIN sales s ON s.id = p.sale_id AND s.tenant_id = p.tenant_id
                LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.tenant_id = p.tenant_id
                WHERE " . implode(' AND ', $paymentWhere) . "
                GROUP BY LOWER(COALESCE(pm.kind, 'other'))
            ");
            $stmt->execute($paymentParams);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $amount = (float) $row['total'];
                switch ((string) $row['kind']) {
                    case 'cash':
                        $cashTotal += $amount;
                        break;
                    case 'bank':
                    case 'bank_transfer':
                        $bankTotal += $amount;
                        break;
                    case 'card':
                    case 'bank_card':
                        $cardTotal += $amount;
                        break;
                    case 'wallet':
                    case 'bank_wallet':
                        $walletTotal += $amount;
                        break;
                }
            }

            foreach ($creditSales as $sale) {
                $creditTotal += max(0.0, (float) $sale);
            }

            return $this->successResponse($response, [
                'cash'         => round($cashTotal, 2),
                'bank'         => round($bankTotal, 2),
                'card'         => round($cardTotal, 2),
                'wallet'       => round($walletTotal, 2),
                'credit'       => round($creditTotal, 2),
                'returns'      => round($returnsTotal, 2),
                'cash_drawer'  => round($cashTotal, 2),
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('breakdownSalesPayments error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'حدث خطأ أثناء حساب تفاصيل المدفوعات', 400);
        }
    }

    /**
     * Build the sales report, including return-adjusted costs and payment summaries.
     *
     * Accessible via: GET /api/v1/analytics/sales
     *
     * Note: The duplicate route GET /reports/sales/summary previously pointed here
     * but was removed on 2024-10-18 — no Frontend consumer was found for that path.
     * Use /analytics/sales for all new integrations.
     */
    public function analyzeSales(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
            }

            $params  = $request->getQueryParams();
            $filters = [];

            if (!empty($params['date'])) {
                $filters['start_date'] = $params['date'];
                $filters['end_date'] = $params['date'];
            }
            if (!empty($params['start_date'])) {
                $filters['start_date'] = $params['start_date'];
            }
            if (!empty($params['end_date'])) {
                $filters['end_date']   = $params['end_date'];
            }
            if (!empty($params['product_id'])) {
                $filters['product_id'] = $params['product_id'];
            }
            if (!empty($params['category_id'])) {
                $filters['category_id'] = $params['category_id'];
            }

            $whereSales   = ["LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"];
            $whereReturns = [
                "r.return_type = 'sale'",
                "LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')",
            ];
            $queryParams  = [];
            $hasStartDate = !empty($filters['start_date']);
            $hasEndDate = !empty($filters['end_date']);
            $from = $toExclusive = null;

            if ($hasStartDate || $hasEndDate) {
                [$from, $toExclusive] = $this->resolveTenantDateRange(
                    (int) $tenantId,
                    $hasStartDate ? (string) $filters['start_date'] : null,
                    $hasEndDate ? (string) $filters['end_date'] : null
                );
                if ($hasStartDate) {
                    $whereSales[] = 's.created_at >= ?';
                    $whereReturns[] = 'r.created_at >= ?';
                    $queryParams[] = $from;
                }
                if ($hasEndDate) {
                    $whereSales[] = 's.created_at < ?';
                    $whereReturns[] = 'r.created_at < ?';
                    $queryParams[] = $toExclusive;
                }
            }
            if (!empty($filters['product_id'])) {
                $whereSales[] = 'si.product_id = ?';
                $queryParams[] = $filters['product_id'];
            }
            if (!empty($filters['category_id'])) {
                $whereSales[] = 'p.category_id = ?';
                $queryParams[] = $filters['category_id'];
            }

            $posIds = [];
            if (isset($params['branch_id'])) {
                $posIds = [$params['branch_id']];
            } elseif (isset($params['pos_id'])) {
                $posIds = is_array($params['pos_id']) ? $params['pos_id'] : [$params['pos_id']];
            } elseif (isset($params['pos_ids'])) {
                $posIds = is_array($params['pos_ids']) ? $params['pos_ids'] : [$params['pos_ids']];
            }
            $posIds = array_values(array_filter(array_map('intval', $posIds), fn ($v) => $v > 0));
            if (!empty($posIds)) {
                $whereSales[] = 's.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                $whereReturns[] = 'r.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                foreach ($posIds as $pid) {
                    $queryParams[] = $pid;
                }
            }

            $paymentKind = !empty($params['payment_kind']) ? strtolower(trim((string) $params['payment_kind'])) : null;
            if ($paymentKind) {
                $whereSales[] = "EXISTS (SELECT 1 FROM payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.tenant_id = s.tenant_id WHERE p.sale_id = s.id AND p.is_draft = 0 AND p.status = 'completed' AND LOWER(pm.kind) = ?)";
                $queryParams[] = $paymentKind;
            }

            $whereSales[]   = 's.tenant_id = ?';
            $whereReturns[] = 'r.tenant_id = ?';
            $clauseSales   = 'WHERE ' . implode(' AND ', $whereSales);
            $clauseReturns = 'WHERE ' . implode(' AND ', $whereReturns);

            $paramsSales = [];
            if ($hasStartDate) {
                $paramsSales[] = $from;
            }
            if ($hasEndDate) {
                $paramsSales[] = $toExclusive;
            }
            if (!empty($filters['product_id'])) {
                $paramsSales[] = $filters['product_id'];
            }
            if (!empty($filters['category_id'])) {
                $paramsSales[] = $filters['category_id'];
            }
            if (!empty($posIds)) {
                foreach ($posIds as $pid) {
                    $paramsSales[] = $pid;
                }
            }
            if ($paymentKind) {
                $paramsSales[] = $paymentKind;
            }
            $paramsSales[] = $tenantId;

            $paramsReturns = [];
            if ($hasStartDate) {
                $paramsReturns[] = $from;
            }
            if ($hasEndDate) {
                $paramsReturns[] = $toExclusive;
            }
            if (!empty($posIds)) {
                foreach ($posIds as $pid) {
                    $paramsReturns[] = $pid;
                }
            }
            $paramsReturns[] = $tenantId;

            $stmt = $this->db->prepare("SELECT COUNT(*) as total_sales FROM sales s {$clauseSales}");
            $stmt->execute($paramsSales);
            $totalSales = $stmt->fetch(PDO::FETCH_ASSOC)['total_sales'] ?? 0;

            $breakdownParams = [
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ];
            if (isset($params['branch_id'])) {
                $breakdownParams['branch_id'] = $params['branch_id'];
            } elseif (count($posIds) === 1) {
                $breakdownParams['branch_id'] = $posIds[0];
            }
            if (isset($params['session_id'])) {
                $breakdownParams['session_id'] = $params['session_id'];
            }
            $breakdownReq = $request->withQueryParams($breakdownParams);
            // This is an internal calculation; don't write its JSON body into the API response.
            $breakdownResp = $this->breakdownSalesPayments($breakdownReq, new \Slim\Psr7\Response());
            $breakdownData = json_decode($breakdownResp->getBody()->__toString(), true)['data'] ?? [];
            $cashInDrawer  = (float) ($breakdownData['cash_drawer'] ?? 0);

            $stmt = $this->db->prepare("SELECT DATE(s.created_at) as date, COUNT(DISTINCT s.id) as total_orders, SUM(si.quantity) as total_items, SUM(COALESCE(si.net_total, si.quantity * si.sale_price)) as total_revenue FROM sales s JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id JOIN products p ON p.id = si.product_id AND p.tenant_id = s.tenant_id {$clauseSales} GROUP BY DATE(s.created_at) ORDER BY s.created_at DESC");
            $stmt->execute($paramsSales);
            $dailySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $periodOrders = 0;
            $periodRevenue = 0.0;
            foreach ($dailySales as $row) {
                $periodOrders += (int) $row['total_orders'];
                $periodRevenue += (float) $row['total_revenue'];
            }
            $periodAvgInvoice = $periodOrders > 0 ? $periodRevenue / $periodOrders : 0.0;

            $stmt = $this->db->prepare("SELECT p.id, p.name, p.product_code, COUNT(DISTINCT s.id) as order_count, SUM(si.quantity) as total_quantity, SUM(COALESCE(si.net_total, si.quantity * si.sale_price)) as total_revenue FROM sales s JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id {$clauseSales} GROUP BY p.id, p.name, p.product_code ORDER BY total_quantity DESC LIMIT 10");
            $stmt->execute($paramsSales);
            $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("SELECT c.id, c.name, COUNT(DISTINCT s.id) as order_count, SUM(si.quantity) as total_quantity, SUM(COALESCE(si.net_total, si.quantity * si.sale_price)) as total_revenue FROM sales s JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id JOIN categories c ON c.id = p.category_id {$clauseSales} GROUP BY c.id, c.name ORDER BY total_quantity DESC");
            $stmt->execute($paramsSales);
            $topCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount)),0) AS total_sales_amount, COALESCE(SUM(s.tax_amount),0) AS total_tax_amount, COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount) + s.tax_amount),0) AS grand_total FROM sales s {$clauseSales}");
            $stmt->execute($paramsSales);
            $salesTotals       = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalSalesAmount  = (float) $salesTotals['total_sales_amount'];
            $totalTaxAmount    = (float) $salesTotals['total_tax_amount'];
            $grandTotal        = (float) $salesTotals['grand_total'];

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(r.total_amount),0) AS total_returns_amount, COALESCE(SUM(r.tax_amount),0) AS total_returns_tax, COALESCE(SUM(r.total_amount + r.tax_amount),0) AS returns_grand_total FROM returns r {$clauseReturns}");
            $stmt->execute($paramsReturns);
            $returnsTotals      = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalReturnsAmount = (float) $returnsTotals['total_returns_amount'];
            $totalReturnsTax    = (float) $returnsTotals['total_returns_tax'];
            $returnsGrandTotal  = (float) $returnsTotals['returns_grand_total'];

            $netSales      = $totalSalesAmount - $totalReturnsAmount;
            $netGrandTotal = $grandTotal - $returnsGrandTotal;

            $byPayment = [];
            $bestPaymentMethod = 'N/A';
            $pmAmounts = ['cash' => $breakdownData['cash'] ?? 0, 'bank' => $breakdownData['bank'] ?? 0, 'card' => $breakdownData['card'] ?? 0, 'wallet' => $breakdownData['wallet'] ?? 0, 'credit' => $breakdownData['credit'] ?? 0];
            $max = -1;
            foreach ($pmAmounts as $method => $amount) {
                if ($amount > 0) {
                    $byPayment[] = ['method' => ucfirst($method), 'amount' => round($amount, 2)];
                    if ($amount > $max) {
                        $max = $amount;
                        $bestPaymentMethod = ucfirst($method);
                    }
                }
            }

            $byPos = [];
            try {
                $posWhere = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"];
                $posParams = [$tenantId];
                if ($hasStartDate) {
                    $posWhere[] = 's.created_at >= ?';
                    $posParams[] = $from;
                }
                if ($hasEndDate) {
                    $posWhere[] = 's.created_at < ?';
                    $posParams[] = $toExclusive;
                }
                if (!empty($posIds)) {
                    $posWhere[] = 's.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                    array_push($posParams, ...$posIds);
                }
                $pkFilter = $paymentKind ? " AND EXISTS (SELECT 1 FROM payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.tenant_id = s.tenant_id WHERE p.sale_id = s.id AND p.is_draft = 0 AND p.status = 'completed' AND LOWER(pm.kind) = ?)" : '';
                $stmt = $this->db->prepare("SELECT s.branch_id as pos_id, b.name as pos_name, SUM(COALESCE(si.net_total, si.quantity * si.sale_price)) as amount FROM sales s LEFT JOIN branches b ON b.id = s.branch_id AND (b.tenant_id = s.tenant_id OR b.tenant_id IS NULL) JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id WHERE " . implode(' AND ', $posWhere) . $pkFilter . " GROUP BY s.branch_id, b.name");
                $stmt->execute($paymentKind ? array_merge($posParams, [$paymentKind]) : $posParams);
                $byPos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                $this->logger->warning('Error fetching sales by POS', [
                    'message' => $e->getMessage()
                ]);
                $byPos = [];
            }

            $latestTx = [];
            try {
                $ltWhere = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"];
                $ltParams = [$tenantId];
                if ($hasStartDate) {
                    $ltWhere[] = 's.created_at >= ?';
                    $ltParams[] = $from;
                }
                if ($hasEndDate) {
                    $ltWhere[] = 's.created_at < ?';
                    $ltParams[] = $toExclusive;
                }
                if (!empty($posIds)) {
                    $ltWhere[] = 's.branch_id IN (' . implode(',', array_fill(0, count($posIds), '?')) . ')';
                    array_push($ltParams, ...$posIds);
                }
                $pkExist = $paymentKind ? " AND EXISTS (SELECT 1 FROM payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.tenant_id = s.tenant_id WHERE p.sale_id = s.id AND p.is_draft = 0 AND p.status = 'completed' AND LOWER(pm.kind) = ?)" : '';
                $stmt = $this->db->prepare("SELECT s.id as sale_id, s.created_at as time, s.branch_id as pos_id, (SELECT pm.name FROM payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.tenant_id = s.tenant_id WHERE p.sale_id = s.id AND p.is_draft = 0 AND p.status = 'completed' ORDER BY p.amount DESC LIMIT 1) as payment_method, COALESCE(s.net_total_amount, s.total_amount) as amount FROM sales s WHERE " . implode(' AND ', $ltWhere) . $pkExist . " ORDER BY s.created_at DESC LIMIT 10");
                $stmt->execute($paymentKind ? array_merge($ltParams, [$paymentKind]) : $ltParams);
                $latestTx = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $costing = new CostingService($this->db);
                foreach ($latestTx as &$tx) {
                    $sid = (int) ($tx['sale_id'] ?? 0);
                    if ($sid > 0) {
                        $c = (float) $costing->computeCOGSForSale((int) $tenantId, $sid, $tx['time'] ?? null);
                        $tx['cogs']   = round($c, 2);
                        $tx['profit'] = round((float) ($tx['amount'] ?? 0) - $c, 2);
                    } else {
                        $tx['cogs'] = 0.0;
                        $tx['profit'] = (float) ($tx['amount'] ?? 0);
                    }
                }
                unset($tx);
            } catch (\Throwable $e) {
                $this->logger->warning('Error fetching latest transactions', [
                    'message' => $e->getMessage()
                ]);
                $latestTx = [];
            }

            $cogsTotal = 0.0;
            try {
                $costing  = new CostingService($this->db);
                $siJoin   = (!empty($filters['product_id']) || !empty($filters['category_id'])) ? ' JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id ' : '';
                $pJoin    = !empty($filters['category_id']) ? ' JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id ' : '';
                $stmt     = $this->db->prepare("SELECT s.id, s.created_at FROM sales s {$siJoin}{$pJoin} {$clauseSales} GROUP BY s.id, s.created_at");
                $stmt->execute($paramsSales);
                $salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($salesRows as $row) {
                    $sid = (int) $row['id'];
                    $c   = (float) $costing->computeCOGSForSale((int) $tenantId, $sid, $row['created_at'] ?? null);
                    $cogsTotal += $c;
                }
                $cogsTotal = round($cogsTotal, 2);
            } catch (\Throwable $e) {
                $this->logger->warning('Error computing COGS in analyzeSales', [
                    'message' => $e->getMessage()
                ]);
                $cogsTotal = 0.0;
            }

            // Batch return IDs so costing does not need one query per return.
            $stmt = $this->db->prepare("SELECT r.id, r.sale_id FROM returns r {$clauseReturns}");
            $stmt->execute($paramsReturns);
            $returnsRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $returnsCostDetails = (new ReturnCostingService($this->db))->calculateDetailsForReturns(
                (int) $tenantId,
                array_map('intval', array_column($returnsRows, 'id'))
            );
            $returnsCOGS = $returnsCostDetails['cost_amount'];

            $netCOGS = $cogsTotal - $returnsCOGS;

            return $this->successResponse($response, [
                'total_sales_amount'   => $totalSalesAmount,
                'total_tax_amount'     => $totalTaxAmount,
                'grand_total'          => $grandTotal,
                'total_returns_amount' => $totalReturnsAmount,
                'total_returns_tax'    => $totalReturnsTax,
                'returns_grand_total'  => $returnsGrandTotal,
                'net_sales'            => $netSales,
                'net_grand_total'      => $netGrandTotal,
                'cash_in_drawer'       => $cashInDrawer,
                'cogs_total'           => $cogsTotal,
                'returns_cogs'         => $returnsCOGS,
                'returns_cost_unresolved_line_count' => $returnsCostDetails['unresolved_line_count'],
                'returns_cost_unresolved_quantity' => $returnsCostDetails['unresolved_quantity'],
                'returns_cost_complete' => $returnsCostDetails['unresolved_line_count'] === 0,
                'net_cogs'             => $netCOGS,
                'gross_profit'         => round(($totalSalesAmount - $totalReturnsAmount) - $netCOGS, 2),
                'daily_sales'          => $dailySales,
                'top_products'         => $topProducts,
                'top_categories'       => $topCategories,
                'total_sales'          => $periodRevenue,
                'invoices_count'       => $periodOrders,
                'avg_invoice'          => $periodAvgInvoice,
                'best_payment_method'  => $bestPaymentMethod,
                'by_pos'               => $byPos,
                'by_payment'           => $byPayment,
                'latest'               => $latestTx,
                'payment_breakdown'    => [
                    'cash'         => round($breakdownData['cash']         ?? 0, 2),
                    'bank'         => round($breakdownData['bank']         ?? 0, 2),
                    'card'         => round($breakdownData['card']         ?? 0, 2),
                    'wallet'       => round($breakdownData['wallet']       ?? 0, 2),
                    'credit'       => round($breakdownData['credit']       ?? 0, 2),
                    'returns'      => round($breakdownData['returns']      ?? 0, 2),
                    'cash_drawer'  => round($breakdownData['cash_drawer']  ?? 0, 2),
                ],
            ], 200);
        } catch (\Exception $e) {
            $this->logger->error('analyzeSales error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'حدث خطأ أثناء تحليل المبيعات', 400);
        }
    }

}
