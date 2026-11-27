<?php

declare(strict_types=1);

use App\Security\AttachmentUrlSigner;
use PHPUnit\Framework\TestCase;

final class AttachmentUrlSignerTest extends TestCase
{
    public function testValidSignatureVerifiesUntilExpiry(): void
    {
        $signer = new AttachmentUrlSigner('unit-test-signing-secret');
        $signed = $signer->sign(42, 7, 1_000_000, 600);

        self::assertTrue($signer->verify(42, 7, $signed['exp'], $signed['sig'], 1_000_100));
        self::assertFalse($signer->verify(42, 7, $signed['exp'], $signed['sig'], 1_000_601));
    }

    public function testSignatureIsBoundToAttachmentTenantAndExpiry(): void
    {
        $signer = new AttachmentUrlSigner('unit-test-signing-secret');
        $signed = $signer->sign(42, 7, 1_000_000, 600);

        self::assertFalse($signer->verify(43, 7, $signed['exp'], $signed['sig'], 1_000_100), 'other attachment');
        self::assertFalse($signer->verify(42, 8, $signed['exp'], $signed['sig'], 1_000_100), 'other tenant');
        self::assertFalse($signer->verify(42, 7, $signed['exp'] + 3600, $signed['sig'], 1_000_100), 'extended expiry');
        self::assertFalse($signer->verify(42, 7, $signed['exp'], str_repeat('0', 64), 1_000_100), 'forged signature');
    }

    public function testDifferentSecretsProduceIncompatibleSignatures(): void
    {
        $a = new AttachmentUrlSigner('secret-a');
        $b = new AttachmentUrlSigner('secret-b');
        $signed = $a->sign(1, 1, 1_000_000);

        self::assertFalse($b->verify(1, 1, $signed['exp'], $signed['sig'], 1_000_001));
    }

    public function testPathContainsEverythingTheDownloadRouteNeeds(): void
    {
        $path = (new AttachmentUrlSigner('s'))->path(5, 9, 1_000_000);

        self::assertStringStartsWith('/api/v1/warranty/attachments/5/file?tenant=9&exp=1000600&sig=', $path);
    }

    public function testEmptySecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AttachmentUrlSigner('');
    }
}
