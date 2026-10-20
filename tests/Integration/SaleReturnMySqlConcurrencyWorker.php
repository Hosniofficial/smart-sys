<?php

declare(strict_types=1);

use App\Handlers\ReturnsHandler;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\TenantMiddleware;
use App\Services\JwtBlacklistService;
use DI\Container;
use Firebase\JWT\JWT;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Refusing return concurrency workers unless DB_HOST is local.');
    }
    if (!preg_match('/^smartsys_sales_it_[a-f0-9]{16}$/', (string) ($input['schema'] ?? ''))) {
        throw new RuntimeException('Refusing return worker for an unrecognized test schema.');
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

    $barrier = $db->prepare('INSERT INTO sale_return_test_barrier (gate, worker) VALUES (?, ?)');
    $barrier->execute([$input['gate'], $input['worker']]);
    $deadline = microtime(true) + 20;
    do {
        $ready = $db->prepare('SELECT COUNT(*) FROM sale_return_test_barrier WHERE gate = ?');
        $ready->execute([$input['gate']]);
        if ((int) $ready->fetchColumn() === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for both sale return workers.');
        }
        usleep(20000);
    } while (true);

    $secret = 'sales-mysql-integration-test-secret';
    $container = new Container();
    $container->set(PDO::class, $db);
    $container->set('db', $db);
    $container->set('config.security', ['jwt' => ['secret' => $secret]]);
    $container->set(ReturnsHandler::class, new ReturnsHandler($db));

    $app = AppFactory::create(container: $container);
    $app->addRoutingMiddleware();
    $app->group('/api/v1', function ($group) use ($container): void {
        (require dirname(__DIR__, 2) . '/app/routes/returns.php')($group, $container);
    });
    $app->add(new SubscriptionMiddleware($db));
    $app->add(new TenantMiddleware($container));
    $app->add(new JwtAuthMiddleware(new JwtBlacklistService($db, $secret), null, $container));

    $token = JWT::encode([
        'user_id' => $input['user_id'],
        'tenant_id' => $input['tenant_id'],
        'role_id' => $input['role_id'],
        'role' => 'return_integration_operator',
        'exp' => time() + 3600,
        'jti' => bin2hex(random_bytes(8)),
    ], $secret, 'HS256');
    $returnType = (string) ($input['return_type'] ?? 'sale');
    $invoiceId = (int) ($input['invoice_id'] ?? $input['sale_id'] ?? 0);
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/api/v1/returns')
        ->withHeader('Authorization', 'Bearer ' . $token)
        ->withParsedBody([
            'return_type' => $returnType,
            'return_date' => $input['return_date'],
            'invoice_id' => $invoiceId,
            'paid_amount' => (float) ($input['paid_amount'] ?? 0),
            'payment_method_id' => (int) $input['payment_method_id'],
            'cost_center_id' => (int) $input['cost_center_id'],
            'refund_mode' => (string) ($input['refund_mode'] ?? 'credit_note'),
            'items' => [[
                'product_id' => (int) $input['product_id'],
                'unit_id' => (int) $input['unit_id'],
                'quantity' => 1,
                'unit_price' => (float) ($input['unit_price'] ?? 250),
                'subtotal' => (float) ($input['item_amount'] ?? 250),
            ]],
        ]);
    $response = $app->handle($request);

    echo 'WORKER_RESULT:' . json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    echo 'WORKER_RESULT:' . json_encode([
        'fatal' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
