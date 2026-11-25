<?php

declare(strict_types=1);

use App\Handlers\ProductsHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, ProductsHandler::class);
    $db      = $container;

    // Units lookup — any authenticated user (cashier needs it for POS)
    $group->get('/units', [$handler, 'listUnits']);

    $group->group('/products', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('/search', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::PRODUCT_VIEW, $db));

        $group->get('', [$handler, 'getAll'])
            ->add(PermissionMiddleware::require(Permissions::PRODUCT_VIEW, $db));

        $group->get('/{id:[0-9]+}', [$handler, 'get'])
            ->add(PermissionMiddleware::require(Permissions::PRODUCT_VIEW, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::PRODUCT_CREATE, $db));

        $group->put('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::PRODUCT_EDIT, $db));

        $group->delete('/{id:[0-9]+}', [$handler, 'delete'])
            ->add(PermissionMiddleware::require(Permissions::PRODUCT_DELETE, $db));
    });
};
