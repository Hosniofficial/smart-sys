<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MonologHandler;

/**
 * Provides cashier-dashboard and point-of-sale performance summaries.
 *
 * Uses tenant-scoped PDO queries across sales, returns, payments, and cash
 * transactions. Date ranges are resolved by BaseHandler; analytics.php and
 * reports.php expose the corresponding endpoints.
 */
class PosAnalyticsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('analytics');
    }

    /** Build a cashier summary from sales, returns, completed payments, and cash activity. */
    public function cashierDashboardSummary(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
            }

            $params    = $request->getQueryParams();
            $sessionId = $params['session_id'] ?? null;
            // Use tenant-local dates and an exclusive end bound for date-only filters.
            [$startDate, $endDateExclusive] = $this->resolveTenantDateRange(
                (int) $tenantId,
                $params['start_date'] ?? null,
                $params['end_date'] ?? null
            );
            $endDate   = $endDateExclusive; // نهاية نصف مفتوحة: يُستخدم مع '< ?' وليس BETWEEN بعد الآن
            $branchId  = $params['branch_id']  ?? null;

            $queryParams  = [$tenantId, $startDate, $endDate];
            $branchFilter  = '';
            $sessionFilter = '';
            $activitySalesBranchFilter = '';
            $activityReturnBranchFilter = '';
            $activityCashBranchFilter = '';
            $activitySalesSessionFilter = '';
            $activityCashSessionFilter = '';
            // For returns: session_id is on the linked sale (s2), not on returns directly.
            $returnSessionFilter = '';

            if ($branchId) {
                $branchFilter = ' AND branch_id = ? ';
                $activitySalesBranchFilter = ' AND s.branch_id = ? ';
                $activityReturnBranchFilter = ' AND r.branch_id = ? ';
                $activityCashBranchFilter = ' AND ct.branch_id = ? ';
                $queryParams[] = $branchId;
            }
            if ($sessionId) {
                $sessionFilter = ' AND session_id = ? ';
                $returnSessionFilter = ' AND s2.session_id = ? ';
                $activitySalesSessionFilter = ' AND s.session_id = ? ';
                $activityCashSessionFilter = ' AND ct.session_id = ? ';
                $queryParams[] = $sessionId;
            }

            // Opening balance
            $openingBalance = 0;
            if ($sessionId) {
                $stmtBal = $this->db->prepare("SELECT opening_cash_amount AS opening_balance FROM cashier_sessions WHERE id = ? AND tenant_id = ?");
                $stmtBal->execute([$sessionId, $tenantId]);
                $openingBalance = (float) ($stmtBal->fetchColumn() ?: 0);
            }

            // Recent activities (UNION of sales + returns + cash_transactions)
            $recentActivitiesQuery = "
                (SELECT s.id, 'sale' COLLATE utf8mb4_unicode_ci as type, s.id as reference_id,
                        CAST(s.invoice_number AS CHAR) COLLATE utf8mb4_unicode_ci as reference_code,
                        (COALESCE(s.net_total_amount, s.total_amount) + COALESCE(s.tax_amount, 0)) as amount, s.created_at,
                        CONCAT('فاتورة مبيعات #', s.invoice_number) COLLATE utf8mb4_unicode_ci as description
                 FROM sales s WHERE s.tenant_id = ? AND s.created_at >= ? AND s.created_at < ?
                 {$activitySalesBranchFilter} {$activitySalesSessionFilter}
                   AND LOWER(COALESCE(s.status, '')) NOT IN (
                       'draft', 'pending', 'pending_approval', 'awaiting_approval',
                       'rejected', 'canceled', 'cancelled'
                   ))
                UNION ALL
                (SELECT r.id, 'return' COLLATE utf8mb4_unicode_ci as type, r.id as reference_id,
                        CAST(r.return_number AS CHAR) COLLATE utf8mb4_unicode_ci as reference_code,
                        r.grand_total as amount, r.created_at,
                        CONCAT('مرتجع مبيعات #', r.return_number) COLLATE utf8mb4_unicode_ci as description
                 FROM returns r LEFT JOIN sales s2 ON s2.id = r.sale_id
                 WHERE r.tenant_id = ? AND r.created_at >= ? AND r.created_at < ?
                 {$activityReturnBranchFilter} {$returnSessionFilter} AND r.return_type = 'sale' AND r.status = 'approved')
                UNION ALL
                (SELECT ct.id,
                        (CASE
                            WHEN ct.type IN ('expense', 'withdrawal', 'return_payment') THEN 'withdrawal'
                            ELSE 'deposit'
                        END) COLLATE utf8mb4_unicode_ci as type,
                        ct.id as reference_id,
                        CAST(COALESCE(
                            CASE
                                WHEN ct.reference_type = 'cash_voucher' THEN cv.reference
                                WHEN ct.reference_type = 'sale'         THEN s_ct.invoice_number
                                WHEN ct.reference_type = 'return'       THEN r_ct.return_number
                                ELSE CONCAT('TX-', ct.id)
                            END,
                            CONCAT('TX-', ct.id)
                        ) AS CHAR) COLLATE utf8mb4_unicode_ci as reference_code,
                        ct.amount, ct.created_at,
                        (CASE
                            WHEN ct.reference_type = 'return' AND r_ct.return_type = 'purchase'
                                THEN CONCAT('استرداد نقدي من المورد #', r_ct.return_number)
                            WHEN ct.reference_type = 'return' AND r_ct.return_type = 'sale'
                                THEN CONCAT('رد نقدي للعميل #', r_ct.return_number)
                            WHEN ct.type IN ('expense', 'withdrawal')
                                THEN CONCAT('سحب نقدي #', COALESCE(cv.reference, CONCAT('TX-', ct.id)))
                            ELSE CONCAT('إيداع نقدي #', COALESCE(cv.reference, CONCAT('TX-', ct.id)))
                        END) COLLATE utf8mb4_unicode_ci as description
                 FROM cash_transactions ct
                 LEFT JOIN cash_vouchers cv    ON cv.id    = ct.reference_id AND ct.reference_type = 'cash_voucher'
                 LEFT JOIN sales s_ct          ON s_ct.id  = ct.reference_id AND ct.reference_type = 'sale'
                 LEFT JOIN returns r_ct        ON r_ct.id  = ct.reference_id AND ct.reference_type = 'return'
                 WHERE ct.tenant_id = ? AND ct.created_at >= ? AND ct.created_at < ?
                 {$activityCashBranchFilter} {$activityCashSessionFilter} AND ct.status = 'completed')
                ORDER BY created_at DESC LIMIT 10
            ";

            // Build per-query params for UNION (returns uses returnSessionFilter)
            $salesParams  = [$tenantId, $startDate, $endDate];
            $returnParams = [$tenantId, $startDate, $endDate];
            $ctParams     = [$tenantId, $startDate, $endDate];

            if ($branchId) {
                $salesParams[]  = $branchId;
                $returnParams[] = $branchId;
                $ctParams[]     = $branchId;
            }
            if ($sessionId) {
                $salesParams[]  = $sessionId; // session_id on sales
                $returnParams[] = $sessionId; // s2.session_id on linked sale
                $ctParams[]     = $sessionId; // session_id on cash_transactions
            }

            $recentParams = array_merge($salesParams, $returnParams, $ctParams);
            $stmt = $this->db->prepare($recentActivitiesQuery);
            $stmt->execute($recentParams);
            $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Sales volume includes open and partially paid invoices; payment totals
            // below are derived separately from completed payment records.
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(COALESCE(net_total_amount, total_amount) + COALESCE(tax_amount,0)),0) as total_sales, COUNT(*) as sales_count FROM sales s WHERE s.tenant_id = ? AND s.created_at >= ? AND s.created_at < ? {$branchFilter} {$sessionFilter} AND LOWER(COALESCE(s.status, '')) NOT IN ('draft', 'pending', 'pending_approval', 'awaiting_approval', 'rejected', 'canceled', 'cancelled')");
            $stmt->execute($queryParams);
            $salesData = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("
                SELECT DATE_FORMAT(s.created_at, '%H:00') AS hour,
                       COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount) + COALESCE(s.tax_amount, 0)), 0) AS total_sales
                FROM sales s
                WHERE s.tenant_id = ? AND s.created_at >= ? AND s.created_at < ?
                  {$branchFilter} {$sessionFilter}
                  AND LOWER(COALESCE(s.status, '')) NOT IN (
                      'draft', 'pending', 'pending_approval', 'awaiting_approval',
                      'rejected', 'canceled', 'cancelled'
                  )
                GROUP BY DATE_FORMAT(s.created_at, '%H')
                ORDER BY DATE_FORMAT(s.created_at, '%H')
            ");
            $stmt->execute($queryParams);
            $hourlySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Total returns — نستخدم JOIN مع sales للوصول لـ session_id (جدول returns لا يملك session_id مباشرة)
            $returnsParams = [$tenantId, $startDate, $endDate];
            $returnsWhere  = "r.tenant_id = ? AND r.created_at >= ? AND r.created_at < ? AND r.return_type = 'sale'";
            if ($branchId) {
                $returnsWhere   .= " AND r.branch_id = ?";
                $returnsParams[] = $branchId;
            }
            if ($sessionId) {
                $returnsWhere   .= " AND s2.session_id = ?";
                $returnsParams[] = $sessionId;
            }
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(r.grand_total),0) as total_returns, COUNT(*) as returns_count
                 FROM returns r
                 LEFT JOIN sales s2 ON s2.id = r.sale_id
                 WHERE {$returnsWhere} AND r.status = 'approved'"
            );
            $stmt->execute($returnsParams);
            $returnsData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Payment breakdown reports actual completed receipts/refunds, not invoice
            // totals or the invoice's default payment method.
            $paymentWhere = [
                'p.tenant_id = ?',
                'p.created_at >= ?',
                'p.created_at < ?',
                "p.status = 'completed'",
                'COALESCE(p.is_draft, 0) = 0',
                "(p.sale_id IS NOT NULL OR p.type IN ('debt_payment', 'customer', 'return_payment'))",
            ];
            $paymentParams = [$tenantId, $startDate, $endDate];
            if ($branchId) {
                $paymentWhere[] = '(p.branch_id = ? OR s.branch_id = ? OR EXISTS (
                    SELECT 1
                    FROM customer_payment_allocations cpa
                    JOIN sales allocated_sale
                      ON allocated_sale.id = cpa.sale_id AND allocated_sale.tenant_id = cpa.tenant_id
                    WHERE cpa.tenant_id = p.tenant_id AND cpa.payment_id = p.id
                      AND allocated_sale.branch_id = ?
                ))';
                array_push($paymentParams, $branchId, $branchId, $branchId);
            }
            if ($sessionId) {
                $paymentWhere[] = 'COALESCE(p.session_id, s.session_id) = ?';
                $paymentParams[] = $sessionId;
            }
            $stmt = $this->db->prepare("
                SELECT LOWER(COALESCE(pm.kind, 'other')) AS kind,
                       COALESCE(SUM(CASE WHEN p.type = 'return_payment' THEN -p.amount ELSE p.amount END), 0) AS total_amount
                FROM payments p
                LEFT JOIN sales s ON s.id = p.sale_id AND s.tenant_id = p.tenant_id
                LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id AND pm.tenant_id = p.tenant_id
                WHERE " . implode(' AND ', $paymentWhere) . "
                GROUP BY LOWER(COALESCE(pm.kind, 'other'))
            ");
            $stmt->execute($paymentParams);
            $paymentBreakdown = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $kind = (string) $row['kind'];
                $paymentBreakdown[$kind . '_total'] = (float) $row['total_amount'];
            }

            $invoiceOutstandingSql = \App\Services\SalesOutstandingBalance::sqlExpression();
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(CASE WHEN {$invoiceOutstandingSql} > 0
                    THEN {$invoiceOutstandingSql} ELSE 0 END), 0)
                FROM sales s
                WHERE s.tenant_id = ? AND s.created_at >= ? AND s.created_at < ?
                  {$branchFilter} {$sessionFilter}
                  AND LOWER(COALESCE(s.status, '')) NOT IN (
                      'draft', 'pending', 'pending_approval', 'awaiting_approval',
                      'rejected', 'canceled', 'cancelled', 'returned'
                  )
            ");
            $stmt->execute($queryParams);
            $paymentBreakdown['credit_total'] = (float) $stmt->fetchColumn();

            // Expenses
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) as total_expenses FROM cash_transactions WHERE tenant_id = ? AND created_at >= ? AND created_at < ? {$branchFilter} {$sessionFilter} AND type IN ('expense', 'withdrawal') AND status = 'completed'");
            $stmt->execute($queryParams);
            $expensesTotal = (float) $stmt->fetchColumn();

            // Only physical cash contributes to the drawer; bank/card/wallet are separate settlements.
            $paymentBreakdown['cash_drawer'] = (float) ($paymentBreakdown['cash_total'] ?? 0);

            return $this->successResponse($response, [
                'openingBalance'   => $openingBalance,
                'totalSales'       => (float) $salesData['total_sales'],
                'salesCount'       => (int) $salesData['sales_count'],
                'hourly_sales'     => $hourlySales,
                'totalReturns'     => (float) $returnsData['total_returns'],
                'returnsCount'     => (int) $returnsData['returns_count'],
                'expenses'         => $expensesTotal,
                'paymentBreakdown' => $paymentBreakdown,
                'recentActivities' => $recentActivities,
            ], 200);
        } catch (\Exception $e) {
            $this->logger->error('cashierDashboardSummary error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب ملخص لوحة تحكم الكاشير', 400);
        }
    }

    /** Aggregate non-cancelled sale items by branch for the requested date range. */
    public function listPos(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $params = $request->getQueryParams();
            $where  = ['s.tenant_id = ?', "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"];
            $qp     = [$tenantId];

            if (!empty($params['start_date'])) {
                $where[] = 's.created_at >= ?';
                $qp[] = $params['start_date'];
            }
            if (!empty($params['end_date'])) {
                $where[] = 's.created_at <= ?';
                $qp[] = $params['end_date'] . ' 23:59:59';
            }

            $sql = "SELECT s.branch_id AS pos_id, b.name AS pos_name,
                           COUNT(DISTINCT s.id) as orders,
                           COALESCE(SUM(COALESCE(si.net_total, si.quantity * si.sale_price)), 0) as amount
                    FROM sales s
                    LEFT JOIN branches b ON b.id = s.branch_id AND (b.tenant_id = s.tenant_id OR b.tenant_id IS NULL)
                    JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY s.branch_id, b.name
                    ORDER BY amount DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($qp);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 200);
        } catch (\Throwable $e) {
            $this->logger->error('listPos error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب قائمة نقاط البيع', 500);
        }
    }

    /** Aggregate cashier sales and returns with optional branch/session filters. */
    public function getPosPerformance(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $params    = $request->getQueryParams();
            [$from, $toExclusive] = $this->resolveTenantDateRange(
                (int) $tenantId,
                $params['start_date'] ?? null,
                $params['end_date'] ?? null,
                7
            );
            $startDate = substr($from, 0, 10);
            $endDate = date('Y-m-d', strtotime($toExclusive . ' -1 day'));
            $where = [
                's.tenant_id = ?',
                "LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')",
                's.created_at >= ?',
                's.created_at < ?',
            ];
            $queryParams = [$tenantId, $from, $toExclusive];

            foreach (['branch_id' => 's.branch_id', 'session_id' => 's.session_id'] as $filterKey => $column) {
                if (!array_key_exists($filterKey, $params)) {
                    continue;
                }

                $filterValue = filter_var($params[$filterKey], FILTER_VALIDATE_INT);
                if ($filterValue === false || $filterValue < 1) {
                    return $this->errorResponse($response, 'معرف الفرع أو الجلسة غير صالح.', 400);
                }

                $where[] = $column . ' = ?';
                $queryParams[] = $filterValue;
            }

            $sql = "
                SELECT s.user_id, u.name as cashier_name, u.username as user_name,
                       COUNT(DISTINCT s.id) as orders_count,
                       COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount)), 0) as total_sales,
                       COALESCE(SUM(s.total_amount), 0) as gross_sales,
                       COALESCE(SUM(COALESCE(s.net_total_amount, s.total_amount)), 0)
                           - COALESCE(SUM(sale_returns.total_returns), 0) as net_sales,
                       COALESCE(AVG(COALESCE(s.net_total_amount, s.total_amount)), 0) as avg_order_value
                FROM sales s
                LEFT JOIN users u ON u.id = s.user_id AND u.tenant_id = s.tenant_id
                LEFT JOIN (
                    SELECT r.tenant_id, r.sale_id, SUM(r.total_amount) AS total_returns
                    FROM returns r
                    WHERE r.tenant_id = ?
                      AND r.return_type = 'sale'
                      AND LOWER(COALESCE(r.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')
                      AND r.created_at >= ?
                      AND r.created_at < ?
                    GROUP BY r.tenant_id, r.sale_id
                ) sale_returns ON sale_returns.tenant_id = s.tenant_id
                              AND sale_returns.sale_id = s.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY s.user_id, u.name, u.username
                ORDER BY total_sales DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$tenantId, $from, $toExclusive], $queryParams));
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $posPerformance = array_map(fn ($row) => [
                'user_id'         => (int)   $row['user_id'],
                'cashier_name'    => $row['cashier_name'] ?? 'غير معروف',
                'user_name'       => $row['user_name']    ?? 'unknown',
                'device_name'     => 'POS-' . str_pad((string) ($row['user_id'] ?? '0'), 3, '0', STR_PAD_LEFT),
                'orders_count'    => (int)   $row['orders_count'],
                'total_sales'     => (float) $row['total_sales'],
                'gross_sales' => (float) $row['gross_sales'],
                'net_sales'       => (float) $row['net_sales'],
                'avg_order_value' => (float) $row['avg_order_value'],
            ], $results);

            return $this->successResponse($response, [
                'data'   => $posPerformance,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('getPosPerformance error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'حدث خطأ أثناء جلب أداء نقاط البيع', 400);
        }
    }
}
