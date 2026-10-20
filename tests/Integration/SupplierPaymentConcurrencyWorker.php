<?php

declare(strict_types=1);

use App\Handlers\PurchasesHandler;
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
        throw new RuntimeException('Refusing MySQL concurrency workers unless DB_HOST is local.');
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
    $barrierDeadline = microtime(true) + 20;
    do {
        $ready = $db->prepare('SELECT COUNT(*) FROM supplier_payment_test_barrier WHERE gate = ?');
        $ready->execute([$input['barrier']]);
        if ((int) $ready->fetchColumn() === 2) {
            break;
        }
        if (microtime(true) >= $barrierDeadline) {
            throw new RuntimeException('Timed out waiting for both concurrent payment workers.');
        }
        usleep(20000);
    } while (true);

    $secret = 'supplier-payment-mysql-integration-test-secret';
    $container = new Container();
    $container->set(PDO::class, $db);
    $container->set('db', $db);
    $container->set('config.security', ['jwt' => ['secret' => $secret]]);
    $container->set(PurchasesHandler::class, new PurchasesHandler($db));

    $app = AppFactory::create(container: $container);
    $app->addRoutingMiddleware();
    $app->group('/api/v1', function ($group) use ($container): void {
        (require dirname(__DIR__, 2) . '/app/routes/purchases.php')($group, $container);
    });
    $app->add(new SubscriptionMiddleware($db));
    $app->add(new TenantMiddleware($container));
    $app->add(new JwtAuthMiddleware(new JwtBlacklistService($db, $secret), null, $container));

    $token = JWT::encode([
        'user_id' => $input['user_id'],
        'tenant_id' => $input['tenant_id'],
        'role_id' => $input['role_id'],
        'role' => 'payment_operator',
        'exp' => time() + 3600,
        'jti' => bin2hex(random_bytes(8)),
    ], $secret, 'HS256');
    $route = $input['route'] ?? 'supplier_debt';
    $invoicePayment = $route === 'invoice';
    $uri = $invoicePayment
        ? '/api/v1/purchases/' . (int) $input['purchase_id'] . '/payments'
        : '/api/v1/purchases/pay-debt';
    if ($invoicePayment) {
        $body = [
            'amount' => $input['amount'],
            'payment_method_id' => $input['payment_method_id'],
            'payment_date' => date('Y-m-d'),
            'branch_id' => $input['branch_id'],
            'cost_center_id' => $input['cost_center_id'],
        ];
    } else {
        $body = [
            'supplier_id' => $input['supplier_id'],
            'amount' => $input['amount'],
            'payment_method_id' => $input['payment_method_id'],
            'payment_date' => date('Y-m-d'),
            'branch_id' => $input['branch_id'],
            'cost_center_id' => $input['cost_center_id'],
        ];
        if ($route !== 'supplier_debt_unallocated') {
            $body['purchases'] = [[
                'purchase_id' => $input['purchase_id'],
                'amount' => $input['amount'],
            ]];
        }
    }
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', $uri)
        ->withHeader('Authorization', 'Bearer ' . $token)
        ->withHeader('Idempotency-Key', $input['idempotency_key'])
        ->withParsedBody($body);
    $response = $app->handle($request);
    echo 'WORKER_RESULT:' . json_encode([
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getBody(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    echo 'WORKER_RESULT:' . json_encode([
        'error' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
