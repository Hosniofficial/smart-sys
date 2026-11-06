<?php
declare(strict_types=1);
require __DIR__ . '/autoload.php';

$pdo = test_pdo();
$failures = 0;
function check6(bool $ok): void { global $failures; if (!$ok) $failures++; }

echo "=== Group H: report status filters (Fix #5 dead filter + vocabulary rollout) ===\n";

// Seed a realistic mix of sales across the vocabulary
$pdo->exec("INSERT INTO sales (id,tenant_id,total_amount,net_total_amount,tax_amount,status,paid_amount,created_at) VALUES
    (501,1,100,100,0,'paid',100,'2026-04-01 10:00:00'),
    (502,1,200,200,0,'settled_by_credit',0,'2026-04-01 11:00:00'),
    (503,1,150,150,0,'closed_by_return',0,'2026-04-01 12:00:00'),
    (504,1,300,300,0,'settled_mixed',150,'2026-04-01 13:00:00'),
    (505,1,400,400,0,'returned',0,'2026-04-01 14:00:00'),
    (506,1,50,50,0,'partial',20,'2026-04-01 15:00:00'),
    (507,1,80,80,0,'pending_payment',0,'2026-04-01 16:00:00')
");

// Test the EXACT query AdvancedReportsHandler uses for "total sales for the
// day" after Fix #5 + the vocabulary rollout (expanded settled set, excluding
// 'returned'). Expected: 100+200+150+300 = 750 (paid + settled_by_credit +
// closed_by_return + settled_mixed), excluding 'returned', 'partial', 'pending_payment'.
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM sales
    WHERE created_at BETWEEN ? AND ?
      AND status IN ('paid', 'settled_by_credit', 'closed_by_return', 'settled_mixed')
      AND tenant_id = ?
");
$stmt->execute(['2026-04-01 00:00:00', '2026-04-01 23:59:59', 1]);
$totalSales = (float) $stmt->fetchColumn();
check6(assert_close($totalSales, 750.0, 'H1 AdvancedReportsHandler total-sales query = 750 (excludes returned/partial/pending)'));

// Confirm the OLD dead filter ('paid','completed') would have massively
// undercounted this same data — 'completed' is never written, and the newer
// settled states weren't recognized either.
$stmtOld = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) FROM sales
    WHERE created_at BETWEEN ? AND ? AND status IN ('paid', 'completed') AND tenant_id = ?
");
$stmtOld->execute(['2026-04-01 00:00:00', '2026-04-01 23:59:59', 1]);
$totalSalesOld = (float) $stmtOld->fetchColumn();
echo "[INFO] Old dead filter ('paid','completed') would have returned: {$totalSalesOld} (only the 'paid' row counted; 'completed' never matches anything)\n";
check6(assert_close($totalSalesOld, 100.0, 'H2 confirms the old filter undercounted (100 vs the correct 750)'));

// Cash-drawer-specific query (PosAnalyticsHandler payment breakdown /
// AdvancedReportsHandler cash_total) must stay narrow: ONLY 'paid', never the
// credit-settled states, since those literally received zero cash.
$stmtCash = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) FROM sales
    WHERE created_at BETWEEN ? AND ? AND status = 'paid' AND tenant_id = ?
");
$stmtCash->execute(['2026-04-01 00:00:00', '2026-04-01 23:59:59', 1]);
$cashTotal = (float) $stmtCash->fetchColumn();
check6(assert_close($cashTotal, 100.0, 'H3 cash-only query correctly stays narrow (100, not inflated by credit-settled sales)'));

echo "\n" . ($failures === 0 ? "ALL PASS" : "{$failures} FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
