<?php

declare(strict_types=1);

use App\Handlers\RBACHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(RBACHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/rbac', function (RouteCollectorProxy $group) use ($handler, $db) {

        // ── Users (via RBAC) — delegate to UsersHandler for actual user ops ──
        // These endpoints are admin-facing user management via RBAC panel.
        $group->get('/users', [$handler, 'getUsers'])
            ->add(PermissionMiddleware::require(Permissions::USER_VIEW, $db));

        $group->post('/users', [$handler, 'createUser'])
            ->add(PermissionMiddleware::require(Permissions::USER_CREATE, $db));

        $group->put('/users/{id}', [$handler, 'updateUser'])
            ->add(PermissionMiddleware::require(Permissions::USER_EDIT, $db));

        $group->delete('/users/{id}', [$handler, 'deleteUser'])
            ->add(PermissionMiddleware::require(Permissions::USER_DELETE, $db));

        // ── Roles ─────────────────────────────────────────────────────────────
        $group->get('/roles', [$handler, 'getRoles'])
            ->add(PermissionMiddleware::require(Permissions::ROLE_VIEW, $db));

        $group->get('/permissions', [$handler, 'getPermissionCatalog'])
            ->add(PermissionMiddleware::require(Permissions::PERMISSION_VIEW, $db));

        $group->get('/roles/{id:[0-9]+}/permissions', [$handler, 'getRolePermissions'])
            ->add(PermissionMiddleware::require(Permissions::ROLE_VIEW, $db));

        $group->put('/roles/{id:[0-9]+}/permissions', [$handler, 'updateRolePermissions'])
            ->add(PermissionMiddleware::require(Permissions::PERMISSION_ASSIGN, $db));

        $group->get('/users/{id:[0-9]+}/permissions', [$handler, 'getUserPermissions'])
            ->add(PermissionMiddleware::require(Permissions::PERMISSION_VIEW, $db));

        $group->put('/users/{id:[0-9]+}/permissions', [$handler, 'updateUserPermissions'])
            ->add(PermissionMiddleware::require(Permissions::PERMISSION_ASSIGN, $db));
    });
};
