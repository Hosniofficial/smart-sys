<?php

declare(strict_types=1);

use App\Handlers\SalesHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(SalesHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/sales', function (RouteCollectorProxy $group) use ($handler, $db) {

        // ── Read ──────────────────────────────────────────────────────────────
        $group->get('', [$handler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VIEW, $db));

        $group->get('/{id:[0-9]+}', [$handler, 'get'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VIEW, $db));

        $group->get('/pending-approvals', [$handler, 'pendingApprovals'])
            ->add(PermissionMiddleware::require(Permissions::SALE_APPROVAL_VIEW, $db));

        // ── Write ─────────────────────────────────────────────────────────────
        $group->post('', [$handler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::SALE_CREATE, $db));

        // Payments
        $group->post('/add-payment', [$handler, 'addSalesPayment'])
            ->add(PermissionMiddleware::require(Permissions::SALE_PAYMENT_CREATE, $db));

        $group->post('/pay-debt', [$handler, 'payDebt'])
            ->add(PermissionMiddleware::require(Permissions::SALE_PAYMENT_CREATE, $db));

        // Approval workflow
        $group->post('/{id:[0-9]+}/approve', [$handler, 'approve'])
            ->add(PermissionMiddleware::require(Permissions::SALE_APPROVAL_APPROVE, $db));

        $group->post('/{id:[0-9]+}/reject', [$handler, 'reject'])
            ->add(PermissionMiddleware::require(Permissions::SALE_APPROVAL_REJECT, $db));

        // Full update
        $group->put('/{id:[0-9]+}', [$handler, 'update'])
            ->add(PermissionMiddleware::require(Permissions::SALE_EDIT, $db));

        // Delete / void — most sensitive: requires SALE_VOID
        $group->delete('/{id:[0-9]+}', [$handler, 'delete'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VOID, $db));
    });
};
