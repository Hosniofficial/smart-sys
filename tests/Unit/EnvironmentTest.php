<?php

declare(strict_types=1);

use App\Utils\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['SMARTSYS_ENV_TEST_NEW'], $_ENV['SMARTSYS_ENV_TEST_KEEP']);
    }

    public function testProcessVariablesAreCopiedIntoEnvWhenMissing(): void
    {
        Environment::hydrateFromProcess(['SMARTSYS_ENV_TEST_NEW' => 'from-process']);

        self::assertSame('from-process', $_ENV['SMARTSYS_ENV_TEST_NEW']);
    }

    public function testExistingEnvValuesAreNeverOverridden(): void
    {
        $_ENV['SMARTSYS_ENV_TEST_KEEP'] = 'from-dotenv';
        Environment::hydrateFromProcess(['SMARTSYS_ENV_TEST_KEEP' => 'from-process']);

        self::assertSame('from-dotenv', $_ENV['SMARTSYS_ENV_TEST_KEEP']);
    }

    public function testNonStringEntriesAreIgnored(): void
    {
        Environment::hydrateFromProcess([0 => 'numeric-key', 'SMARTSYS_ENV_TEST_NEW' => 'ok']);

        self::assertArrayNotHasKey(0, $_ENV);
        self::assertSame('ok', $_ENV['SMARTSYS_ENV_TEST_NEW']);
    }
}
