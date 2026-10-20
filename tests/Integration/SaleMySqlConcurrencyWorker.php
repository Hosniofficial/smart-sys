<?php

declare(strict_types=1);

use App\Services\SaleCreationService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Refusing MySQL sales workers unless DB_HOST is local.');
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

    $barrier = $db->prepare('INSERT INTO sale_test_barrier (gate, worker) VALUES (?, ?)');
    $barrier->execute([$input['gate'], $input['worker']]);
    $deadline = microtime(true) + 20;
    do {
        $ready = $db->prepare('SELECT COUNT(*) FROM sale_test_barrier WHERE gate = ?');
        $ready->execute([$input['gate']]);
        if ((int) $ready->fetchColumn() === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for both concurrent sale workers.');
        }
        usleep(20000);
    } while (true);

    try {
        $sale = (new SaleCreationService($db, (int) $input['tenant_id'], (int) $input['user_id']))->createSale([
            'tenant_id' => (int) $input['tenant_id'],
            'branch_id' => (int) $input['branch_id'],
            'cost_center_id' => (int) $input['cost_center_id'],
            'payment_method_id' => (int) $input['payment_method_id'],
            'paid_amount' => (float) $input['sale_price'],
            'invoice_number' => $input['invoice_number'],
            'items' => [[
                'product_id' => (int) $input['product_id'],
                'unit_id' => (int) $input['unit_id'],
                'quantity' => (float) $input['quantity'],
                'sale_price' => (float) $input['sale_price'],
                'conversion_factor' => 0.01,
            ]],
        ]);
        echo 'WORKER_RESULT:' . json_encode([
            'status' => 'created',
            'sale_id' => $sale['sale_id'],
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        echo 'WORKER_RESULT:' . json_encode([
            'status' => 'rejected',
            'message' => $error->getMessage(),
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    echo 'WORKER_RESULT:' . json_encode([
        'error' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
