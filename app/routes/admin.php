<?php

use App\Handlers\AdminSubscriptionHandler;
use App\Handlers\PlatformAdminHandler;
use App\Middleware\SuperAdminMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $handler = new \App\Routing\ContainerHandlerProxy($container, AdminSubscriptionHandler::class);
        $platformHandler = new \App\Routing\ContainerHandlerProxy($container, PlatformAdminHandler::class);

        $group->group('/admin', function (RouteCollectorProxy $group) use ($handler, $platformHandler) {
            // Subscriptions
            $group->get('/subscriptions', [$handler, 'listSubscriptions']);
            $group->post('/subscriptions/{id:[0-9]+}/activate', [$handler, 'activateSubscription']);
            $group->post('/subscriptions/{id:[0-9]+}/change-plan', [$handler, 'changePlan']);
            $group->post('/subscriptions/{id:[0-9]+}/expire', [$handler, 'expireSubscription']);
            $group->post('/subscriptions/{id:[0-9]+}/extend', [$handler, 'extendSubscription']);
            $group->post('/subscriptions/{id:[0-9]+}/security-check', [$handler, 'securityCheck']);
            $group->post('/subscriptions/{id:[0-9]+}/block', [$handler, 'blockSubscription']);
            
            // Plans
            $group->get('/plans', [$handler, 'listPlans']);
            $group->post('/plans', [$handler, 'createPlan']);
            $group->put('/plans/{code}', [$handler, 'updatePlan']);
            $group->delete('/plans/{code}', [$handler, 'deletePlan']);

            // Platform administration
            $group->get('/tenants', [$platformHandler, 'listTenants']);
            $group->patch('/tenants/{id:[0-9]+}/status', [$platformHandler, 'updateTenantStatus']);
            $group->get('/platform-audit', [$platformHandler, 'listPlatformAuditLogs']);
            $group->get('/operations', [$platformHandler, 'getOperationsOverview']);
        })->add(SuperAdminMiddleware::class);
};
