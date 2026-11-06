<?php

use App\Handlers\AdminSubscriptionHandler;
use App\Middleware\SuperAdminMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $handler = $container->get(AdminSubscriptionHandler::class);

        $group->group('/admin', function (RouteCollectorProxy $group) use ($handler) {
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
        })->add($container->get(SuperAdminMiddleware::class));
};
