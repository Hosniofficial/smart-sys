<?php

declare(strict_types=1);

namespace App\Services;

/** Creates a stable hash for comparing retries that reuse a purchase request key. */
final class PurchaseRequestFingerprint
{
    public static function fromPayload(array $payload): string
    {
        $normalized = self::normalize($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \InvalidArgumentException('تعذر التحقق من بيانات طلب الشراء.');
        }

        return hash('sha256', $json);
    }

    private static function normalize(array $value): array
    {
        if (self::isList($value)) {
            return array_map(
                static fn (mixed $item): mixed => is_array($item) ? self::normalize($item) : $item,
                $value
            );
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
