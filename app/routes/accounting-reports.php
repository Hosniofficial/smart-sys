<?php

declare(strict_types=1);

use App\Handlers\AccountingReportsHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {

    $handler = new \App\Routing\ContainerHandlerProxy($container, AccountingReportsHandler::class);
    $db      = $container;

    $group->group('/reports/accounting', function (RouteCollectorProxy $group) use ($handler, $db) {

        // All accounting reports require report.financial
        $group->get('/trial-balance', [$handler, 'trialBalance'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        $group->get('/ledger/{account_id}', [$handler, 'ledger'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        $group->get('/income-statement', [$handler, 'incomeStatement'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        $group->get('/balance-sheet', [$handler, 'balanceSheet'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        $group->get('/ar-aging', [$handler, 'arAgingReport'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        $group->get('/cash-flow', [$handler, 'cashFlow'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        $group->get('/nrv', [$handler, 'nrvReport'])
            ->add(PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db));

        // Write operations — require accounting.je.create
        $group->post('/ar-aging/post-provision', [$handler, 'postBadDebtProvision'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_JE_CREATE, $db));

        $group->post('/nrv/post-writedown', [$handler, 'postNrvWriteDown'])
            ->add(PermissionMiddleware::require(Permissions::ACCOUNTING_JE_CREATE, $db));
    });
};
