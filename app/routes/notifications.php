<?php

use App\Handlers\NotificationHandler;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $notificationHandler = $container->get(NotificationHandler::class);

        // Notification routes
        $group->group('/notifications', function (RouteCollectorProxy $group) use ($notificationHandler) {
            $group->get('', [$notificationHandler, 'getNotifications']);
            $group->put('/{id}/read', [$notificationHandler, 'markAsRead']);
        });
};
