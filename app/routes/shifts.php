<?php

use App\Handlers\ShiftsHandler;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $handler = $container->get(ShiftsHandler::class);
        
        $group->group('/shifts', function (RouteCollectorProxy $group) use ($handler) {
            $group->get('/current', [$handler, 'current']);
        });
};
