<?php

declare(strict_types=1);

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class AuthRoutesRegistrationTest extends TestCase
{
    public function testAuthRoutesRegisterWithoutConstructingDatabaseBackedHandlers(): void
    {
        $container = new Container();
        $app = AppFactory::create();
        $authRoutes = require __DIR__ . '/../../app/routes/auth.php';

        $app->group('/api/v1/auth', function ($group) use ($authRoutes, $container): void {
            $authRoutes($group, $container);
        });

        $paths = array_map(
            static fn ($route): string => $route->getPattern(),
            $app->getRouteCollector()->getRoutes()
        );

        self::assertContains('/api/v1/auth/login', $paths);
        self::assertContains('/api/v1/auth/register', $paths);
    }
}
