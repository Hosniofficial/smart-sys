<?php

declare(strict_types=1);

use App\Handlers\WarrantyHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

/**
 * End-to-end check of the warranty attachment flow against a real MySQL schema:
 * upload validation, private storage, signed downloads, tenant isolation, and deletion.
 */
final class WarrantyAttachmentMySqlIntegrationTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;
    private static int $tenantA;
    private static int $tenantB;
    private static int $userA;
    private static int $userB;
    private static int $requestA;
    private static string $uploadsDir;
    private static ?string $previousUploadsDir = null;
    private static ?string $previousJwtSecret = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_WARRANTY_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_MYSQL_WARRANTY_TESTS=1 to create an isolated temporary MySQL schema.');
        }

        \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            self::fail('Refusing MySQL integration tests unless DB_HOST is local.');
        }
        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

        self::$schema = 'smartsys_warranty_it_' . bin2hex(random_bytes(8));
        self::$server = new mysqli($host, $username, $password, '', $port);
        self::$server->set_charset('utf8mb4');
        self::$server->query('CREATE DATABASE `' . self::$schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$server->select_db(self::$schema);

        try {
            MySqlSchemaDumpImporter::import(self::$server, __DIR__ . '/Fixtures/mysql_schema.sql');
            self::$db = new PDO(
                "mysql:host={$host};port={$port};dbname=" . self::$schema . ';charset=utf8mb4',
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            self::seed();
        } catch (Throwable $error) {
            self::dropSchema();
            throw $error;
        }

        self::$uploadsDir = sys_get_temp_dir() . '/smartsys-warranty-it-' . bin2hex(random_bytes(6));
        mkdir(self::$uploadsDir, 0700, true);
        self::$previousUploadsDir = isset($_ENV['UPLOADS_DIR']) ? (string) $_ENV['UPLOADS_DIR'] : null;
        self::$previousJwtSecret = isset($_ENV['JWT_SECRET']) ? (string) $_ENV['JWT_SECRET'] : null;
        $_ENV['UPLOADS_DIR'] = self::$uploadsDir;
        $_ENV['JWT_SECRET'] = 'warranty-integration-test-signing-secret';
    }

    public static function tearDownAfterClass(): void
    {
        self::dropSchema();
        foreach ([self::$previousUploadsDir, self::$previousJwtSecret] as $i => $previous) {
            $key = $i === 0 ? 'UPLOADS_DIR' : 'JWT_SECRET';
            if ($previous === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previous;
            }
        }
        if (isset(self::$uploadsDir) && is_dir(self::$uploadsDir)) {
            self::removeDirectory(self::$uploadsDir);
        }
    }

    private static function dropSchema(): void
    {
        self::$db = null;
        if (self::$schema !== null && self::$server instanceof mysqli) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
        }
        self::$schema = null;
        if (self::$server instanceof mysqli) {
            self::$server->close();
            self::$server = null;
        }
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function seed(): void
    {
        $db = self::$db;
        $db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
                   VALUES ('warranty-it', 'Warranty IT', 0, 'EGP', 30, '{}', 1)");
        $planId = (int) $db->lastInsertId();

        foreach (['A', 'B'] as $label) {
            $db->prepare("INSERT INTO tenants (company_name, plan_id, status) VALUES (?, ?, 'active')")
                ->execute(["Warranty IT tenant {$label}", $planId]);
            $tenantId = (int) $db->lastInsertId();
            $db->prepare(
                "INSERT INTO users (username, password, name, status, tenant_id, email)
                 VALUES (?, 'test-only', ?, 'active', ?, ?)"
            )->execute(["warranty-it-{$label}-" . bin2hex(random_bytes(3)), "User {$label}", $tenantId, strtolower($label) . bin2hex(random_bytes(3)) . '@example.test']);
            $userId = (int) $db->lastInsertId();

            if ($label === 'A') {
                self::$tenantA = $tenantId;
                self::$userA = $userId;
                $db->prepare('INSERT INTO warranty_requests (tenant_id, created_by) VALUES (?, ?)')
                    ->execute([$tenantId, $userId]);
                self::$requestA = (int) $db->lastInsertId();
            } else {
                self::$tenantB = $tenantId;
                self::$userB = $userId;
            }
        }
    }

    private function handler(): WarrantyHandler
    {
        return new WarrantyHandler(self::$db);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wit');
        file_put_contents($path, $content);

        return $path;
    }

    private function uploadAs(int $tenantId, int $userId, int $requestId, string $clientName, string $content): array
    {
        $path = $this->tempFile($content);
        $file = new UploadedFile($path, $clientName, 'image/png', strlen($content), UPLOAD_ERR_OK);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/v1/warranty/requests/{$requestId}/attachments")
            ->withAttribute('tenant_id', $tenantId)
            ->withAttribute('user', ['id' => $userId])
            ->withUploadedFiles(['file' => $file]);

        $response = $this->handler()->uploadAttachment($request, new Response(), ['id' => (string) $requestId]);
        $payload = json_decode((string) $response->getBody(), true) ?: [];

        return [$response->getStatusCode(), $payload];
    }

    private function download(string $path): Response
    {
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);
        preg_match('#/attachments/(\d+)/file#', (string) $parts['path'], $m);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', (string) $parts['path'])
            ->withQueryParams($query);

        return $this->handler()->downloadAttachment($request, new Response(), ['attachmentId' => $m[1]]);
    }

    public function testScriptDisguisedAsImageIsRejectedAndNothingIsStored(): void
    {
        $before = self::$db->query('SELECT COUNT(*) FROM warranty_attachments')->fetchColumn();

        [$status] = $this->uploadAs(self::$tenantA, self::$userA, self::$requestA, 'shell.php', "<?php system(\$_GET['c']); ?>");

        self::assertSame(422, $status);
        self::assertSame($before, self::$db->query('SELECT COUNT(*) FROM warranty_attachments')->fetchColumn());
        self::assertSame([], glob(self::$uploadsDir . '/warranty/*') ?: []);
    }

    public function testValidImageIsStoredPrivatelyWithSniffedExtensionAndServedViaSignedLink(): void
    {
        $png = base64_decode(self::PNG_1X1);

        // Client claims a .php name; the stored name/extension must come from the content.
        [$status, $payload] = $this->uploadAs(self::$tenantA, self::$userA, self::$requestA, 'evil.php', $png);
        self::assertSame(200, $status, json_encode($payload));

        $row = self::$db->query('SELECT * FROM warranty_attachments ORDER BY id DESC LIMIT 1')->fetch();
        self::assertStringEndsWith('.png', $row['filename']);
        self::assertSame('image/png', $row['mime_type']);
        self::assertSame('evil.png', $row['original_name'], 'display name must carry the real extension');

        $stored = self::$uploadsDir . '/warranty/' . $row['filename'];
        self::assertFileExists($stored);
        self::assertStringNotContainsString(
            realpath(dirname(__DIR__, 2) . '/public') ?: '/nonexistent',
            (string) realpath($stored),
            'attachments must live outside the web root'
        );

        $response = $this->download((string) $payload['data']['url']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame($png, (string) $response->getBody());
    }

    public function testSignedLinkCannotBeTamperedReusedAcrossTenantsOrUsedAfterExpiry(): void
    {
        [, $payload] = $this->uploadAs(self::$tenantA, self::$userA, self::$requestA, 'a.png', base64_decode(self::PNG_1X1));
        $path = (string) $payload['data']['url'];

        self::assertSame(200, $this->download($path)->getStatusCode());

        $tamperedSig = preg_replace('/sig=[0-9a-f]+/', 'sig=' . str_repeat('0', 64), $path);
        self::assertSame(404, $this->download($tamperedSig)->getStatusCode(), 'forged signature');

        $otherTenant = preg_replace('/tenant=\d+/', 'tenant=' . self::$tenantB, $path);
        self::assertSame(404, $this->download($otherTenant)->getStatusCode(), 'link reused for another tenant');

        $attId = (int) $payload['data']['id'];
        $expired = (new \App\Security\AttachmentUrlSigner('warranty-integration-test-signing-secret'))
            ->path($attId, self::$tenantA, time() - 7200);
        self::assertSame(404, $this->download($expired)->getStatusCode(), 'expired link');
    }

    public function testCannotAttachToAnotherTenantsRequest(): void
    {
        [$status] = $this->uploadAs(self::$tenantB, self::$userB, self::$requestA, 'b.png', base64_decode(self::PNG_1X1));

        self::assertSame(404, $status);
        $leaked = self::$db->prepare('SELECT COUNT(*) FROM warranty_attachments WHERE tenant_id = ? AND warranty_request_id = ?');
        $leaked->execute([self::$tenantB, self::$requestA]);
        self::assertSame(0, (int) $leaked->fetchColumn());
    }

    public function testRequestDetailsExposeSignedUrlsAndDeleteRemovesRowAndFile(): void
    {
        [, $payload] = $this->uploadAs(self::$tenantA, self::$userA, self::$requestA, 'c.png', base64_decode(self::PNG_1X1));
        $attId = (int) $payload['data']['id'];
        $filename = (string) $payload['data']['filename'];

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/warranty/requests/' . self::$requestA)
            ->withAttribute('tenant_id', self::$tenantA)
            ->withAttribute('user', ['id' => self::$userA]);
        $details = $this->handler()->get($request, new Response(), ['id' => (string) self::$requestA]);
        self::assertSame(200, $details->getStatusCode());
        $attachments = json_decode((string) $details->getBody(), true)['data']['attachments'];
        $match = array_values(array_filter($attachments, fn ($a) => (int) $a['id'] === $attId));
        self::assertNotEmpty($match);
        self::assertStringContainsString('/api/v1/warranty/attachments/' . $attId . '/file?', (string) $match[0]['url']);

        // Another tenant cannot delete it.
        $foreign = $this->handler()->deleteAttachment(
            (new ServerRequestFactory())->createServerRequest('DELETE', '/x')
                ->withAttribute('tenant_id', self::$tenantB)->withAttribute('user', ['id' => self::$userB]),
            new Response(),
            ['attachmentId' => (string) $attId]
        );
        self::assertSame(403, $foreign->getStatusCode());
        self::assertFileExists(self::$uploadsDir . '/warranty/' . $filename);

        $own = $this->handler()->deleteAttachment(
            (new ServerRequestFactory())->createServerRequest('DELETE', '/x')
                ->withAttribute('tenant_id', self::$tenantA)->withAttribute('user', ['id' => self::$userA]),
            new Response(),
            ['attachmentId' => (string) $attId]
        );
        self::assertSame(200, $own->getStatusCode());
        self::assertFileDoesNotExist(self::$uploadsDir . '/warranty/' . $filename);
        self::assertSame(404, $this->download($payload['data']['url'])->getStatusCode());
    }
}
