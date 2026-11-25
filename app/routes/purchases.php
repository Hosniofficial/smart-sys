<?php

declare(strict_types=1);

use App\Handlers\PurchasesHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, PurchasesHandler::class);
    $db      = $container;

    $group->group('/purchases', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('/next-invoice-number', [$handler, 'getNextInvoiceNumber'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_CREATE, $db));

        $group->get('', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_VIEW, $db));

        $group->get('/{id:[0-9]+}', [$handler, 'get'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_VIEW, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_CREATE, $db));

        $group->put('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_EDIT, $db));

        $group->delete('/{id:[0-9]+}', [$handler, 'delete'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_DELETE, $db));

        $group->post('/{id:[0-9]+}/payments', [$handler, 'addPayment'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_PAYMENT_CREATE, $db));

        $group->post('/pay-debt', [$handler, 'paySupplierDebt'])
            ->add(PermissionMiddleware::require(Permissions::PURCHASE_PAYMENT_CREATE, $db));
    });
};
