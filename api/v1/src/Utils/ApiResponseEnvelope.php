<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Normalizes handler payloads to the public API response contract.
 */
final class ApiResponseEnvelope
{
    public static function normalize(mixed $payload, int $httpStatus): array
    {
        if (!is_array($payload)) {
            return ApiFieldNameMapper::toCamelCase(self::envelope(
                $httpStatus >= 400 ? 'error' : 'success',
                '',
                $httpStatus >= 400 ? null : $payload,
                null,
                []
            ));
        }

        if (self::isList($payload)) {
            $status = $httpStatus >= 400 ? 'error' : 'success';

            return ApiFieldNameMapper::toCamelCase(self::envelope(
                $status,
                $status === 'error' ? 'Request failed' : '',
                $status === 'error' ? null : $payload,
                null,
                []
            ));
        }

        $payloadStatus = $payload['status'] ?? null;
        $isError = $httpStatus >= 400 || $payloadStatus === 'error';
        $status = $isError ? 'error' : 'success';
        $message = $payload['message'] ?? ($isError ? 'Request failed' : '');
        $data = $payload['data'] ?? null;
        $extraData = $payload;
        unset(
            $extraData['status'],
            $extraData['message'],
            $extraData['data'],
            $extraData['errors'],
            $extraData['meta'],
            $extraData['pagination']
        );
        foreach (['page', 'per_page', 'total', 'total_pages', 'limit', 'offset'] as $key) {
            unset($extraData[$key]);
        }

        if (!array_key_exists('data', $payload) && !$isError) {
            $data = $extraData;
        } elseif (!$isError && $extraData !== []) {
            if (!is_array($data)) {
                $data = ['value' => $data];
            } elseif (self::isList($data)) {
                $data = ['items' => $data];
            }
            $data = array_merge($data, $extraData);
        }
        if (!$isError && is_string($payloadStatus) && !in_array($payloadStatus, ['success', 'error'], true)) {
            if (!is_array($data)) {
                $data = ['value' => $data];
            }
            $data['status'] = $payloadStatus;
        }

        $errors = $payload['errors'] ?? null;
        if ($isError && $errors === null) {
            $errors = $payload;
            unset($errors['status'], $errors['message'], $errors['data'], $errors['errors'], $errors['meta']);
            if (
                $payloadStatus !== null
                && !in_array($payloadStatus, ['success', 'error'], true)
                && !array_key_exists('code', $errors)
            ) {
                $errors['code'] = $payloadStatus;
            }
            if ($errors === []) {
                $errors = null;
            }
        }

        $rawMeta = $payload['meta'] ?? null;
        $meta = is_array($rawMeta)
            ? $rawMeta
            : (is_object($rawMeta) ? get_object_vars($rawMeta) : []);
        $pagination = $payload['pagination'] ?? null;
        if (is_object($pagination)) {
            $pagination = get_object_vars($pagination);
        }
        if (is_array($pagination)) {
            $meta = array_merge($meta, $pagination);
        }
        if (!$isError && is_array($data) && isset($data['pagination'])) {
            $nestedPagination = $data['pagination'];
            if (is_object($nestedPagination)) {
                $nestedPagination = get_object_vars($nestedPagination);
            }
            if (is_array($nestedPagination)) {
                $meta = array_merge($meta, $nestedPagination);
                unset($data['pagination']);
            }
        }
        if (!$isError && is_array($data) && isset($data['items'])) {
            $paginationKeys = [
                'page',
                'per_page',
                'perPage',
                'current_page',
                'currentPage',
                'last_page',
                'lastPage',
                'total_pages',
                'totalPages',
                'limit',
                'offset',
                'total',
            ];
            foreach ($paginationKeys as $key) {
                if (array_key_exists($key, $data)) {
                    $meta[$key] = $data[$key];
                    unset($data[$key]);
                }
            }
        }
        foreach (['page', 'per_page', 'total', 'total_pages', 'limit', 'offset'] as $key) {
            if (array_key_exists($key, $payload)) {
                $meta[$key] = $payload[$key];
            }
        }

        return ApiFieldNameMapper::toCamelCase(self::envelope(
            $status,
            is_scalar($message) ? (string) $message : '',
            $isError ? null : $data,
            $errors,
            $meta
        ));
    }

    private static function envelope(
        string $status,
        string $message,
        mixed $data,
        mixed $errors,
        array $meta
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => (object) $meta,
        ];
    }

    private static function isList(array $payload): bool
    {
        return $payload === [] || array_keys($payload) === range(0, count($payload) - 1);
    }
}
