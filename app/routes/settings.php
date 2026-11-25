<?php

declare(strict_types=1);

use App\Handlers\AdminSettingsHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, AdminSettingsHandler::class);
    $db      = $container;

    $group->group('/settings', function (RouteCollectorProxy $group) use ($handler, $db) {

        // Print configuration is intentionally readable by authenticated staff so invoices
        // can use the current branch's settings without granting general settings access.
        $group->get('/print', [$handler, 'getBranchPrintSettings']);
        $group->put('/print', [$handler, 'updateBranchPrintSettings'])
            ->add(PermissionMiddleware::require(Permissions::SETTINGS_MANAGE, $db));
        $group->delete('/print', [$handler, 'resetBranchPrintSettings'])
            ->add(PermissionMiddleware::require(Permissions::SETTINGS_MANAGE, $db));

        // Read — requires view OR manage, OR tenant owner (for initial setup)
        $group->get('', [$handler, 'getSettings'])
            ->add(PermissionMiddleware::anyOf([
                Permissions::SETTINGS_VIEW,
                Permissions::SETTINGS_MANAGE,
            ], $db)->orOwner());

        $group->get('/currencies/rates', [$handler, 'getExchangeRatesAPI'])
            ->add(PermissionMiddleware::anyOf([
                Permissions::SETTINGS_VIEW,
                Permissions::SETTINGS_MANAGE,
            ], $db)->orOwner());

        $group->post('/currencies/rates/refresh', [$handler, 'refreshExchangeRatesAPI'])
            ->add(PermissionMiddleware::require(Permissions::SETTINGS_MANAGE, $db));

        // Write — requires manage only
        $group->put('', [$handler, 'updateSettings'])
            ->add(PermissionMiddleware::require(Permissions::SETTINGS_MANAGE, $db));

        $group->post('/logo', [$handler, 'uploadLogo'])
            ->add(PermissionMiddleware::require(Permissions::SETTINGS_MANAGE, $db));
    });
};
