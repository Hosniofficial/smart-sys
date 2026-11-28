<?php

declare(strict_types=1);

use App\Middleware\EmailVerificationRequiredMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class EmailVerificationRequiredMiddlewareTest extends TestCase
{
    public function testVerifiedUserCanContinueUsingCurrentDatabaseState(): void
    {
        $db = $this->createUsersDatabase();
        $db->exec("INSERT INTO users (id, tenant_id, email_verified_at) VALUES (10, 2, '2026-11-28 08:00:00')");
        $middleware = new EmailVerificationRequiredMiddleware($db);
        $handler = new EmailVerificationRecordingHandler();
        $request = $this->requestForUser(['id' => 10, 'tenant_id' => 2]);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $handler->calls);
    }

    public function testUnverifiedUserIsBlocked(): void
    {
        $db = $this->createUsersDatabase();
        $db->exec('INSERT INTO users (id, tenant_id, email_verified_at) VALUES (10, 2, NULL)');
        $middleware = new EmailVerificationRequiredMiddleware($db);
        $handler = new EmailVerificationRecordingHandler();

        $response = $middleware->process($this->requestForUser(['id' => 10, 'tenant_id' => 2]), $handler);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Email verification required', $payload['message']);
        self::assertSame(0, $handler->calls);
    }

    public function testMissingAuthenticatedUserIsRejected(): void
    {
        $middleware = new EmailVerificationRequiredMiddleware($this->createUsersDatabase());
        $handler = new EmailVerificationRecordingHandler();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/sales');

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $handler->calls);
    }

    public function testDatabaseFailureFailsClosedWithServiceUnavailable(): void
    {
        $middleware = new EmailVerificationRequiredMiddleware(new PDO('sqlite::memory:'));
        $handler = new EmailVerificationRecordingHandler();
        $request = $this->requestForUser(['id' => 10, 'tenant_id' => 2]);

        $response = $middleware->process($request, $handler);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(0, $handler->calls);
    }

    private function createUsersDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE users (
                id INTEGER NOT NULL,
                tenant_id INTEGER NULL,
                email_verified_at TEXT NULL
            )'
        );

        return $db;
    }

    private function requestForUser(array $user): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/sales')
            ->withAttribute('user', $user);
    }
}

final class EmailVerificationRecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return new Response();
    }
}
