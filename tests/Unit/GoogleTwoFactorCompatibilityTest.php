<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

final class GoogleTwoFactorCompatibilityTest extends TestCase
{
    public function testRfc6238Sha1VectorRemainsCompatible(): void
    {
        $authenticator = new Google2FA();
        $authenticator->setOneTimePasswordLength(8);

        self::assertTrue(
            $authenticator->verifyKey('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '94287082', 0, 1)
        );
        self::assertFalse(
            $authenticator->verifyKey('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '00000000', 0, 1)
        );
    }
}
