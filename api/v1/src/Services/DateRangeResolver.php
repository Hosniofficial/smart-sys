<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resolves optional date filters into a half-open range in the requested timezone.
 */
class DateRangeResolver
{
    /**
     * Resolve date inputs to inclusive start and exclusive end timestamps.
     *
     * @return array{0: string, 1: string} Inclusive start and exclusive end.
     */
    public static function resolve(
        ?string $start,
        ?string $end,
        int $defaultDaysBack = 30,
        ?string $timezone = null
    ): array
    {
        $targetTimezone = new \DateTimeZone($timezone ?: date_default_timezone_get());
        $today = new \DateTimeImmutable('now', $targetTimezone);
        $start = ($start !== null && $start !== '')
            ? $start
            : $today->modify("-{$defaultDaysBack} days")->format('Y-m-d');
        $end = ($end !== null && $end !== '') ? $end : $today->format('Y-m-d');

        $startDateOnly = self::toCalendarDate($start, $targetTimezone);
        $endDateOnly = self::toCalendarDate($end, $targetTimezone);
        if ($startDateOnly > $endDateOnly) {
            throw new \InvalidArgumentException('Start date must not be later than end date.');
        }

        $fromInclusive = $startDateOnly . ' 00:00:00';
        // The half-open boundary includes the full end date without wrapping the indexed column.
        $toExclusive = (new \DateTimeImmutable($endDateOnly, $targetTimezone))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00');

        return [$fromInclusive, $toExclusive];
    }

    /** Convert zoned ISO timestamps to the target calendar date; retain the date prefix otherwise. */
    private static function toCalendarDate(string $value, \DateTimeZone $targetTimezone): string
    {
        if (preg_match('/T.*(?:Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            $timestamp = new \DateTimeImmutable($value);
            $parseErrors = \DateTimeImmutable::getLastErrors();
            if ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)) {
                throw new \InvalidArgumentException('Date-time value is invalid.');
            }

            return $timestamp->setTimezone($targetTimezone)->format('Y-m-d');
        }

        $date = substr($value, 0, 10);
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $parseErrors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsedDate
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
            || $parsedDate->format('Y-m-d') !== $date
        ) {
            throw new \InvalidArgumentException('Date must contain a valid YYYY-MM-DD calendar date.');
        }

        return $date;
    }

    /**
     * Build a parameterized half-open SQL predicate for a trusted column expression.
     *
     * @return array{0: string, 1: array{0: string, 1: string}} SQL and bound values.
     */
    public static function buildCondition(
        string $column,
        ?string $start,
        ?string $end,
        int $defaultDaysBack = 30,
        ?string $timezone = null
    ): array
    {
        [$from, $toExclusive] = self::resolve($start, $end, $defaultDaysBack, $timezone);
        return ["{$column} >= ? AND {$column} < ?", [$from, $toExclusive]];
    }
}
