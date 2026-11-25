<?php

declare(strict_types=1);

use App\Handlers\PaymentMethodsHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, PaymentMethodsHandler::class);
    $db      = $container;

    $group->group('/payment-methods', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::PAYMENT_METHOD_VIEW, $db));

        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::PAYMENT_METHOD_MANAGE, $db));

        $group->put('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::PAYMENT_METHOD_MANAGE, $db));
    });
};
