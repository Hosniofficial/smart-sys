<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class DatabaseSslOptions
{
    public static function mergeInto(array $options, string $mode, string $caPath = ''): array
    {
        foreach (self::forMode($mode, $caPath) as $attribute => $value) {
            $options[$attribute] = $value;
        }

        return $options;
    }

    public static function forMode(string $mode, string $caPath = ''): array
    {
        $mode = strtoupper(trim($mode));
        if ($mode === '') {
            return [];
        }

        if ($mode === 'REQUIRED') {
            return [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false];
        }

        if (!in_array($mode, ['VERIFY_CA', 'VERIFY_IDENTITY'], true)) {
            throw new RuntimeException('DB_SSL_MODE must be REQUIRED, VERIFY_CA, or VERIFY_IDENTITY.');
        }

        if ($caPath === '' || !is_file($caPath) || !is_readable($caPath)) {
            throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate for verified TLS modes.');
        }

        return [
            PDO::MYSQL_ATTR_SSL_CA => $caPath,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ];
    }
}
