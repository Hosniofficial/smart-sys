<?php

declare(strict_types=1);

use App\Handlers\AuthHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AuthHandlerLoginProtectionTest extends TestCase
{
    private array $previousEnv = [];
    private array $previousServer = [];
    private $previousLogLevel;

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'JWT_REFRESH_SECRET', 'TWO_FA_ENCRYPTION_KEY'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['JWT_SECRET'] = str_repeat('a', 40);
        $_ENV['JWT_REFRESH_SECRET'] = str_repeat('b', 40);
        $_ENV['TWO_FA_ENCRYPTION_KEY'] = base64_encode(str_repeat('c', 32));
        $this->previousLogLevel = getenv('LOG_LEVEL');
        putenv('LOG_LEVEL=emergency');
        $this->previousServer = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        if ($this->previousLogLevel === false) {
            putenv('LOG_LEVEL');
        } else {
            putenv('LOG_LEVEL=' . $this->previousLogLevel);
        }
        $_SERVER = $this->previousServer;
    }

    public function testLockoutRequiresBothTheUsernameAndIpAddressToMatch(): void
    {
        $db = new AuthHandlerLoginTestPdo(5);
        $response = $this->login($db);

        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString(
            'WHERE username = ? AND ip_address = ?',
            $db->queries[0]
        );
    }

    public function testLoginFailsClosedWhenLockoutStorageIsUnavailable(): void
    {
        $db = new AuthHandlerLoginTestPdo(0);
        $db->failLockoutCheck = true;

        $response = $this->login($db);

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $db->queries);
    }

    public function testUnknownUserReceivesTheSameInvalidCredentialsStatus(): void
    {
        $db = new AuthHandlerLoginTestPdo(0);
        $response = $this->login($db);

        self::assertSame(401, $response->getStatusCode());
        self::assertTrue(
            count(array_filter($db->queries, static fn(string $query): bool => str_contains($query, 'INSERT INTO login_attempts'))) === 1
        );
    }

    private function login(AuthHandlerLoginTestPdo $db): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://example.test/api/v1/auth/login')
            ->withParsedBody(['username' => 'unknown-user', 'password' => 'incorrect']);

        return (new AuthHandler($db))->login($request, new Response());
    }
}

final class AuthHandlerLoginTestPdo extends PDO
{
    public array $queries = [];
    public bool $failLockoutCheck = false;

    public function __construct(private int $recentFailures)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($this->failLockoutCheck && str_contains($query, 'SELECT COUNT(*) FROM login_attempts')) {
            throw new PDOException('Lockout storage unavailable');
        }

        return new AuthHandlerLoginTestStatement($this, $query);
    }

    public function recentFailures(): int
    {
        return $this->recentFailures;
    }
}

final class AuthHandlerLoginTestStatement extends PDOStatement
{
    public function __construct(
        private AuthHandlerLoginTestPdo $db,
        private string $query
    ) {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->db->recentFailures();
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return false;
    }
}
