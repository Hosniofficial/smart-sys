<?php

declare(strict_types=1);

use App\Handlers\SuppliersHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(SuppliersHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/suppliers', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'getSuppliers'])
            ->add(PermissionMiddleware::require(Permissions::SUPPLIER_VIEW, $db));

        $group->get('/missing-accounts', [$handler, 'listMissingAccounts'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db));

        $group->get('/{id}/statement', [$handler, 'getStatement'])
            ->add(PermissionMiddleware::require(Permissions::SUPPLIER_VIEW, $db));

        $group->post('', [$handler, 'createSupplier'])
            ->add(PermissionMiddleware::require(Permissions::SUPPLIER_CREATE, $db));

        $group->post('/cash-default', [$handler, 'ensureCashSupplier'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_CREATE, $db));

        $group->post('/{id}/ensure-account', [$handler, 'ensureAccount'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db));

        $group->put('/{id}', [$handler, 'updateSupplier'])
            ->add(PermissionMiddleware::require(Permissions::SUPPLIER_EDIT, $db));

        $group->delete('/{id}', [$handler, 'deleteSupplier'])
            ->add(PermissionMiddleware::require(Permissions::SUPPLIER_DELETE, $db));
    });
};
