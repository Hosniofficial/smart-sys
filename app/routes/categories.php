<?php

declare(strict_types=1);

use App\Handlers\CategoriesHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(CategoriesHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/categories', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'getAll'])
            ->add(PermissionMiddleware::require(Permissions::CATEGORY_VIEW, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::CATEGORY_CREATE, $db));

        $group->put('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::CATEGORY_EDIT, $db));

        $group->delete('/{id:[0-9]+}', [$handler, 'delete'])
            ->add(PermissionMiddleware::require(Permissions::CATEGORY_DELETE, $db));
    });
};
