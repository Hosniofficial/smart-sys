<?php

declare(strict_types=1);

use App\Services\OwnerContributionService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Refusing owner contribution workers unless DB_HOST is local.');
    }
    if (!preg_match('/^smartsys_supplier_payment_it_[a-f0-9]{16}$/', (string) ($input['schema'] ?? ''))) {
        throw new RuntimeException('Refusing owner contribution worker for an unrecognized test schema.');
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
            throw new RuntimeException('Timed out waiting for both owner contribution workers.');
        }
        usleep(20000);
    } while (true);

    try {
        $service = new OwnerContributionService(
            $db,
            (int) $input['tenant_id'],
            (int) $input['user_id']
        );
        if (($input['action'] ?? 'record') === 'void') {
            $result = $service->voidContribution(
                (int) $input['contribution_id'],
                (int) $input['payment_method_id'],
                branchId: (int) $input['branch_id'],
                reason: 'Concurrent owner contribution liquidity test'
            );
        } else {
            $result = $service->recordContribution(
                'capital',
                (float) $input['amount'],
                (int) $input['target_account_id'],
                (int) $input['related_account_id'],
                costCenterId: (int) $input['cost_center_id'],
                idempotencyKey: (string) $input['idempotency_key']
            );
        }
        echo 'WORKER_RESULT:' . json_encode([
            'result' => $result,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        echo 'WORKER_RESULT:' . json_encode([
            'exception' => get_class($error),
            'message' => $error->getMessage(),
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    echo 'WORKER_RESULT:' . json_encode([
        'fatal' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
