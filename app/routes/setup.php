<?php

use App\Handlers\SetupHandler;
use App\Handlers\OwnerContributionHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $setupHandler = $container->get(SetupHandler::class);
        $ownerContributionHandler = $container->get(OwnerContributionHandler::class);

        $group->group('/setup', function (RouteCollectorProxy $group) use ($setupHandler) {
            // Get setup status and current settings
            $group->get('/status', function (Request $request, Response $response) use ($setupHandler) {
                return $setupHandler->getSetupStatus($request, $response);
            });

            // Save setup configuration
            $group->post('/save', function (Request $request, Response $response) use ($setupHandler) {
                return $setupHandler->saveSetup($request, $response);
            });

            // Skip setup (complete later)
            $group->post('/skip', function (Request $request, Response $response) use ($setupHandler) {
                return $setupHandler->skipSetup($request, $response);
            });
        });

        // Owner Contributions endpoints
        $group->group('/setup/owner-contribution', function (RouteCollectorProxy $group) use ($ownerContributionHandler) {
            // Create new contribution
            $group->post('/create', function (Request $request, Response $response) use ($ownerContributionHandler) {
                return $ownerContributionHandler->create($request, $response);
            });

            // Get contribution by ID
            $group->get('/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($ownerContributionHandler) {
                return $ownerContributionHandler->getById($request, $response, $args);
            });

            // Void (reverse) a contribution
            $group->post('/{id:[0-9]+}/void', function (Request $request, Response $response, array $args) use ($ownerContributionHandler) {
                return $ownerContributionHandler->void($request, $response, $args);
            });
        });

        // List contributions (outside nested group for cleaner path)
        $group->get('/setup/owner-contributions', function (Request $request, Response $response) use ($ownerContributionHandler) {
            return $ownerContributionHandler->list($request, $response);
        });
};
