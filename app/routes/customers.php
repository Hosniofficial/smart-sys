<?php

declare(strict_types=1);

use App\Handlers\CustomersHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(CustomersHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/customers', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'getCustomers'])
            ->add(PermissionMiddleware::require(Permissions::CUSTOMER_VIEW, $db));

        $group->get('/missing-accounts', [$handler, 'listMissingAccounts'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db));

        $group->get('/{id}', [$handler, 'getCustomer'])
            ->add(PermissionMiddleware::require(Permissions::CUSTOMER_VIEW, $db));

        $group->get('/{id}/statement', [$handler, 'getStatement'])
            ->add(PermissionMiddleware::require(Permissions::CUSTOMER_VIEW, $db));

        $group->post('', [$handler, 'createCustomer'])
            ->add(PermissionMiddleware::require(Permissions::CUSTOMER_CREATE, $db));

        $group->post('/{id}/ensure-account', [$handler, 'ensureAccount'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db));

        $group->post('/{id}/payments', [$handler, 'addPayment'])
            ->add(PermissionMiddleware::require(Permissions::SALE_PAYMENT_CREATE, $db));

        $group->put('/{id}', [$handler, 'updateCustomer'])
            ->add(PermissionMiddleware::require(Permissions::CUSTOMER_EDIT, $db));

        $group->delete('/{id}', [$handler, 'deleteCustomer'])
            ->add(PermissionMiddleware::require(Permissions::CUSTOMER_DELETE, $db));
    });
};
