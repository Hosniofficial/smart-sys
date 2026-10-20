<?php

declare(strict_types=1);

use App\Handlers\CashVouchersHandler;
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
        throw new RuntimeException('Refusing MySQL cash voucher workers unless DB_HOST is local.');
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
        'INSERT INTO voucher_idempotency_test_barrier (gate, worker) VALUES (?, ?)'
    );
    $barrier->execute([$input['barrier'], $input['worker']]);
    $deadline = microtime(true) + 20;
    do {
        $ready = $db->prepare(
            'SELECT COUNT(*) FROM voucher_idempotency_test_barrier WHERE gate = ?'
        );
        $ready->execute([$input['barrier']]);
        if ((int) $ready->fetchColumn() === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for both concurrent voucher workers.');
        }
        usleep(20000);
    } while (true);

    $secret = 'cash-voucher-mysql-integration-test-secret';
    $container = new Container();
    $container->set(PDO::class, $db);
    $container->set('db', $db);
    $container->set('config.security', ['jwt' => ['secret' => $secret]]);
    $container->set(CashVouchersHandler::class, new CashVouchersHandler($db));

    $app = AppFactory::create(container: $container);
    $app->addRoutingMiddleware();
    $app->group('/api/v1', function ($group) use ($container): void {
        (require dirname(__DIR__, 2) . '/app/routes/cash-vouchers.php')($group, $container);
    });
    $app->add(new SubscriptionMiddleware($db));
    $app->add(new TenantMiddleware($container));
    $app->add(new JwtAuthMiddleware(
        new JwtBlacklistService($db, $secret),
        null,
        $container
    ));

    $token = JWT::encode([
        'user_id' => $input['user_id'],
        'tenant_id' => $input['tenant_id'],
        'branch_id' => $input['branch_id'],
        'role_id' => $input['role_id'],
        'exp' => time() + 3600,
        'jti' => bin2hex(random_bytes(8)),
    ], $secret, 'HS256');
    $voucherType = (string) ($input['voucher_type'] ?? 'receipt');
    $payload = [
        'type' => $voucherType,
        'date' => $input['date'],
        'amount' => $input['amount'],
        'currency' => $input['currency'],
        'account_id' => $input['account_id'],
        'branch_id' => $input['branch_id'],
        'payment_method_id' => $input['payment_method_id'],
        'cost_center_id' => $input['cost_center_id'],
        'notes' => $input['notes'],
    ];
    if ($voucherType === 'payment') {
        $payload['supplier_id'] = $input['supplier_id'];
    } else {
        $payload['customer_id'] = $input['customer_id'];
    }
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/api/v1/cash-vouchers')
        ->withHeader('Authorization', 'Bearer ' . $token)
        ->withHeader('Idempotency-Key', $input['idempotency_key'])
        ->withParsedBody($payload);
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
