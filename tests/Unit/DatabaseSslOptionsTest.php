<?php

declare(strict_types=1);

use App\Services\DatabaseSslOptions;
use PHPUnit\Framework\TestCase;

final class DatabaseSslOptionsTest extends TestCase
{
    public function testEmptyModeLeavesSslOptionsUnset(): void
    {
        self::assertSame([], DatabaseSslOptions::forMode(''));
    }

    public function testRequiredModeEnablesTlsWithoutClaimingCertificateVerification(): void
    {
        self::assertSame(
            [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false],
            DatabaseSslOptions::forMode('REQUIRED')
        );
    }

    public function testVerifiedModesRequireReadableCaAndEnableVerification(): void
    {
        $caPath = tempnam(sys_get_temp_dir(), 'smartsys-ca-');
        self::assertNotFalse($caPath);
        file_put_contents($caPath, 'test certificate');

        try {
            self::assertSame([
                PDO::MYSQL_ATTR_SSL_CA => $caPath,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ], DatabaseSslOptions::forMode('VERIFY_IDENTITY', $caPath));
        } finally {
            unlink($caPath);
        }
    }

    public function testBundledAivenProjectCaIsValidAndUsableForVerifiedTls(): void
    {
        $caPath = dirname(__DIR__, 2) . '/certs/aiven-project-ca.pem';
        self::assertFileExists($caPath);

        $certificate = openssl_x509_read((string) file_get_contents($caPath));
        self::assertNotFalse($certificate);

        $options = DatabaseSslOptions::forMode('VERIFY_IDENTITY', $caPath);
        self::assertSame($caPath, $options[PDO::MYSQL_ATTR_SSL_CA]);
        self::assertTrue($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function testVerifiedModesRejectMissingCa(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseSslOptions::forMode('VERIFY_CA');
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseSslOptions::forMode('VERIFY_EVERYTHING');
    }
}
