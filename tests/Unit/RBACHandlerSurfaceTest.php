<?php

declare(strict_types=1);

use App\Handlers\RBACHandler;
use PHPUnit\Framework\TestCase;

final class RBACHandlerSurfaceTest extends TestCase
{
    public function testOnlyRegisteredRbacHandlerOperationsRemainPublic(): void
    {
        $handler = new \ReflectionClass(RBACHandler::class);
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $handler->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === RBACHandler::class
            )
        );

        self::assertEqualsCanonicalizing(
            ['__construct', 'getUsers', 'createUser', 'updateUser', 'deleteUser', 'getRoles', 'hasPermission'],
            $publicMethods
        );
    }
}
