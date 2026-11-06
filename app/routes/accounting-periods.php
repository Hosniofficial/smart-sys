<?php

declare(strict_types=1);

use App\Handlers\AccountingPeriodsHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(AccountingPeriodsHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/accounting-periods', function (RouteCollectorProxy $group) use ($handler, $db) {

        $group->get('', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db));

        // Write / State changes (open/close/reopen/delete)
        // All state-changing operations require ACCOUNTING_PERIOD_MANAGE.
        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_PERIOD_MANAGE, $db));

        $group->put('/{id}/close', [$handler, 'close'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_PERIOD_MANAGE, $db));

        $group->put('/{id}/reopen', [$handler, 'reopen'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_PERIOD_MANAGE, $db));

        $group->delete('/{id}', [$handler, 'delete'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_PERIOD_MANAGE, $db));
    });
};
