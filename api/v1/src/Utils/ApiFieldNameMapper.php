<?php

declare(strict_types=1);

namespace App\Utils;

final class ApiFieldNameMapper
{
    public static function toCamelCase(mixed $value): mixed
    {
        return self::map($value, self::snakeToCamel(...));
    }

    public static function toSnakeCase(mixed $value): mixed
    {
        return self::map($value, self::camelToSnake(...));
    }

    private static function map(mixed $value, callable $mapKey): mixed
    {
        if (is_object($value)) {
            $mapped = [];
            foreach (get_object_vars($value) as $key => $item) {
                $mappedKey = is_string($key) ? $mapKey($key) : $key;
                self::assertAvailableKey($mapped, $mappedKey);
                $mapped[$mappedKey] = self::map($item, $mapKey);
            }

            return (object) $mapped;
        }

        if (!is_array($value)) {
            return $value;
        }

        $mapped = [];
        foreach ($value as $key => $item) {
            $mappedKey = is_string($key) ? $mapKey($key) : $key;
            self::assertAvailableKey($mapped, $mappedKey);
            $mapped[$mappedKey] = self::map($item, $mapKey);
        }

        return $mapped;
    }

    private static function assertAvailableKey(array $mapped, int|string $key): void
    {
        if (array_key_exists($key, $mapped)) {
            throw new \InvalidArgumentException(
                sprintf('Multiple fields map to the same API field "%s".', (string) $key),
                400
            );
        }
    }

    private static function snakeToCamel(string $key): string
    {
        if (str_contains($key, '.')) {
            return $key;
        }

        return preg_replace_callback(
            '/_+([a-zA-Z0-9])/',
            static fn(array $match): string => strtoupper($match[1]),
            $key
        ) ?? $key;
    }

    private static function camelToSnake(string $key): string
    {
        if (str_contains($key, '.')) {
            return $key;
        }

        $key = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key) ?? $key;
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;

        return strtolower($key);
    }
}
