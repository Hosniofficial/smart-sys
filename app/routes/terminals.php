<?php

declare(strict_types=1);

use App\Handlers\TerminalsHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, TerminalsHandler::class);
    $db      = $container;

    $group->group('/terminals', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::POS_TERMINAL_MANAGE, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::POS_TERMINAL_MANAGE, $db));

        $group->put('/{id}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::POS_TERMINAL_MANAGE, $db));
    });
};
