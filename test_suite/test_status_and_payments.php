<?php
declare(strict_types=1);
require __DIR__ . '/autoload.php';

use App\Services\AccountingService;
use App\Services\SalePaymentService;

$pdo = test_pdo();
$failures = 0;
function check(bool $ok): void { global $failures; if (!$ok) $failures++; }

echo "=== Group A: computeSaleStatus() / recalculateSaleStatus() vocabulary (Finding 15-A) ===\n";

// A1: settled entirely by EXTERNAL return credit (no direct return on this sale) -> settled_by_credit
$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount) VALUES (101,1,1000,0,'pending_payment',0)");
$pdo->exec("INSERT INTO return_credit_allocations (tenant_id,sale_id,allocated_amount) VALUES (1,101,1000)");
$acc = new AccountingService($pdo);
$r1 = $acc->recalculateSaleStatus(1, 101);
check(assert_eq($r1['status'], 'settled_by_credit', 'A1 settled_by_credit (external credit, no cash)'));
check(assert_close($r1['paid_amount'], 0.0, 'A1 paid_amount stays 0 (credit != cash)'));

// A2: settled by a DIRECT return on this same sale -> closed_by_return
$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount) VALUES (102,1,500,0,'pending_payment',0)");
$pdo->exec("INSERT INTO returns (id,tenant_id,sale_id,return_type,grand_total) VALUES (1,1,102,'sale',300)");
$pdo->exec("INSERT INTO return_credit_allocations (tenant_id,return_id,sale_id,allocated_amount) VALUES (1,1,102,500)");
$r2 = $acc->recalculateSaleStatus(1, 102);
check(assert_eq($r2['status'], 'closed_by_return', 'A2 closed_by_return (direct return on same sale)'));

// A3: fully returned (return grand_total >= sale grand_total) -> returned, regardless of payments
$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount) VALUES (103,1,200,0,'pending_payment',0)");
$pdo->exec("INSERT INTO returns (id,tenant_id,sale_id,return_type,grand_total) VALUES (2,1,103,'sale',200)");
$r3 = $acc->recalculateSaleStatus(1, 103);
check(assert_eq($r3['status'], 'returned', 'A3 returned (full return)'));

// A4: mixed settlement — some cash + some external return credit -> settled_mixed
$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount) VALUES (104,1,1000,0,'pending_payment',0)");
$pdo->exec("INSERT INTO payments (tenant_id,sale_id,amount,payment_date,payment_method_id,status,is_draft,type) VALUES (1,104,400,'2026-01-10 10:00:00',1,'completed',0,'sale')");
$pdo->exec("INSERT INTO return_credit_allocations (tenant_id,sale_id,allocated_amount) VALUES (1,104,600)");
$r4 = $acc->recalculateSaleStatus(1, 104);
check(assert_eq($r4['status'], 'settled_mixed', 'A4 settled_mixed (cash + credit)'));
check(assert_close($r4['paid_amount'], 400.0, 'A4 paid_amount = cash portion only (400)'));

// A5: refund netting — a completed cash payment of 500, then a type='return_payment' refund of 200
// paid_amount should net to 300, matching SalesHandler::determineSaleStatus()'s $actualPaid formula.
$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount) VALUES (105,1,500,0,'pending_payment',0)");
$pdo->exec("INSERT INTO payments (tenant_id,sale_id,amount,payment_date,payment_method_id,status,is_draft,type) VALUES (1,105,500,'2026-01-10 10:00:00',1,'completed',0,'sale')");
$pdo->exec("INSERT INTO payments (tenant_id,sale_id,amount,payment_date,payment_method_id,status,is_draft,type) VALUES (1,105,200,'2026-01-11 10:00:00',1,'completed',0,'return_payment')");
$r5 = $acc->recalculateSaleStatus(1, 105);
check(assert_close($r5['paid_amount'], 300.0, 'A5 paid_amount nets cash refund (500-200=300)'));
check(assert_eq($r5['status'], 'partial', 'A5 status=partial after net refund (300 of 500)'));

