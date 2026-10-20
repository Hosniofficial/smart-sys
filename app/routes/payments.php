<?php

declare(strict_types=1);

use App\Handlers\PaymentsHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $paymentsHandler    = $container->get(PaymentsHandler::class);
    $db                 = $container->get(PDO::class);

    $group->group('/payments', function (RouteCollectorProxy $group) use ($paymentsHandler, $db) {

        $group->get('', [$paymentsHandler, 'list'])
            ->add(PermissionMiddleware::anyOf([
                Permissions::SALE_PAYMENT_CREATE,
                Permissions::PURCHASE_PAYMENT_CREATE,
                Permissions::REPORT_FINANCIAL,
            ], $db));

    });

    $group->get('/receipts', [$paymentsHandler, 'listReceipts'])
        ->add(PermissionMiddleware::anyOf([
            Permissions::SALE_VIEW,
            Permissions::REPORT_SALES,
        ], $db));
};
