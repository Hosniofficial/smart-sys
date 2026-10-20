<?php

declare(strict_types=1);

use App\Services\PurchaseService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Refusing invoice number workers unless DB_HOST is local.');
    }
    if (!preg_match('/^smartsys_supplier_payment_it_[a-f0-9]{16}$/', (string) ($input['schema'] ?? ''))) {
        throw new RuntimeException('Refusing invoice number worker for an unrecognized test schema.');
    }

    $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
    $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
    $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$input['schema']};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $barrier = $db->prepare(
        'INSERT INTO supplier_payment_test_barrier (gate, worker) VALUES (?, ?)'
    );
    $barrier->execute([$input['barrier'], $input['worker']]);
    $deadline = microtime(true) + 20;
    do {
        $ready = $db->prepare('SELECT COUNT(*) FROM supplier_payment_test_barrier WHERE gate = ?');
        $ready->execute([$input['barrier']]);
        if ((int) $ready->fetchColumn() === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for both invoice number workers.');
        }
        usleep(20000);
    } while (true);

    $db->beginTransaction();
    $invoiceDate = (string) $input['invoice_date'];
    $invoiceNumber = (new PurchaseService($db, (int) $input['tenant_id']))
        ->generateInvoiceNumber($invoiceDate);
    $db->prepare(
        "INSERT INTO purchases (
            tenant_id, supplier_id, invoice_number, invoice_date, total_amount,
            paid_amount, total_items, status, branch_id
         ) VALUES (?, ?, ?, ?, 0, 0, 0, 'due', ?)"
    )->execute([
        (int) $input['tenant_id'],
        (int) $input['supplier_id'],
        $invoiceNumber,
        $invoiceDate,
        (int) $input['branch_id'],
    ]);
    $db->commit();

    echo 'WORKER_RESULT:' . json_encode([
        'invoice_number' => $invoiceNumber,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    echo 'WORKER_RESULT:' . json_encode([
        'error' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
