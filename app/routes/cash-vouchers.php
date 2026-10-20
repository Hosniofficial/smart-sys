<?php

declare(strict_types=1);

use App\Handlers\CashVouchersHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(CashVouchersHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/cash-vouchers', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'getList'])
            ->add(PermissionMiddleware::require(Permissions::VOUCHER_VIEW, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::VOUCHER_CREATE, $db));

        $group->put('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::VOUCHER_EDIT, $db));

        $group->delete('/{id:[0-9]+}', [$handler, 'delete'])
            ->add(PermissionMiddleware::require(Permissions::VOUCHER_DELETE, $db));
    });
};
