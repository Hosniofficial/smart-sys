<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecuritySecretValidationTest extends TestCase
{
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'JWT_REFRESH_SECRET', 'TWO_FA_ENCRYPTION_KEY'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['JWT_SECRET'] = str_repeat('a', 40);
        $_ENV['JWT_REFRESH_SECRET'] = str_repeat('b', 40);
        unset($_ENV['TWO_FA_ENCRYPTION_KEY']);
        require_once dirname(__DIR__, 2) . '/config/container.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testRejectsIdenticalJwtSecrets(): void
    {
        $_ENV['JWT_REFRESH_SECRET'] = $_ENV['JWT_SECRET'];

        $this->expectException(RuntimeException::class);
        validateSecuritySecrets();
    }

    public function testMissingTwoFactorKeyDoesNotBlockApplicationStartup(): void
    {
        unset($_ENV['TWO_FA_ENCRYPTION_KEY']);

        validateSecuritySecrets();

        self::assertArrayNotHasKey('TWO_FA_ENCRYPTION_KEY', $_ENV);
    }
}
