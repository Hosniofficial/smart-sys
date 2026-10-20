<?php

declare(strict_types=1);

use App\Handlers\ReturnsHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(ReturnsHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/returns', function (RouteCollectorProxy $group) use ($handler, $db) {

        // List endpoints — view permission
        $group->get('/sale', [$handler, 'listSale'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_VIEW, $db));

        $group->get('/purchase', [$handler, 'listPurchase'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_VIEW, $db));

        $group->get('/sale/{id:\d+}', [$handler, 'getSale'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_VIEW, $db));

        $group->get('/purchase/{id:\d+}', [$handler, 'getPurchase'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_VIEW, $db));

        // Invoice lookup helpers — need at least RETURN_CREATE to use them
        $group->get('/invoices', [$handler, 'searchInvoice'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_CREATE, $db));

        $group->get('/invoice-items', [$handler, 'getInvoiceItems'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_CREATE, $db));

        $group->get('/returned-qty', [$handler, 'getReturnedQuantities'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_CREATE, $db));

        // Create return
        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::RETURN_CREATE, $db));
    });
};
