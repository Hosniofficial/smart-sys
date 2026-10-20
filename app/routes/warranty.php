<?php

declare(strict_types=1);

use App\Handlers\WarrantyHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(WarrantyHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/warranty', function (RouteCollectorProxy $group) use ($handler, $db) {

        // ── Read ──────────────────────────────────────────────────────────────
        $group->get('/requests', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_VIEW, $db));

        $group->get('/requests/{id}', [$handler, 'get'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_VIEW, $db));

        // ── Write ─────────────────────────────────────────────────────────────
        $group->post('/requests', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_CREATE, $db));

        $group->patch('/requests/{id}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_EDIT, $db));

        $group->post('/requests/{id}/status', [$handler, 'changeStatus'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_EDIT, $db));

        $group->post('/requests/{id}/notes', [$handler, 'addNote'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_EDIT, $db));

        $group->post('/requests/{id}/attachments', [$handler, 'uploadAttachment'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_EDIT, $db));

        // ── Delete ────────────────────────────────────────────────────────────
        $group->delete('/attachments/{attachmentId}', [$handler, 'deleteAttachment'])
            ->add(PermissionMiddleware::require(Permissions::WARRANTY_DELETE, $db));
    });
};
