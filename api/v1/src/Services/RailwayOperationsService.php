<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

final class RailwayOperationsService
{
    private const API_URL = 'https://backboard.railway.com/graphql/v2';

    private Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null
            ? fn (string $query, array $variables, string $token): array => $this->sendRequest($query, $variables, $token)
            : Closure::fromCallable($transport);
    }

    public function getOverview(): array
    {
        $config = [
            'token' => $this->environmentValue('RAILWAY_PROJECT_ACCESS_TOKEN'),
            'environment_id' => $this->environmentValue('RAILWAY_ENVIRONMENT_ID'),
            'api_service_id' => $this->environmentValue('RAILWAY_API_SERVICE_ID'),
            'database_service_id' => $this->environmentValue('RAILWAY_DATABASE_SERVICE_ID'),
        ];
        $missing = [];

        foreach ($config as $key => $value) {
            if ($value === '') {
                $missing[] = match ($key) {
                    'token' => 'RAILWAY_PROJECT_ACCESS_TOKEN',
                    'environment_id' => 'RAILWAY_ENVIRONMENT_ID',
                    'api_service_id' => 'RAILWAY_API_SERVICE_ID',
                    'database_service_id' => 'RAILWAY_DATABASE_SERVICE_ID',
                };
            }
        }

        if ($missing !== []) {
            return [
                'status' => 'not_configured',
                'missing_configuration' => $missing,
                'environment_id' => null,
                'services' => [],
                'volumes' => [],
            ];
        }

        $serviceData = $this->query(
            <<<'GRAPHQL'
query PlatformServiceStatus(
  $environmentId: String!
  $apiServiceId: String!
  $databaseServiceId: String!
) {
  api: serviceInstance(environmentId: $environmentId, serviceId: $apiServiceId) {
    serviceName
    region
    healthcheckPath
    latestDeployment {
      id
      status
      createdAt
    }
  }
  database: serviceInstance(environmentId: $environmentId, serviceId: $databaseServiceId) {
    serviceName
    region
    latestDeployment {
      id
      status
      createdAt
    }
  }
}
GRAPHQL,
            [
                'environmentId' => $config['environment_id'],
                'apiServiceId' => $config['api_service_id'],
                'databaseServiceId' => $config['database_service_id'],
            ],
            $config['token']
        );

        $volumes = [];
        foreach ($this->volumeInstanceIds() as $volumeId) {
            try {
                $volumeData = $this->query(
                    <<<'GRAPHQL'
query VolumeBackupOverview($volumeInstanceId: String!) {
  volumeInstanceBackupList(volumeInstanceId: $volumeInstanceId) {
    id
    name
    createdAt
    expiresAt
    usedMB
    referencedMB
  }
  volumeInstanceBackupScheduleList(volumeInstanceId: $volumeInstanceId) {
    id
    name
    cron
    kind
    retentionSeconds
    createdAt
  }
}
GRAPHQL,
                    ['volumeInstanceId' => $volumeId],
                    $config['token']
                );

                $volumes[] = [
                    'id' => $volumeId,
                    'status' => 'available',
                    'backups' => $volumeData['volumeInstanceBackupList'] ?? [],
                    'schedules' => $volumeData['volumeInstanceBackupScheduleList'] ?? [],
                ];
            } catch (Throwable $error) {
                $volumes[] = [
                    'id' => $volumeId,
                    'status' => 'error',
                    'message' => 'تعذر تحميل بيانات النسخ الاحتياطي لهذا المجلد.',
                ];
            }
        }

        return [
            'status' => 'available',
            'environment_id' => $config['environment_id'],
            'services' => [
                'api' => $this->normalizeService($serviceData['api'] ?? null),
                'database' => $this->normalizeService($serviceData['database'] ?? null),
            ],
            'volumes' => $volumes,
            'volume_configuration_missing' => $volumes === [],
        ];
    }

    private function normalizeService(mixed $service): array
    {
        if (!is_array($service)) {
            return ['status' => 'not_found'];
        }

        $deployment = is_array($service['latestDeployment'] ?? null)
            ? $service['latestDeployment']
            : null;

        return [
            'name' => (string) ($service['serviceName'] ?? ''),
            'status' => (string) ($deployment['status'] ?? 'no_deployment'),
            'region' => $service['region'] ?? null,
            'healthcheck_path' => $service['healthcheckPath'] ?? null,
            'latest_deployment_at' => $deployment['createdAt'] ?? null,
        ];
    }

    private function query(string $query, array $variables, string $token): array
    {
        $payload = ($this->transport)($query, $variables, $token);
        if (!is_array($payload)) {
            throw new RuntimeException('Railway returned an invalid response.');
        }

        $errors = $payload['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $firstError = is_array($errors[0] ?? null) ? $errors[0] : [];
            $extensions = is_array($firstError['extensions'] ?? null) ? $firstError['extensions'] : [];
            $code = preg_replace('/[^A-Z0-9_]/i', '', (string) ($extensions['code'] ?? '')) ?: 'UNKNOWN';
            $traceId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($extensions['traceId'] ?? ''));
            throw new RuntimeException('Railway GraphQL error ' . $code . ($traceId !== '' ? ' (' . $traceId . ')' : ''));
        }

        if (!is_array($payload['data'] ?? null)) {
            throw new RuntimeException('Railway returned a response without data.');
        }

        return $payload['data'];
    }

    private function sendRequest(string $query, array $variables, string $token): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is unavailable.');
        }

        $body = json_encode([
            'query' => $query,
            'variables' => $variables,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = curl_init(self::API_URL);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize Railway API request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Project-Access-Token: ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $responseBody = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false || $curlError !== '') {
            throw new RuntimeException('Railway API request failed.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('Railway API returned HTTP ' . $httpStatus . '.');
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Railway returned invalid JSON.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Railway returned an invalid JSON payload.');
        }

        return $decoded;
    }

    private function environmentValue(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    private function volumeInstanceIds(): array
    {
        $value = $this->environmentValue('RAILWAY_VOLUME_INSTANCE_IDS');
        if ($value === '') {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $id): bool => $id !== '' && strlen($id) <= 255
        )));

        return array_slice($ids, 0, 10);
    }
}
