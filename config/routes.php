<?php

declare(strict_types=1);

use App\Middleware\JwtAuthMiddleware;
use App\Middleware\TenantMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Middleware\SuperAdminMiddleware;
use App\Handlers\OwnerContributionHandler;
use App\Handlers\SubscriptionCronHandler;
use DI\Container;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app, Container $container): void {

    // ── تحميل الملفات مرة واحدة ──────────────────────────────────────────
    $authRoutes   = require __DIR__ . '/../app/routes/auth.php';
    $publicRoutes = require __DIR__ . '/../app/routes/public.php';

    // ── 1. Public routes ──────────────────────────────────────────────────
    $publicRoutes($app, $container);

    // The cron handler authenticates requests with X-Cron-Token itself.
    $app->post(
        '/api/v1/admin/subscriptions/cron',
        [$container->get(SubscriptionCronHandler::class), 'processCron']
    );

    // ── 2. Auth routes (بدون أي middleware) ──────────────────────────────
    $app->group('/api/v1/auth', function (RouteCollectorProxy $group) use ($container, $authRoutes) {
        $authRoutes($group, $container);
    });

    // ── 3. JWT-only (بدون subscription) ──────────────────────────────────
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container) {

        $group->get('/plans', \App\Handlers\SubscriptionHandler::class . ':listPublicPlans');
        $ownerContributionHandler = $container->get(OwnerContributionHandler::class);
        $group->get(
            '/admin/owner-contribution-tenants',
            [$ownerContributionHandler, 'listAvailableTenants']
        )->add($container->get(SuperAdminMiddleware::class));

        $group->group('', function (RouteCollectorProxy $group) {
            $group->get('/subscription/me', \App\Handlers\SubscriptionHandler::class . ':getMySubscription');
        })->add(JwtAuthMiddleware::class);

    });

    // ── 4. Protected routes (JWT + Tenant + Subscription) ────────────────
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container) {

        // Core
        (require __DIR__ . '/../app/routes/admin.php')($group, $container);

        // Business
        (require __DIR__ . '/../app/routes/products.php')($group, $container);
        (require __DIR__ . '/../app/routes/inventory.php')($group, $container);
        (require __DIR__ . '/../app/routes/sales.php')($group, $container);
        (require __DIR__ . '/../app/routes/purchases.php')($group, $container);
        (require __DIR__ . '/../app/routes/returns.php')($group, $container);
        (require __DIR__ . '/../app/routes/payments.php')($group, $container);

        // Reports
        (require __DIR__ . '/../app/routes/reports.php')($group, $container);
        (require __DIR__ . '/../app/routes/analytics.php')($group, $container);

        // System
        (require __DIR__ . '/../app/routes/settings.php')($group, $container);
        (require __DIR__ . '/../app/routes/sessions.php')($group, $container);
        (require __DIR__ . '/../app/routes/terminals.php')($group, $container);
        (require __DIR__ . '/../app/routes/shifts.php')($group, $container);

        // Bootstrap (Aggregated APIs for performance)
        (require __DIR__ . '/../app/routes/bootstrap.php')($group, $container);
        
        // Users
        (require __DIR__ . '/../app/routes/categories.php')($group, $container);
        (require __DIR__ . '/../app/routes/customers.php')($group, $container);
        (require __DIR__ . '/../app/routes/suppliers.php')($group, $container);
        (require __DIR__ . '/../app/routes/branches.php')($group, $container);
        (require __DIR__ . '/../app/routes/users.php')($group, $container);
        (require __DIR__ . '/../app/routes/rbac.php')($group, $container);

        // Setup
        (require __DIR__ . '/../app/routes/setup.php')($group, $container);
        (require __DIR__ . '/../app/routes/opening-balance.php')($group, $container);

        // Accounting
        (require __DIR__ . '/../app/routes/accounting-periods.php')($group, $container);
        (require __DIR__ . '/../app/routes/accounts.php')($group, $container);
        (require __DIR__ . '/../app/routes/cost-centers.php')($group, $container);
        (require __DIR__ . '/../app/routes/audit.php')($group, $container);
        (require __DIR__ . '/../app/routes/cash-vouchers.php')($group, $container);
        (require __DIR__ . '/../app/routes/notifications.php')($group, $container);
        (require __DIR__ . '/../app/routes/payment-methods.php')($group, $container);
        (require __DIR__ . '/../app/routes/product-branch.php')($group, $container);
        (require __DIR__ . '/../app/routes/warranty.php')($group, $container);
        (require __DIR__ . '/../app/routes/repairs.php')($group, $container);
        (require __DIR__ . '/../app/routes/accounting-reports.php')($group, $container);

    })
    ->add(SubscriptionMiddleware::class)
    ->add(TenantMiddleware::class)
    ->add(JwtAuthMiddleware::class);

};
