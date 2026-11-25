<?php

declare(strict_types=1);

use App\Handlers\AuditHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $auditHandler = new \App\Routing\ContainerHandlerProxy($container, AuditHandler::class);
    $db           = $container;

    $group->group('/audit', function (RouteCollectorProxy $group) use ($auditHandler, $db) {
        $group->get('/logs', [$auditHandler, 'getAuditLogs'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));
    });
};
