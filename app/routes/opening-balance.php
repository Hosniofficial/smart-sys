<?php

declare(strict_types=1);

use App\Handlers\OpeningBalanceHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(OpeningBalanceHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/setup/opening-balance', function (RouteCollectorProxy $group) use ($handler, $db) {

        // NOTE: GET /template was removed — no Frontend consumer found.
        // The handler method template() was also deleted from OpeningBalanceHandler.

        // ── Preview (dry-run, no data written — view permission) ─────────────
        $group->post('/preview', function (Request $request, Response $response) use ($handler) {
            return $handler->preview($request, $response);
        })->add(PermissionMiddleware::require(Permissions::ACCOUNTING_OPENING_BALANCE, $db));

        // ── Commit (irreversible write — requires dedicated permission) ───────
        $group->post('/commit', function (Request $request, Response $response) use ($handler) {
            return $handler->commit($request, $response);
        })->add(PermissionMiddleware::require(Permissions::ACCOUNTING_OPENING_BALANCE, $db));
    });
};
