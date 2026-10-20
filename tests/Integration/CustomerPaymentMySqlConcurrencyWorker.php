<?php

declare(strict_types=1);

use App\Handlers\CustomersHandler;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Refusing customer payment concurrency workers unless DB_HOST is local.');
    }
    if (!preg_match('/^smartsys_sales_it_[a-f0-9]{16}$/', (string) ($input['schema'] ?? ''))) {
        throw new RuntimeException('Refusing customer payment worker for an unrecognized test schema.');
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
        'INSERT INTO customer_payment_test_barrier (gate, worker) VALUES (?, ?)'
    );
    $barrier->execute([$input['gate'], $input['worker']]);
    $deadline = microtime(true) + 20;
    do {
        $ready = $db->prepare('SELECT COUNT(*) FROM customer_payment_test_barrier WHERE gate = ?');
        $ready->execute([$input['gate']]);
        if ((int) $ready->fetchColumn() === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for both customer payment workers.');
        }
        usleep(20000);
    } while (true);

    $container = new Container();
    $container->set(PDO::class, $db);
    $container->set('db', $db);
    $container->set(CustomersHandler::class, new CustomersHandler($db));
    $app = AppFactory::create(container: $container);
    $app->addRoutingMiddleware();
    $app->group('/api/v1', function ($group) use ($container): void {
        (require dirname(__DIR__, 2) . '/app/routes/customers.php')($group, $container);
    });

    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/api/v1/customers/' . (int) $input['customer_id'] . '/payments')
        ->withParsedBody([
            'amount' => (float) $input['amount'],
            'payment_method_id' => (int) $input['payment_method_id'],
            'payment_date' => (string) $input['payment_date'],
            'branch_id' => (int) $input['branch_id'],
            'notes' => 'Concurrent customer payment integration test',
        ])
        ->withHeader('Idempotency-Key', (string) $input['idempotency_key'])
        ->withAttribute('tenant_id', (int) $input['tenant_id'])
        ->withAttribute('user', [
            'id' => (int) $input['user_id'],
            'tenant_id' => (int) $input['tenant_id'],
            'role' => 'sales_payment_it',
        ]);
    $response = $app->handle($request);

    echo 'WORKER_RESULT:' . json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    echo 'WORKER_RESULT:' . json_encode([
        'error' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
