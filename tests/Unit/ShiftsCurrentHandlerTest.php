<?php

declare(strict_types=1);

use App\Handlers\ShiftsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ShiftsCurrentHandlerTest extends TestCase
{
    public function testCurrentReturnsOnlyOpenShiftForRequestedTenantBranchAndTerminal(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO cashier_shifts (id, tenant_id, branch_id, terminal_id, status, start_time) VALUES
            (1, 7, 3, 5, 'open', '2026-10-18 09:00:00'),
            (2, 8, 3, 5, 'open', '2026-10-18 10:00:00'),
            (3, 7, 3, 5, 'closed', '2026-10-18 11:00:00')");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/shifts/current')
            ->withAttribute('tenant_id', 7)
            ->withQueryParams(['branch_id' => '3', 'terminal_id' => '5']);

        $response = (new ShiftsHandler($db))->current($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['data']['id']);
    }

    public function testCurrentRequiresBranchAndTerminal(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/shifts/current')
            ->withAttribute('tenant_id', 7);

        $response = (new ShiftsHandler($this->database()))
            ->current($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('error', $body['status']);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE cashier_shifts (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            branch_id INTEGER NOT NULL,
            terminal_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            start_time TEXT NOT NULL
        )');

        return $db;
    }
}
