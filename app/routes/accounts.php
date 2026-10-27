<?php

use App\Handlers\AccountStatementHandler;
use App\Middleware\PermissionMiddleware;
use App\Security\Permissions;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $handler = $container->get(AccountStatementHandler::class);
        $db = $container->get(PDO::class);
        $accountRead = PermissionMiddleware::anyOf([
            Permissions::ACCOUNTING_VIEW,
            Permissions::REPORT_FINANCIAL,
            Permissions::PAYMENT_METHOD_VIEW,
            Permissions::SETTINGS_VIEW,
            Permissions::SETTINGS_MANAGE,
            Permissions::VOUCHER_VIEW,
            Permissions::ACCOUNTING_OWNER_CONTRIBUTION_VIEW,
            Permissions::ACCOUNTING_OWNER_CONTRIBUTION_CREATE,
        ], $db);

        // Accounts routes
        $group->group('/accounts', function (RouteCollectorProxy $group) use ($handler, $accountRead) {
            // legacy accounts list
            $group->get('', [$handler, 'getAccounts'])
                ->add($accountRead);

            // grouped accounts (tenant vs global)
            $group->get('/grouped', [$handler, 'getGroupedAccounts'])
                ->add($accountRead);
        });
};
