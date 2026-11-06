<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utils\ApiResponseEnvelope;
use PHPUnit\Framework\TestCase;

final class ApiResponseEnvelopeTest extends TestCase
{
    public function testSuccessEnvelopeHasOneStableShape(): void
    {
        $payload = ['status' => 'success', 'data' => ['id' => 17]];

        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => ['id' => 17],
                'errors' => null,
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize($payload, 200)
        );
    }

    public function testSuccessPayloadWithoutDataMovesFieldsIntoData(): void
    {
        $payload = ['status' => 'success', 'result' => ['id' => 17]];

        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => ['result' => ['id' => 17]],
                'errors' => null,
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize($payload, 200)
        );
    }

    public function testErrorEnvelopeMovesErrorDetailsIntoErrors(): void
    {
        $payload = ['status' => 'error', 'message' => 'Forbidden', 'required' => ['reports.view']];

        self::assertEquals(
            [
                'status' => 'error',
                'message' => 'Forbidden',
                'data' => null,
                'errors' => ['required' => ['reports.view']],
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize($payload, 403)
        );
    }

    public function testMissingStatusUsesHttpStatusAndMovesErrorDetailsIntoErrors(): void
    {
        $payload = ['error_code' => 'TOKEN_EXPIRED', 'message' => 'Please sign in again'];

        self::assertEquals(
            [
                'status' => 'error',
                'message' => 'Please sign in again',
                'data' => null,
                'errors' => ['errorCode' => 'TOKEN_EXPIRED'],
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize($payload, 401)
        );
    }

    public function testListAndCustomStatusesUseSuccessOrErrorBasedOnHttpStatus(): void
    {
        $list = [['id' => 1], ['id' => 2]];
        $customStatus = ['status' => 'subscription_required', 'code' => 'SUBSCRIPTION_REQUIRED'];
        $healthStatus = ['status' => 'ok', 'timestamp' => '2026-10-27T00:00:00+00:00'];

        self::assertEquals(
            ['status' => 'success', 'message' => '', 'data' => $list, 'errors' => null, 'meta' => (object) []],
            ApiResponseEnvelope::normalize($list, 200)
        );
        self::assertEquals(
            ['status' => 'success', 'message' => '', 'data' => [], 'errors' => null, 'meta' => (object) []],
            ApiResponseEnvelope::normalize([], 200)
        );
        self::assertEquals(
            [
                'status' => 'error',
                'message' => 'Request failed',
                'data' => null,
                'errors' => ['code' => 'SUBSCRIPTION_REQUIRED'],
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize($customStatus, 402)
        );
        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => ['timestamp' => '2026-10-27T00:00:00+00:00', 'status' => 'ok'],
                'errors' => null,
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize($healthStatus, 200)
        );
    }

    public function testPaginationFieldsMoveToMeta(): void
    {
        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => ['items' => [['id' => 1]]],
                'errors' => null,
                'meta' => (object) [
                    'page' => 2,
                    'perPage' => 25,
                    'total' => 60,
                    'currentPage' => 2,
                    'lastPage' => 3,
                ],
            ],
            ApiResponseEnvelope::normalize([
                'status' => 'success',
                'items' => [['id' => 1]],
                'page' => 2,
                'per_page' => 25,
                'total' => 60,
                'pagination' => [
                    'total' => 60,
                    'per_page' => 25,
                    'current_page' => 2,
                    'last_page' => 3,
                ],
            ], 200)
        );
    }

    public function testBusinessStatusIsPreservedInsideData(): void
    {
        self::assertEquals(
            [
                'status' => 'success',
                'message' => 'Two-factor code required',
                'data' => ['status' => '2fa_required'],
                'errors' => null,
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize([
                'status' => '2fa_required',
                'message' => 'Two-factor code required',
            ], 200)
        );
    }

    public function testStandardizedErrorWithNullErrorsDoesNotNestAnEmptyErrorsObject(): void
    {
        self::assertEquals(
            [
                'status' => 'error',
                'message' => 'Method not allowed',
                'data' => null,
                'errors' => null,
                'meta' => (object) [],
            ],
            ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Method not allowed',
                'data' => null,
                'errors' => null,
                'meta' => (object) [],
            ], 405)
        );
    }

    public function testSuccessfulExtraFieldsRemainInDataAndPaginationUsesMeta(): void
    {
        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => [
                    'id' => 17,
                    'warnings' => ['journalEntrySkipped' => true],
                ],
                'errors' => null,
                'meta' => (object) ['total' => 12],
            ],
            ApiResponseEnvelope::normalize([
                'status' => 'success',
                'data' => ['id' => 17],
                'warnings' => ['journal_entry_skipped' => true],
                'total' => 12,
            ], 200)
        );
    }

    public function testNestedPaginationIsLiftedIntoMeta(): void
    {
        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => ['items' => [['id' => 1]]],
                'errors' => null,
                'meta' => (object) [
                    'total' => 8,
                    'perPage' => 4,
                    'currentPage' => 2,
                    'lastPage' => 2,
                ],
            ],
            ApiResponseEnvelope::normalize([
                'status' => 'success',
                'data' => [
                    'items' => [['id' => 1]],
                    'pagination' => [
                        'total' => 8,
                        'per_page' => 4,
                        'current_page' => 2,
                        'last_page' => 2,
                    ],
                ],
            ], 200)
        );
    }

    public function testPaginationFieldsBesideItemsAreLiftedIntoMeta(): void
    {
        self::assertEquals(
            [
                'status' => 'success',
                'message' => '',
                'data' => ['items' => [['id' => 1]]],
                'errors' => null,
                'meta' => (object) [
                    'page' => 2,
                    'perPage' => 10,
                    'total' => 17,
                ],
            ],
            ApiResponseEnvelope::normalize([
                'status' => 'success',
                'data' => [
                    'items' => [['id' => 1]],
                    'page' => 2,
                    'per_page' => 10,
                    'total' => 17,
                ],
            ], 200)
        );
    }
}