echo "\n=== Group B: duplicate-payment guard on retry (Fix #1) ===\n";

$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount,branch_id) VALUES (201,1,1000,0,'pending_payment',0,1)");
$svc = new SalePaymentService($pdo, 1 /* userId */);

$res1 = $svc->addSalePayment(1, 201, 500.0, '2026-01-15 09:00:00', 1, null, 1, null, null);
check(!isset($res1['duplicate_retry']) || $res1['duplicate_retry'] === false ? true : (function () { echo "  (first call unexpectedly flagged as duplicate)\n"; return false; })());

// Exact retry: same tenant/sale/amount/date/method/user, NO explicit idempotency key
// -> should hit the internal hash-based fallback key and be detected as a duplicate.
$res2 = $svc->addSalePayment(1, 201, 500.0, '2026-01-15 09:00:00', 1, null, 1, null, null);
check(($res2['duplicate_retry'] ?? false) === true);
echo ($res2['duplicate_retry'] ?? false ? "[PASS]" : "[FAIL]") . " B: second identical call flagged duplicate_retry=true\n";

$paymentsCount = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE sale_id=201")->fetchColumn();
$paidAmount    = (float) $pdo->query("SELECT paid_amount FROM sales WHERE id=201")->fetchColumn();
$jeCount       = (int) $pdo->query("
    SELECT COUNT(*)
    FROM journal_entries je
    JOIN payments p ON p.journal_entry_id = je.id
    WHERE je.reference_type = 'customer_payment' AND p.sale_id = 201
")->fetchColumn();
check(assert_eq((string)$paymentsCount, '1', 'B payments row count stays 1 (not duplicated)'));
check(assert_close($paidAmount, 500.0, 'B sales.paid_amount stays 500 (not doubled to 1000)'));
check(assert_eq((string)$jeCount, '1', 'B journal_entries count stays 1'));

echo "\n=== Group C: genuinely different payments must NOT be merged (Fix 15-B) ===\n";

$pdo->exec("INSERT INTO sales (id,tenant_id,net_total_amount,tax_amount,status,paid_amount,branch_id) VALUES (202,1,1000,0,'pending_payment',0,1)");
// Same date/amount/method/user (the exact collision scenario from Finding 15-B) but
// DIFFERENT explicit Idempotency-Key each — must both be recorded, not merged.
$c1 = $svc->addSalePayment(1, 202, 300.0, '2026-01-20 10:00:00', 1, null, 1, null, 'idem-key-AAA');
$c2 = $svc->addSalePayment(1, 202, 300.0, '2026-01-20 10:00:00', 1, null, 1, null, 'idem-key-BBB');
check(($c1['duplicate_retry'] ?? false) === false || !isset($c1['duplicate_retry']));
check(($c2['duplicate_retry'] ?? false) === false || !isset($c2['duplicate_retry']));

$paymentsCount2 = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE sale_id=202")->fetchColumn();
$paidAmount2    = (float) $pdo->query("SELECT paid_amount FROM sales WHERE id=202")->fetchColumn();
check(assert_eq((string)$paymentsCount2, '2', 'C payments row count = 2 (two genuine payments)'));
check(assert_close($paidAmount2, 600.0, 'C sales.paid_amount = 600 (300+300, not merged)'));
$salePaymentJournals2 = (int) $pdo->query("
    SELECT COUNT(*)
    FROM journal_entries je
    JOIN payments p ON p.journal_entry_id = je.id
    WHERE je.reference_type = 'customer_payment' AND p.sale_id = 202
")->fetchColumn();
check(assert_eq((string)$salePaymentJournals2, '2', 'C each genuine payment has its own journal entry'));

echo "\n" . ($failures === 0 ? "ALL PASS" : "{$failures} FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
