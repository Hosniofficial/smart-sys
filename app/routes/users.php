<?php

declare(strict_types=1);

use App\Handlers\UsersHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(UsersHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/users', function (RouteCollectorProxy $group) use ($handler, $db) {

        // ── Self-service (no permission required beyond being authenticated) ──
        // Any logged-in user can read their own profile and change their password.
        $group->get('/me', [$handler, 'getMe']);
        $group->post('/update-profile', [$handler, 'updateProfile']);
        $group->post('/change-password', [$handler, 'changePassword']);

        $group->get('/list', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::USER_VIEW, $db));

    });
};
