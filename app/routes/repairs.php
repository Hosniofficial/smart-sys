<?php

declare(strict_types=1);

use App\Handlers\RepairOrdersHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
    $handler = $container->get(RepairOrdersHandler::class);
    $db = $container->get(PDO::class);

    $group->group('/repairs', function (RouteCollectorProxy $group) use ($handler, $db): void {
        $group->get('', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VIEW, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::SALE_CREATE, $db));

        $group->get('/{id:[0-9]+}', [$handler, 'get'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VIEW, $db));

        $group->patch('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::SALE_EDIT, $db));

        $group->post('/{id:[0-9]+}/invoice', [$handler, 'createInvoice'])
            ->add(PermissionMiddleware::require(Permissions::SALE_CREATE, $db));
    });
};
