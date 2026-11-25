<?php

declare(strict_types=1);

use App\Handlers\InventoryHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, InventoryHandler::class);
    $db      = $container;

    $group->group('/inventory', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('/stock', [$handler, 'getStock'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->get('/batches', [$handler, 'getBatches'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->get('/movements', [$handler, 'getMovements'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->get('/summary', [$handler, 'getSummary'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));
    });
};
