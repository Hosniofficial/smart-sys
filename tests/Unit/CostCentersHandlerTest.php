<?php

declare(strict_types=1);

use App\Handlers\CostCentersHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CostCentersHandlerTest extends TestCase
{
    public function testListReturnsOnlyActiveCostCentersForCurrentTenant(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE cost_centers (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT,
            code TEXT,
            is_active INTEGER
        )');
        $db->exec("INSERT INTO cost_centers (id, tenant_id, name, code, is_active) VALUES
            (1, 7, 'North', 'N', 1),
            (2, 7, 'Inactive', 'I', 0),
            (3, 8, 'Other tenant', 'O', 1)");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cost-centers')
            ->withAttribute('tenant_id', 7);
        $response = (new CostCentersHandler($db))->list($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('success', $body['status']);
        self::assertSame([
            ['id' => 1, 'name' => 'North', 'code' => 'N'],
        ], $body['data']);
    }

    public function testListRequiresTenantContext(): void
    {
        $handler = new CostCentersHandler(new PDO('sqlite::memory:'));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/cost-centers');

        $response = $handler->list($request, (new ResponseFactory())->createResponse());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('error', json_decode((string) $response->getBody(), true)['status']);
    }
}
