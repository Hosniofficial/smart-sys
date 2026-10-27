<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utils\ApiResponseEnvelope;
use PHPUnit\Framework\TestCase;

final class ApiResponseEnvelopeTest extends TestCase
{
    public function testSuccessEnvelopeGetsMessageWithoutChangingExistingData(): void
    {
        $payload = ['status' => 'success', 'data' => ['id' => 17]];

        self::assertSame(
            ['status' => 'success', 'data' => ['id' => 17], 'message' => ''],
            ApiResponseEnvelope::normalize($payload, 200)
        );
    }

    public function testLegacySuccessObjectKeepsTopLevelFieldsAndAddsCanonicalData(): void
    {
        $payload = ['status' => 'success', 'result' => ['id' => 17]];

        self::assertSame(
            [
                'status' => 'success',
                'result' => ['id' => 17],
                'message' => '',
                'data' => ['result' => ['id' => 17]],
            ],
            ApiResponseEnvelope::normalize($payload, 200)
        );
    }

    public function testErrorEnvelopeKeepsCustomFieldsAndAddsNullData(): void
    {
        $payload = ['status' => 'error', 'message' => 'Forbidden', 'required' => ['reports.view']];

        self::assertSame(
            [
                'status' => 'error',
                'message' => 'Forbidden',
                'required' => ['reports.view'],
                'data' => null,
            ],
            ApiResponseEnvelope::normalize($payload, 403)
        );
    }

    public function testMissingStatusUsesHttpStatusAndKeepsLegacyFields(): void
    {
        $payload = ['error_code' => 'TOKEN_EXPIRED', 'message' => 'Please sign in again'];

        self::assertSame(
            [
                'error_code' => 'TOKEN_EXPIRED',
                'message' => 'Please sign in again',
                'status' => 'error',
                'data' => null,
            ],
            ApiResponseEnvelope::normalize($payload, 401)
        );
    }

    public function testListPayloadGetsStandardEnvelopeAndCustomStatusGetsData(): void
    {
        $list = [['id' => 1], ['id' => 2]];
        $customStatus = ['status' => 'subscription_required', 'code' => 'SUBSCRIPTION_REQUIRED'];
        $healthStatus = ['status' => 'ok', 'timestamp' => '2026-10-27T00:00:00+00:00'];

        self::assertSame(
            ['status' => 'success', 'message' => '', 'data' => $list],
            ApiResponseEnvelope::normalize($list, 200)
        );
        self::assertSame(
            ['status' => 'success', 'message' => '', 'data' => []],
            ApiResponseEnvelope::normalize([], 200)
        );
        self::assertSame(
            [
                'status' => 'subscription_required',
                'code' => 'SUBSCRIPTION_REQUIRED',
                'message' => '',
                'data' => ['code' => 'SUBSCRIPTION_REQUIRED'],
            ],
            ApiResponseEnvelope::normalize($customStatus, 402)
        );
        self::assertSame(
            [
                'status' => 'ok',
                'timestamp' => '2026-10-27T00:00:00+00:00',
                'message' => '',
                'data' => ['timestamp' => '2026-10-27T00:00:00+00:00'],
            ],
            ApiResponseEnvelope::normalize($healthStatus, 200)
        );
    }
}
