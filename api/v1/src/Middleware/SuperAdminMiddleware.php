<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use App\Utils\SuperAdminHelper;
use App\Utils\ApiResponseEnvelope;

/** Restricts a route group to platform-scoped super-admin identities. */
class SuperAdminMiddleware implements MiddlewareInterface
{
    /** Reject requests without a verified platform super-admin identity. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (SuperAdminHelper::isNot(is_array($user) ? $user : null)) {
            $response = new Response();
            $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Forbidden: super_admin only'
            ], 403), JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        return $handler->handle($request);
    }
}
