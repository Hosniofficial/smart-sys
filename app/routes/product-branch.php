<?php

use App\Handlers\ProductBranchHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
    $handler = $container->get(ProductBranchHandler::class);
    $db = $container->get(PDO::class);

    // Product-Branch GL Integration routes (SAP-style accounting)
    $group->group('/product-branch', function (RouteCollectorProxy $group) use ($handler, $db) {
        // Get product-branch status with GL reconciliation info
        $group->get('/status', [$handler, 'getProductsStatus']);

        // Activate product in branch (DRAFT → ACTIVE_IN_BRANCH)
        $group->post('/activate', [$handler, 'activateProductInBranch']);

        // Post opening balance with GL entries (ACTIVE_IN_BRANCH → GL_POSTED)
        $group->post('/opening-balance/post', [$handler, 'postOpeningBalance']);

        $group->get('/opening-balances', [$handler, 'getActiveOpeningBalances'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->post('/opening-balance/reverse', [$handler, 'reverseOpeningBalance'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_JE_REVERSE, $db))
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_ADJUST, $db));
    });
};
