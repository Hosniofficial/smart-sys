<?php

declare(strict_types=1);

use App\Handlers\BranchHandler;
use App\Handlers\BranchInventoryReportHandler;
use App\Handlers\StockAdjustmentHandler;
use App\Handlers\StockTransferHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler     = $container->get(BranchHandler::class);
    $reportH     = $container->get(BranchInventoryReportHandler::class);
    $adjustH     = $container->get(StockAdjustmentHandler::class);
    $transferH   = $container->get(StockTransferHandler::class);
    $db          = $container->get(PDO::class);

    $group->group('/branches', function (RouteCollectorProxy $group) use ($handler, $reportH, $adjustH, $transferH, $db) {

        // ── Static routes FIRST ───────────────────────────────────────────
        $group->get('', [$handler, 'listWithAggregates'])
            ->add(PermissionMiddleware::require(Permissions::BRANCH_VIEW, $db));

        // Transfers
        $group->get('/transfers', [$transferH, 'listTransfers'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->get('/transfers/{id}', [$transferH, 'getTransferById'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        // Inventory Reports
        $group->get('/reports/inventory-value', [$reportH, 'inventoryValueReport'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_INVENTORY, $db));

        $group->get('/reports/inventory-value/by-branch', [$reportH, 'inventoryValueBybranch'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_INVENTORY, $db));

        $group->get('/reports/inventory/movements', [$reportH, 'inventoryMovementsReport'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_INVENTORY, $db));

        $group->get('/reports/account-coverage', [$reportH, 'branchesAccountCoverage'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db));

        // Bulk Adjustments
        $group->post('/adjustments/bulk', [$adjustH, 'bulkAdjustments'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_ADJUST, $db));

        $group->post('/adjustments/bulk/csv', [$adjustH, 'bulkAdjustmentsCsv'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_ADJUST, $db));

        // ── Variable routes AFTER ─────────────────────────────────────────
        $group->get('/{id}/stock', [$reportH, 'getBranchStock'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->get('/{id}/transfers', [$transferH, 'listBranchTransfersByInventory'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db));

        $group->post('/{id}/transfer', function (Request $request, Response $response, array $args) use ($transferH) {
            return $transferH->transferStock($request, $response, $args);
        })->add(PermissionMiddleware::require(Permissions::INVENTORY_TRANSFER, $db));

        $group->post('/{id}/adjustments', [$adjustH, 'adjustStockQuantity'])
            ->add(PermissionMiddleware::require(Permissions::INVENTORY_ADJUST, $db));

        // ── Branch CRUD ───────────────────────────────────────────────────
        $group->post('', [$handler, 'createBranch'])
            ->add(PermissionMiddleware::require(Permissions::BRANCH_CREATE, $db));

        $group->put('/{id}', [$handler, 'updateBranch'])
            ->add(PermissionMiddleware::require(Permissions::BRANCH_EDIT, $db));

        $group->delete('/{id}', [$handler, 'deleteBranch'])
            ->add(PermissionMiddleware::require(Permissions::BRANCH_DELETE, $db));
    });
};
