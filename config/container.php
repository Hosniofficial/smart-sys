<?php

declare(strict_types=1);

use App\Handlers\AccountStatementHandler;
use App\Handlers\AccountingReportsHandler;
use App\Handlers\AdminSettingsHandler;
use App\Handlers\AdminSubscriptionHandler;
use App\Handlers\AdvancedReportsHandler;
use App\Handlers\AuditHandler;
use App\Handlers\AuthHandler;
use App\Handlers\BootstrapHandler;
use App\Handlers\BranchHandler;
use App\Handlers\BranchInventoryReportHandler;
use App\Handlers\CategoriesHandler;
use App\Handlers\CustomersHandler;
use App\Handlers\EmailVerificationHandler;
use App\Handlers\InventoryAnalyticsHandler;
use App\Handlers\InventoryHandler;
use App\Handlers\OpeningBalanceHandler;
use App\Handlers\PaymentMethodsHandler;
use App\Handlers\PaymentsHandler;
use App\Handlers\PosAnalyticsHandler;
use App\Handlers\ProductBranchHandler;
use App\Handlers\ProductsHandler;
use App\Handlers\PurchasesHandler;
use App\Handlers\RBACHandler;
use App\Handlers\ReturnsHandler;
use App\Handlers\SalesAnalyticsHandler;
use App\Handlers\SalesHandler;
use App\Handlers\SalesPriceCampaignsHandler;
use App\Handlers\SalesQuotationsHandler;
use App\Handlers\SessionsHandler;
use App\Handlers\SetupHandler;
use App\Handlers\ShiftsHandler;
use App\Handlers\StockAdjustmentHandler;
use App\Handlers\StockTransferHandler;
use App\Handlers\StrictSubscriptionHandler;
use App\Handlers\SubscriptionCronHandler;
use App\Handlers\SubscriptionHandler;
use App\Handlers\SuppliersHandler;
use App\Handlers\TerminalsHandler;
use App\Handlers\UsersHandler;
use App\Handlers\WarrantyHandler;
use App\Listeners\SecurityEventListener;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\RequestRateLimiter;
use App\Middleware\StrictSubscriptionMiddleware;
use App\Middleware\SubscriptionMiddleware;
use App\Repositories\SecurityEventRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SaleRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PaymentRepository;
use App\Security\Permissions;
use App\Services\Accounting\GLAccountResolver;
use App\Services\AccountingService;
use App\Services\CurrencyService;
use App\Services\JwtBlacklistService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\CashierSessionService;
use App\Services\CashVoucherService;
use App\Services\SaleApprovalService;
use App\Services\SalePaymentService;
use App\Services\SecurityEventDispatcher;
use App\Services\AuthSessionService;
use App\Services\UserSessionService;
use App\Services\SecurityLogger;
use DI\Container;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

use DI\ContainerBuilder;

