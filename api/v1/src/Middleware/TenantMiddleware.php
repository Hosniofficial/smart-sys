<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Container\ContainerInterface;
use App\Database\DatabaseValidator;
use App\Services\MonologHandler;
use App\Utils\ApiResponseEnvelope;

/**
 * Resolves tenant scope from JWT attributes or a validated X-Tenant-ID header.
 *
 * Header-based requests are checked against active tenants through the
 * container's database connection; JWT-scoped requests pass through unchanged.
 */
class TenantMiddleware implements MiddlewareInterface
{
    private $container;
    private $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger    = MonologHandler::getInstance('tenant');
    }
    /** Validate or preserve the tenant context before dispatching the request. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (strpos($path, '/auth/') !== false) {
            return $handler->handle($request);
        }
        // السماح بالوصول لمسارات المصادقة والصحة بدون التحقق من X-Tenant-ID
        $allowed = [
            '/smartsys/api/v1/auth/login',
            '/smartsys/api/v1/auth/register',
            '/smartsys/api/v1/auth/refresh',
            '/smartsys/api/v1/health',
            '/smartsys/api/v1/check'
        ];
        if (in_array($path, $allowed)) {
            return $handler->handle($request);
        }
        // إذا كان JwtAuthMiddleware قد أرفق tenant_id من التوكن، فلا نستخدم/نفرض X-Tenant-ID
        $attrTenant = $request->getAttribute('tenant_id');
        if ($attrTenant) {
            return $handler->handle($request);
        }

        // For non-JWT protected routes, require X-Tenant-ID header
        $tenantId = $request->getHeaderLine('X-Tenant-ID');
        if (empty($tenantId)) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Missing X-Tenant-ID header'
            ], 400), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        // Validate tenant exists and is active
        try {
            $db = $this->container->get('db');
            $stmt = $db->prepare("SELECT id FROM tenants WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$tenantId]);

            if (!$stmt->fetch()) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
                    'status' => 'error',
                    'message' => 'Invalid or inactive tenant'
                ], 403), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(403);
            }
        } catch (\Exception $e) {
            $this->logger->error('Tenant validation error', ['error' => $e->getMessage()]);
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Tenant validation failed'
            ], 500)));
            return $response->withStatus(500);
        }

        // Inject validated tenant_id into request attributes
        $request = $request->withAttribute('tenant_id', $tenantId);
        return $handler->handle($request);
    }
}
