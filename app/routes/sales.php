<?php

declare(strict_types=1);

use App\Handlers\SalesHandler;
use App\Handlers\SalesPriceCampaignsHandler;
use App\Handlers\SalesQuotationsHandler;
use App\Security\Permissions;
use App\Middleware\PermissionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = $container->get(SalesHandler::class);
    $campaignsHandler = $container->get(SalesPriceCampaignsHandler::class);
    $quotationsHandler = $container->get(SalesQuotationsHandler::class);
    $db      = $container->get(PDO::class);

    $group->group('/sales', function (RouteCollectorProxy $group) use ($handler, $campaignsHandler, $quotationsHandler, $db) {

        $group->get('/price-campaigns', [$campaignsHandler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::SALE_DISCOUNT, $db));

        $group->post('/price-campaigns', [$campaignsHandler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::SALE_DISCOUNT, $db));

        $group->patch('/price-campaigns/{id:[0-9]+}/status', [$campaignsHandler, 'setStatus'])
            ->add(PermissionMiddleware::require(Permissions::SALE_DISCOUNT, $db));

        $group->get('/quotations', [$quotationsHandler, 'list'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VIEW, $db));

        $group->post('/quotations', [$quotationsHandler, 'create'])
            ->add(PermissionMiddleware::require(Permissions::SALE_CREATE, $db));

        $group->get('/quotations/{id:[0-9]+}', [$quotationsHandler, 'get'])
            ->add(PermissionMiddleware::require(Permissions::SALE_VIEW, $db));

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