return function (): Container {
    $containerBuilder = new ContainerBuilder();
    
    $containerBuilder->addDefinitions([
        // ── Configuration ─────────────────────────────────────────────────────
        'config.security' => fn() => require __DIR__ . '/security.php',

        PDO::class => function () {
            if (!class_exists('Database')) {
                require_once __DIR__ . '/database.php';
            }
            $db = new Database();
            return $db->pdo;
        },

        // ── Repositories ──────────────────────────────────────────────────────
        SettingsRepository::class => fn(PDO $pdo) => new SettingsRepository($pdo),
        SaleRepository::class     => fn(PDO $pdo) => new SaleRepository($pdo),
        PurchaseRepository::class => fn(PDO $pdo) => new PurchaseRepository($pdo),
        PaymentRepository::class  => fn(PDO $pdo) => new PaymentRepository($pdo),

        // ── Core Services ─────────────────────────────────────────────────────
        AccountingService::class => fn(PDO $pdo) => new AccountingService($pdo),
        CurrencyService::class   => fn(PDO $pdo) => new CurrencyService($pdo),

        // ── Domain Services ───────────────────────────────────────────────────
        // Note: PurchaseService and ReturnService require tenantId + userId at
        // runtime (per-request context), so they are NOT registered as singletons.
        // Handlers create them inline with the correct context.
        // They are listed here for documentation purposes only.
        // PurchaseService::class => ... (created per-request in handlers)
        // ReturnService::class   => ... (created per-request in handlers)

           'jwt.secret' => $_ENV['JWT_SECRET'] ?? throw new \RuntimeException('JWT_SECRET environment variable is not set'),

        JwtBlacklistService::class => function (Container $c) {
            return new JwtBlacklistService(
                $c->get(PDO::class),
                $c->get('jwt.secret')
            );
        },

    RequestRateLimiter::class => function (PDO $pdo) {
        $securityConfig = require __DIR__ . '/security.php';
        $rl = $securityConfig['rate_limiting'] ?? [];

        $enabled = (bool) ($rl['enabled'] ?? false);
        $rules = [];
        $options = [
            'ip_whitelist' => $rl['ip_whitelist'] ?? [],
            'trust_proxy' => $rl['trust_proxy'] ?? false,
            'trusted_proxies' => $rl['trusted_proxies'] ?? [],
            'headers' => $rl['headers'] ?? [
                'enabled' => false,
                'limit' => 'X-RateLimit-Limit',
                'remaining' => 'X-RateLimit-Remaining',
                'reset' => 'X-RateLimit-Reset',
            ],
        ];

        if ($enabled) {
            if (!empty($rl['rules']) && is_array($rl['rules'])) {
                foreach ($rl['rules'] as $r) {
                    if (!empty($r['path']) && isset($r['max_attempts'], $r['window'])) {
                        $rules[] = [
                            'path' => $r['path'],
                            'max' => (int) $r['max_attempts'],
                            'window' => (int) $r['window'],
                        ];
                    }
                }
            }

            if (empty($rules)) {
                $rules = [
                    ['path' => '/auth/login', 'max' => (int) ($rl['max_attempts'] ?? 10), 'window' => (int) ($rl['window'] ?? 600)],
                    ['path' => '/auth/register', 'max' => 5, 'window' => 1800],
                ];
            }
        }

        return new RequestRateLimiter($pdo, $rules, $options);
    },

    SecurityEventRepository::class => function (PDO $pdo) {
        return new SecurityEventRepository($pdo);
    },

    SecurityLogger::class => function (SecurityEventRepository $repository) {
        return new SecurityLogger($repository, [
            'enabled' => true,
            'log_auth_attempts' => true,
            'log_failed_logins' => true,
            'log_sensitive_operations' => true,
        ]);
    },

    SecurityEventListener::class => function (SecurityLogger $logger) {
        return new SecurityEventListener($logger);
    },

    SecurityEventDispatcher::class => function (Container $container) {
        $dispatcher = new SecurityEventDispatcher($container);
        $dispatcher->addListener(SecurityEventListener::class);
        return $dispatcher;
    },

    GLAccountResolver::class => function (PDO $pdo) {
        return new GLAccountResolver($pdo);
    },

    JwtAuthMiddleware::class => function (
        JwtBlacklistService $blacklistService,
        SecurityLogger $securityLogger,
        Container $container,
        UserSessionService $userSessionService
    ) {
        return new JwtAuthMiddleware($blacklistService, $securityLogger, $container, $userSessionService);
    },

    AuthSessionService::class => fn(PDO $pdo) => new AuthSessionService($pdo),
    UserSessionService::class => fn(PDO $pdo) => new UserSessionService($pdo),

    AuthHandler::class => function (
        PDO $pdo,
        JwtBlacklistService $blacklistService,
        SecurityLogger $securityLogger,
        AuthSessionService $authSession
    ) {
        return new AuthHandler(
            $pdo,
            $blacklistService,
            $securityLogger,
            $authSession
        );
    },

    ProductsHandler::class => fn(PDO $pdo) => new ProductsHandler($pdo),
    SalesHandler::class => fn(PDO $pdo) => new SalesHandler($pdo),
    SalesPriceCampaignsHandler::class => fn(PDO $pdo) => new SalesPriceCampaignsHandler($pdo),
    SalesQuotationsHandler::class => fn(PDO $pdo) => new SalesQuotationsHandler($pdo),

    UsersHandler::class => fn(PDO $pdo, SecurityLogger $securityLogger) =>
        new UsersHandler($pdo, $securityLogger),

    StrictSubscriptionHandler::class => fn(PDO $pdo) => new StrictSubscriptionHandler($pdo),
    SubscriptionHandler::class => fn(PDO $pdo) => new SubscriptionHandler($pdo),
    EmailVerificationHandler::class => fn(PDO $pdo) => new EmailVerificationHandler($pdo),
    PurchasesHandler::class => fn(PDO $pdo) => new PurchasesHandler($pdo),

    ReturnsHandler::class => function (PDO $pdo) {
        $logger = new Logger('returns');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/returns.log', Logger::DEBUG));
        return new ReturnsHandler($pdo, $logger);
    },

    BranchHandler::class => function (PDO $pdo) {
        $logger = new Logger('branch');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/branch.log', Logger::DEBUG));
        return new BranchHandler($pdo, $logger);
    },

    BranchInventoryReportHandler::class => fn(PDO $pdo) => new BranchInventoryReportHandler($pdo),
    StockAdjustmentHandler::class       => fn(PDO $pdo) => new StockAdjustmentHandler($pdo),
    StockTransferHandler::class         => fn(PDO $pdo) => new StockTransferHandler($pdo),
    BootstrapHandler::class             => fn(PDO $pdo) => new BootstrapHandler($pdo),

    SetupHandler::class => fn(PDO $pdo) => new SetupHandler($pdo),
    OpeningBalanceHandler::class => fn(PDO $pdo) => new OpeningBalanceHandler($pdo),
    App\Handlers\SettingsHandler::class => fn(PDO $pdo) => new App\Handlers\SettingsHandler($pdo),
    ProductBranchHandler::class => fn(PDO $pdo) => new ProductBranchHandler($pdo),
    RBACHandler::class => fn(PDO $pdo) => new RBACHandler($pdo),
    SalesAnalyticsHandler::class => fn(PDO $pdo) => new SalesAnalyticsHandler($pdo),
    InventoryAnalyticsHandler::class => fn(PDO $pdo) => new InventoryAnalyticsHandler($pdo),
    PosAnalyticsHandler::class => fn(PDO $pdo) => new PosAnalyticsHandler($pdo),
    AuditHandler::class => fn(PDO $pdo) => new AuditHandler($pdo),
    AccountingReportsHandler::class => fn(PDO $pdo) => new AccountingReportsHandler($pdo),
    AdvancedReportsHandler::class => fn(PDO $pdo) => new AdvancedReportsHandler($pdo),
    CustomersHandler::class => fn(PDO $pdo) => new CustomersHandler($pdo),
    SuppliersHandler::class => fn(PDO $pdo) => new SuppliersHandler($pdo),
    PaymentsHandler::class => fn(PDO $pdo) => new PaymentsHandler($pdo),
    PaymentMethodsHandler::class => fn(PDO $pdo) => new PaymentMethodsHandler($pdo),
    App\Handlers\NotificationHandler::class => fn(PDO $pdo) => new App\Handlers\NotificationHandler($pdo),
    App\Handlers\CashVouchersHandler::class => fn(PDO $pdo) => new App\Handlers\CashVouchersHandler($pdo),
    App\Handlers\CostCentersHandler::class => fn(PDO $pdo) => new App\Handlers\CostCentersHandler($pdo),
    CashVoucherService::class              => fn(PDO $pdo) => new CashVoucherService($pdo, 0), // tenantId resolved per-request
    AccountStatementHandler::class => fn(PDO $pdo) => new AccountStatementHandler($pdo),
    SessionsHandler::class         => fn(PDO $pdo) => new SessionsHandler($pdo),
    CashierSessionService::class   => fn(PDO $pdo) => new CashierSessionService($pdo),
    TerminalsHandler::class => fn(PDO $pdo) => new TerminalsHandler($pdo),
    CategoriesHandler::class => fn(PDO $pdo) => new CategoriesHandler($pdo),
    ShiftsHandler::class => fn(PDO $pdo) => new ShiftsHandler($pdo),

    InventoryHandler::class => function (PDO $pdo) {
        $logger = new Logger('inventory');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/debug.log', Logger::DEBUG));
        return new InventoryHandler($pdo, $logger);
    },

    SubscriptionMiddleware::class => fn(PDO $pdo) => new SubscriptionMiddleware($pdo),
    StrictSubscriptionMiddleware::class => fn(PDO $pdo) => new StrictSubscriptionMiddleware($pdo),
    AdminSettingsHandler::class => fn(PDO $pdo) => new AdminSettingsHandler($pdo),
    SubscriptionCronHandler::class => fn(PDO $pdo) => new SubscriptionCronHandler($pdo),
    WarrantyHandler::class => fn(PDO $pdo) => new WarrantyHandler($pdo),

    // ── PermissionMiddleware factory ──────────────────────────────────────────
    // Returns a callable that creates a PermissionMiddleware instance.
    // Usage in routes:
    //   $perm = $container->get('permission.factory');
    //   $group->delete('/{id}', ...)->add($perm(Permissions::SALE_VOID));
    //   $group->get('', ...)->add($perm->anyOf([Permissions::SALE_VIEW, Permissions::REPORT_SALES]));
    'permission.factory' => function (PDO $pdo): \Closure {
        return function (string $permission) use ($pdo): PermissionMiddleware {
            return PermissionMiddleware::require($permission, $pdo);
        };
    },

    // Convenience: pre-built middleware instances for the most common permissions.
    // Add more as needed when wiring routes in Week 2.
    'perm.sale.view'            => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_VIEW, $db),
    'perm.sale.create'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_CREATE, $db),
    'perm.sale.edit'            => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_EDIT, $db),
    'perm.sale.void'            => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_VOID, $db),
    'perm.sale.payment'         => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_PAYMENT_CREATE, $db),
    'perm.sale.approve'         => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_APPROVAL_APPROVE, $db),
    'perm.sale.reject'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::SALE_APPROVAL_REJECT, $db),
    'perm.purchase.view'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::PURCHASE_VIEW, $db),
    'perm.purchase.create'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::PURCHASE_CREATE, $db),
    'perm.purchase.edit'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::PURCHASE_EDIT, $db),
    'perm.purchase.delete'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::PURCHASE_DELETE, $db),
    'perm.return.view'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::RETURN_VIEW, $db),
    'perm.return.create'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::RETURN_CREATE, $db),
    'perm.return.approve'       => fn(PDO $db) => PermissionMiddleware::require(Permissions::RETURN_APPROVE, $db),
    'perm.user.view'            => fn(PDO $db) => PermissionMiddleware::require(Permissions::USER_VIEW, $db),
    'perm.user.create'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::USER_CREATE, $db),
    'perm.user.edit'            => fn(PDO $db) => PermissionMiddleware::require(Permissions::USER_EDIT, $db),
    'perm.user.delete'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::USER_DELETE, $db),
    'perm.settings.view'        => fn(PDO $db) => PermissionMiddleware::anyOf([Permissions::SETTINGS_VIEW, Permissions::SETTINGS_MANAGE], $db),
    'perm.settings.manage'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::SETTINGS_MANAGE, $db),
    'perm.product.view'         => fn(PDO $db) => PermissionMiddleware::require(Permissions::PRODUCT_VIEW, $db),
    'perm.product.create'       => fn(PDO $db) => PermissionMiddleware::require(Permissions::PRODUCT_CREATE, $db),
    'perm.product.edit'         => fn(PDO $db) => PermissionMiddleware::require(Permissions::PRODUCT_EDIT, $db),
    'perm.product.delete'       => fn(PDO $db) => PermissionMiddleware::require(Permissions::PRODUCT_DELETE, $db),
    'perm.inventory.view'       => fn(PDO $db) => PermissionMiddleware::require(Permissions::INVENTORY_VIEW, $db),
    'perm.inventory.adjust'     => fn(PDO $db) => PermissionMiddleware::require(Permissions::INVENTORY_ADJUST, $db),
    'perm.inventory.transfer'   => fn(PDO $db) => PermissionMiddleware::require(Permissions::INVENTORY_TRANSFER, $db),
    'perm.report.sales'         => fn(PDO $db) => PermissionMiddleware::require(Permissions::REPORT_SALES, $db),
    'perm.report.financial'     => fn(PDO $db) => PermissionMiddleware::require(Permissions::REPORT_FINANCIAL, $db),
    'perm.report.inventory'     => fn(PDO $db) => PermissionMiddleware::require(Permissions::REPORT_INVENTORY, $db),
    'perm.report.purchases'     => fn(PDO $db) => PermissionMiddleware::require(Permissions::REPORT_PURCHASES, $db),
    'perm.branch.view'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::BRANCH_VIEW, $db),
    'perm.branch.create'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::BRANCH_CREATE, $db),
    'perm.branch.edit'          => fn(PDO $db) => PermissionMiddleware::require(Permissions::BRANCH_EDIT, $db),
    'perm.branch.delete'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::BRANCH_DELETE, $db),
    'perm.customer.view'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::CUSTOMER_VIEW, $db),
    'perm.customer.create'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::CUSTOMER_CREATE, $db),
    'perm.customer.edit'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::CUSTOMER_EDIT, $db),
    'perm.customer.delete'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::CUSTOMER_DELETE, $db),
    'perm.supplier.view'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::SUPPLIER_VIEW, $db),
    'perm.supplier.create'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::SUPPLIER_CREATE, $db),
    'perm.supplier.edit'        => fn(PDO $db) => PermissionMiddleware::require(Permissions::SUPPLIER_EDIT, $db),
    'perm.supplier.delete'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::SUPPLIER_DELETE, $db),
    'perm.voucher.view'         => fn(PDO $db) => PermissionMiddleware::require(Permissions::VOUCHER_VIEW, $db),
    'perm.voucher.create'       => fn(PDO $db) => PermissionMiddleware::require(Permissions::VOUCHER_CREATE, $db),
    'perm.voucher.delete'       => fn(PDO $db) => PermissionMiddleware::require(Permissions::VOUCHER_DELETE, $db),
    'perm.accounting.view'      => fn(PDO $db) => PermissionMiddleware::require(Permissions::ACCOUNTING_VIEW, $db),
    'perm.accounting.period'    => fn(PDO $db) => PermissionMiddleware::require(Permissions::ACCOUNTING_PERIOD_MANAGE, $db),
    'perm.rbac.manage'          => fn(PDO $db) => PermissionMiddleware::anyOf([Permissions::ROLE_EDIT, Permissions::PERMISSION_ASSIGN], $db),
    ]);
    
    return $containerBuilder->build();
};
