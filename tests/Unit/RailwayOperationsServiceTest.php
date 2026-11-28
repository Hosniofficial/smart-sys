<?php

declare(strict_types=1);

use App\Services\RailwayOperationsService;
use PHPUnit\Framework\TestCase;

final class RailwayOperationsServiceTest extends TestCase
{
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ([
            'RAILWAY_PROJECT_ACCESS_TOKEN',
            'RAILWAY_ENVIRONMENT_ID',
            'RAILWAY_API_SERVICE_ID',
            'RAILWAY_DATABASE_SERVICE_ID',
            'RAILWAY_VOLUME_INSTANCE_IDS',
        ] as $key) {
            if (array_key_exists($key, $this->originalEnvironment)) {
                $_ENV[$key] = $this->originalEnvironment[$key];
            } else {
                unset($_ENV[$key]);
            }
        }

        parent::tearDown();
    }

    public function testMissingRailwayConfigurationIsReportedWithoutCallingExternalApi(): void
    {
        $calls = 0;
        $service = new RailwayOperationsService(function () use (&$calls): array {
            $calls++;
            return [];
        });

        $overview = $service->getOverview();

        self::assertSame('not_configured', $overview['status']);
        self::assertContains('RAILWAY_PROJECT_ACCESS_TOKEN', $overview['missing_configuration']);
        self::assertSame(0, $calls);
    }

    public function testConfiguredOverviewReadsDeploymentAndBackupDataWithoutReturningToken(): void
    {
        $this->setRailwayEnvironment([
            'RAILWAY_PROJECT_ACCESS_TOKEN' => 'project-token-test-only',
            'RAILWAY_ENVIRONMENT_ID' => 'environment-1',
            'RAILWAY_API_SERVICE_ID' => 'api-service-1',
            'RAILWAY_DATABASE_SERVICE_ID' => 'database-service-1',
            'RAILWAY_VOLUME_INSTANCE_IDS' => 'database-volume-1',
        ]);
        $seenToken = null;
        $service = new RailwayOperationsService(function (string $query, array $variables, string $token) use (&$seenToken): array {
            $seenToken = $token;

            if (str_contains($query, 'PlatformServiceStatus')) {
                return ['data' => [
                    'api' => [
                        'serviceName' => 'SmartSys API',
                        'region' => 'us-east',
                        'healthcheckPath' => '/check',
                        'latestDeployment' => ['status' => 'SUCCESS', 'createdAt' => '2026-11-28T10:00:00Z'],
                    ],
                    'database' => [
                        'serviceName' => 'MySQL',
                        'region' => 'us-east',
                        'latestDeployment' => ['status' => 'SUCCESS', 'createdAt' => '2026-11-28T10:01:00Z'],
                    ],
                ]];
            }

            return ['data' => [
                'volumeInstanceBackupList' => [[
                    'id' => 'backup-1',
                    'name' => 'daily',
                    'createdAt' => '2026-11-28T09:00:00Z',
                    'expiresAt' => '2026-12-04T09:00:00Z',
                    'usedMB' => 20,
                    'referencedMB' => 30,
                ]],
                'volumeInstanceBackupScheduleList' => [[
                    'id' => 'schedule-1',
                    'name' => 'Daily',
                    'cron' => '0 0 * * *',
                    'kind' => 'DAILY',
                    'retentionSeconds' => 518400,
                    'createdAt' => '2026-11-01T00:00:00Z',
                ]],
            ]];
        });

        $overview = $service->getOverview();

        self::assertSame('available', $overview['status']);
        self::assertSame('SUCCESS', $overview['services']['api']['status']);
        self::assertSame('SUCCESS', $overview['services']['database']['status']);
        self::assertSame('available', $overview['volumes'][0]['status']);
        self::assertCount(1, $overview['volumes'][0]['backups']);
        self::assertCount(1, $overview['volumes'][0]['schedules']);
        self::assertSame('project-token-test-only', $seenToken);
        self::assertArrayNotHasKey('token', $overview);
        self::assertStringNotContainsString('project-token-test-only', json_encode($overview));
    }

    public function testGraphqlErrorsAreNotTreatedAsSuccessfulStatus(): void
    {
        $this->setRailwayEnvironment([
            'RAILWAY_PROJECT_ACCESS_TOKEN' => 'project-token-test-only',
            'RAILWAY_ENVIRONMENT_ID' => 'environment-1',
            'RAILWAY_API_SERVICE_ID' => 'api-service-1',
            'RAILWAY_DATABASE_SERVICE_ID' => 'database-service-1',
        ]);
        $service = new RailwayOperationsService(static fn (): array => [
            'errors' => [[
                'message' => 'Not Authorized',
                'extensions' => ['code' => 'INTERNAL_SERVER_ERROR', 'traceId' => 'trace-123'],
            ]],
        ]);

        try {
            $service->getOverview();
            self::fail('GraphQL errors must fail the operations query.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('INTERNAL_SERVER_ERROR', $error->getMessage());
            self::assertStringContainsString('trace-123', $error->getMessage());
            self::assertStringNotContainsString('project-token-test-only', $error->getMessage());
        }
    }

    private function setRailwayEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->originalEnvironment)) {
                $this->originalEnvironment[$key] = $_ENV[$key] ?? null;
                if (!array_key_exists($key, $_ENV)) {
                    unset($this->originalEnvironment[$key]);
                }
            }
            $_ENV[$key] = $value;
        }
    }
}
