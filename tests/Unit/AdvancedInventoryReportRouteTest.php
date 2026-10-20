<?php

declare(strict_types=1);

use App\Handlers\AdvancedReportsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdvancedInventoryReportRouteTest extends TestCase
{
    public function testRegisteredHandlerMethodReturnsTenantScopedInventoryAnalysis(): void
    {
        $handler = new class (new PDO('sqlite::memory:')) extends AdvancedReportsHandler {
            public function getInventoryAnalysis(array $params, int $tenantId): array
            {
                return ['tenant_id' => $tenantId, 'filters' => $params];
            }
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/reports/inventory/advanced?branch_id=7')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['branch_id' => '7']);

        $response = $handler->getAdvancedInventoryReport(
            $request,
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'status' => 'success',
            'data' => ['tenant_id' => 3, 'filters' => ['branch_id' => '7']],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testAdvancedInventoryReportRequiresTenantContext(): void
    {
        $handler = new AdvancedReportsHandler(new PDO('sqlite::memory:'));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/reports/inventory/advanced');

        $response = $handler->getAdvancedInventoryReport(
            $request,
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(403, $response->getStatusCode());
    }
}
