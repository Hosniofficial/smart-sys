<?php

declare(strict_types=1);

use App\Handlers\CostCentersHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
    $handler = $container->get(CostCentersHandler::class);
    $db = $container->get(PDO::class);

    $group->get('/cost-centers', [$handler, 'list'])
        ->add(PermissionMiddleware::anyOf([
            Permissions::SALE_EDIT,
            Permissions::VOUCHER_CREATE,
            Permissions::VOUCHER_EDIT,
        ], $db));
};
