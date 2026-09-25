<?php

declare(strict_types=1);


/** Builds the application's PDO connection using canonical DB_* names with legacy aliases. */
final class MigrationDatabase
{
    /**
     * Resolve configuration without connecting; canonical application names win over legacy aliases.
     *
     * @return array{host:string,port:string,database:string,username:string,password:string}
     * @throws RuntimeException when no database name is explicitly configured.
     */
    public static function resolveConfig(array $environment): array
    {
        $database = self::firstConfigured($environment, 'DB_DATABASE', 'DB_NAME');
        if ($database === null) {
            throw new RuntimeException('DB_DATABASE (or legacy DB_NAME) must be configured before running migrations.');
        }

        return [
            'host' => self::firstConfigured($environment, 'DB_HOST') ?? 'localhost',
            'port' => self::firstConfigured($environment, 'DB_PORT') ?? '3306',
            'database' => $database,
            'username' => self::firstConfigured($environment, 'DB_USERNAME', 'DB_USER') ?? 'root',
            'password' => self::firstConfigured($environment, 'DB_PASSWORD', 'DB_PASS') ?? '',
        ];
    }

    /** Create an exception-throwing PDO connection for migration and schema tooling. */
    public static function connect(array $environment): PDO
    {
        $config = self::resolveConfig($environment);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** Return the first non-empty configured value, preferring the canonical key. */
    private static function firstConfigured(array $environment, string $key, ?string $legacyKey = null): ?string
    {
        foreach (array_filter([$key, $legacyKey]) as $candidate) {
            $value = isset($environment[$candidate]) ? trim((string) $environment[$candidate]) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
