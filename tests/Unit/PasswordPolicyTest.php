<?php

declare(strict_types=1);

use App\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsPasswordsWithinConfiguredBounds(): void
    {
        self::assertNull(PasswordPolicy::validationMessage('validPass123'));
        self::assertNull(PasswordPolicy::validationMessage(str_repeat('a', 72)));
    }

    public function testRejectsPasswordsBelowMinimumLength(): void
    {
        self::assertNotNull(PasswordPolicy::validationMessage('short7'));
    }

    public function testRejectsPasswordsAboveBcryptInputLimit(): void
    {
        self::assertNotNull(PasswordPolicy::validationMessage(str_repeat('a', 73)));
    }
}
