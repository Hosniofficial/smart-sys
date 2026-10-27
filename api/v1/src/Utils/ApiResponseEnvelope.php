<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Adds the common response fields without removing legacy top-level fields.
 *
 * Legacy fields remain at the top level so existing clients can continue to
 * consume them. Custom status values retain their status while gaining data.
 */
final class ApiResponseEnvelope
{
    public static function normalize(mixed $payload, int $httpStatus): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }

        if (self::isList($payload)) {
            $status = $httpStatus >= 400 ? 'error' : 'success';

            return [
                'status' => $status,
                'message' => $status === 'error' ? 'Request failed' : '',
                'data' => $payload,
            ];
        }

        $status = $payload['status'] ?? ($httpStatus >= 400 ? 'error' : 'success');
        if (!isset($payload['status'])) {
            $payload['status'] = $status;
        }

        if (!array_key_exists('message', $payload)) {
            $payload['message'] = $status === 'error' ? 'Request failed' : '';
        }

        if (!array_key_exists('data', $payload)) {
            if ($status === 'success') {
                $legacyData = $payload;
                unset($legacyData['status'], $legacyData['message']);
                $payload['data'] = $legacyData;
            } elseif ($status !== 'error') {
                $legacyData = $payload;
                unset($legacyData['status'], $legacyData['message']);
                $payload['data'] = $legacyData;
            } else {
                $payload['data'] = null;
            }
        }

        return $payload;
    }

    private static function isList(array $payload): bool
    {
        return $payload === [] || array_keys($payload) === range(0, count($payload) - 1);
    }
}
