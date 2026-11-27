<?php

declare(strict_types=1);

use App\Security\SecureUpload;
use PHPUnit\Framework\TestCase;

final class SecureUploadTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testPhpScriptIsRejectedRegardlessOfClientName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SecureUpload::inspectBytes("<?php system(\$_GET['c']); ?>", 30);
    }

    public function testHtmlAndSvgAreRejected(): void
    {
        foreach ([
            '<html><script>alert(1)</script></html>',
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        ] as $payload) {
            try {
                SecureUpload::inspectBytes($payload, strlen($payload));
                self::fail('Active content must be rejected: ' . substr($payload, 0, 20));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPolyglotWithPhpAfterValidImageHeaderStillStoresAsImageExtensionOnly(): void
    {
        $png = base64_decode(self::PNG_1X1) . "<?php echo 'x'; ?>";
        $info = SecureUpload::inspectBytes($png, strlen($png));

        // The stored name is built from the sniffed type, so it can never end in .php.
        self::assertSame('png', $info['extension']);
        self::assertSame('image/png', $info['mime']);
    }

    public function testAllowedTypesMapToCanonicalExtensions(): void
    {
        $png = SecureUpload::inspectBytes(base64_decode(self::PNG_1X1), 70);
        self::assertSame(['png', 'image/png'], [$png['extension'], $png['mime']]);

        $pdf = SecureUpload::inspectBytes("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF", 60);
        self::assertSame(['pdf', 'application/pdf'], [$pdf['extension'], $pdf['mime']]);
    }

    public function testEmptyContentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SecureUpload::inspectBytes('', 0);
    }

    public function testStoredNameValidationBlocksTraversalAndHiddenFiles(): void
    {
        self::assertTrue(SecureUpload::isSafeStoredName('wr_1_1700000000_ab12cd34ef56ab12.png'));
        foreach (['../.env', '..\\x.png', 'a/b.png', '.htaccess', '', 'x..png', "x\0.png", 'a b.png'] as $bad) {
            self::assertFalse(SecureUpload::isSafeStoredName($bad), $bad);
        }
    }

    public function testServeContentTypesAreAllowlistedOnly(): void
    {
        self::assertSame('image/png', SecureUpload::contentTypeForExtension('PNG'));
        self::assertSame('application/pdf', SecureUpload::contentTypeForExtension('pdf'));
        foreach (['php', 'phtml', 'phar', 'svg', 'html', 'htm', 'js', ''] as $ext) {
            self::assertNull(SecureUpload::contentTypeForExtension($ext), $ext);
        }
    }
}
