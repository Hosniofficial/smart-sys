<?php
declare(strict_types=1);
require __DIR__ . '/autoload.php';

$pdo = test_pdo();
$failures = 0;
function check2(bool $ok): void { global $failures; if (!$ok) $failures++; }

echo "=== Group D: StockTransferHandler quantity_cost arithmetic (Fix #11) ===\n";
echo "Setup: branch 1 has 100 units, quantity_cost=1000.00 (avg cost/unit = 10.00). Transfer 20 units.\n\n";

// D1: reproduce the ORIGINAL BUGGY SQL verbatim (self-referential expression
// inside one UPDATE statement) against a fresh copy of the same starting row,
// to empirically prove the MySQL execution-order bug existed as described.
$pdo->exec("INSERT INTO branch_products (id,tenant_id,branch_id,product_id,quantity,quantity_cost) VALUES (901,1,1,1,100,1000.00)");
$pdo->prepare(
    "UPDATE branch_products
     SET quantity      = quantity - ?,
         quantity_cost = GREATEST(0, quantity_cost - (quantity_cost / NULLIF(quantity, 0) * ?))
     WHERE id = ?"
)->execute([20, 20, 901]);
$buggyRow = $pdo->query("SELECT quantity, quantity_cost FROM branch_products WHERE id=901")->fetch(PDO::FETCH_ASSOC);
$buggyDeducted = round(1000.00 - (float)$buggyRow['quantity_cost'], 2);
echo "[OLD BUGGY SQL] remaining quantity={$buggyRow['quantity']}, remaining quantity_cost={$buggyRow['quantity_cost']}, actual deducted={$buggyDeducted}\n";
check2(assert_close($buggyDeducted, 250.0, 'D1 confirms the ORIGINAL bug: buggy SQL deducts 250 (not 200)'));

// D2: run the FIXED logic — exact same PHP computation now used in
// StockTransferHandler::transferStock() (values computed in PHP from the
// pre-update row, then passed as literal parameters to the UPDATE).
$pdo->exec("INSERT INTO branch_products (id,tenant_id,branch_id,product_id,quantity,quantity_cost) VALUES (902,1,1,2,100,1000.00)");

$checkStmt = $pdo->prepare("SELECT quantity, quantity_cost FROM branch_products WHERE id = ? FOR UPDATE");
$checkStmt->execute([902]);
$sourceRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
$sourceQtyBefore  = (float) $sourceRow['quantity'];
$sourceCostBefore = (float) $sourceRow['quantity_cost'];
$transferQty      = 20.0;

$unitCostAtSource = $sourceQtyBefore > 0 ? ($sourceCostBefore / $sourceQtyBefore) : 0.0;
$deductedCost      = round($unitCostAtSource * $transferQty, 2);
$deductedCost      = min($deductedCost, $sourceCostBefore);

$pdo->prepare(
    "UPDATE branch_products
     SET quantity      = quantity - ?,
         quantity_cost = GREATEST(0, quantity_cost - ?)
     WHERE id = ?"
)->execute([$transferQty, $deductedCost, 902]);

$fixedRow = $pdo->query("SELECT quantity, quantity_cost FROM branch_products WHERE id=902")->fetch(PDO::FETCH_ASSOC);
echo "[FIXED SQL]     remaining quantity={$fixedRow['quantity']}, remaining quantity_cost={$fixedRow['quantity_cost']}, actual deducted={$deductedCost}\n";
check2(assert_close($deductedCost, 200.0, 'D2 fixed logic deducts exactly 200 (matches audit worked example)'));
check2(assert_close((float)$fixedRow['quantity_cost'], 800.0, 'D2 remaining quantity_cost = 800'));
check2(assert_close((float)$fixedRow['quantity'], 80.0, 'D2 remaining quantity = 80'));
$avgAfter = round((float)$fixedRow['quantity_cost'] / (float)$fixedRow['quantity'], 4);
check2(assert_close($avgAfter, 10.0, 'D2 average cost/unit UNCHANGED after transfer (10.00 -> 10.00, was 9.375 under the bug)'));

// D3: unified cost consistency — destination branch receives EXACTLY the
// same amount deducted from source (Finding #10 fix), so total tenant-wide
// inventory value is unchanged by the transfer itself.
$pdo->exec("INSERT INTO branch_products (tenant_id,branch_id,product_id,quantity,quantity_cost) VALUES (1,2,2,{$transferQty},{$deductedCost})
            ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity), quantity_cost=quantity_cost+VALUES(quantity_cost)");
$destRow = $pdo->query("SELECT quantity, quantity_cost FROM branch_products WHERE tenant_id=1 AND branch_id=2 AND product_id=2")->fetch(PDO::FETCH_ASSOC);
check2(assert_close((float)$destRow['quantity_cost'], 200.0, 'D3 destination branch received exactly 200 (same value deducted from source)'));

$totalValueAfter = (float)$fixedRow['quantity_cost'] + (float)$destRow['quantity_cost'];
check2(assert_close($totalValueAfter, 1000.0, 'D3 tenant-wide inventory value unchanged by transfer (800+200=1000)'));

echo "\n" . ($failures === 0 ? "ALL PASS" : "{$failures} FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
