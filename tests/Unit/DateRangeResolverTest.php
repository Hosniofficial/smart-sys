<?php

declare(strict_types=1);

use App\Services\DateRangeResolver;
use PHPUnit\Framework\TestCase;

final class DateRangeResolverTest extends TestCase
{
    public function testIsoUtcBoundariesAreConvertedToTenantCalendarDate(): void
    {
        self::assertSame(
            ['2026-10-01 00:00:00', '2026-10-02 00:00:00'],
            DateRangeResolver::resolve(
                '2026-09-30T21:00:00.000Z',
                '2026-10-01T20:59:59.999Z',
                30,
                'Africa/Cairo'
            )
        );
    }

    public function testDateOnlyBoundariesRemainCalendarDates(): void
    {
        self::assertSame(
            ['2026-10-01 00:00:00', '2026-10-02 00:00:00'],
            DateRangeResolver::resolve('2026-10-01', '2026-10-01', 30, 'Africa/Cairo')
        );
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateRangeResolver::resolve('2026-02-30', '2026-03-01');
    }

    public function testInvalidZonedTimestampIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateRangeResolver::resolve('2026-02-30T10:00:00Z', '2026-03-01T10:00:00Z');
    }

    public function testReversedDateRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateRangeResolver::resolve('2026-10-02', '2026-10-01');
    }
}
