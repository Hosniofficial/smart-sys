<?php

declare(strict_types=1);

use App\Handlers\AdvancedReportsHandler;
use App\Handlers\SalesAnalyticsHandler;
use App\Handlers\PosAnalyticsHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $db = $container->get(PDO::class);

    $group->group('/reports', function (RouteCollectorProxy $group) use ($container, $db) {

        $salesH     = $container->get(SalesAnalyticsHandler::class);
        $posH       = $container->get(PosAnalyticsHandler::class);
        $advanced   = $container->get(AdvancedReportsHandler::class);

        // ── Sales reports ─────────────────────────────────────────────────────
        // NOTE: Duplicate route GET /reports/sales/summary was removed.
        // analyzeSales() remains accessible via GET /analytics/sales (see analytics.php).

        $group->get('/sales/daily-summary', [$salesH, 'dailySalesSummary'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_SALES, $db));

        // ── Inventory reports ─────────────────────────────────────────────────
        // ── POS ───────────────────────────────────────────────────────────────
        $group->get('/pos', [$posH, 'listPos'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_SALES, $db));

        // ── Contacts ──────────────────────────────────────────────────────────
        $group->get('/profit-loss', [$advanced, 'getProfitLossReport'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

    });
};
