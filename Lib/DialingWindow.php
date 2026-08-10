<?php

namespace Modules\ModuleAutoDialer\Lib;

use InvalidArgumentException;

final class DialingWindow
{
    private const MIN_OFFSET_MINUTES = -720;
    private const MAX_OFFSET_MINUTES = 840;

    /**
     * Converts an offset in hours to minutes. Null means PBX-local time.
     *
     * @param mixed $value
     */
    public static function normalizeOffset($value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('TimeOffset must be numeric or empty');
        }

        $minutes = (float)$value * 60;
        $roundedMinutes = round($minutes);
        if (
            abs($minutes - $roundedMinutes) > 0.000001
            || $roundedMinutes < self::MIN_OFFSET_MINUTES
            || $roundedMinutes > self::MAX_OFFSET_MINUTES
        ) {
            throw new InvalidArgumentException(
                'TimeOffset must be between -12 and 14 hours in whole minutes'
            );
        }

        return (int)$roundedMinutes;
    }

    public static function minuteOfDay(int $timestamp, ?int $offsetMinutes): int
    {
        if ($offsetMinutes === null || $offsetMinutes === 0) {
            return (int)date('G', $timestamp) * 60 + (int)date('i', $timestamp);
        }

        $minute = (int)floor($timestamp / 60) + $offsetMinutes;
        return (($minute % 1440) + 1440) % 1440;
    }

    public static function isMinuteAllowed(int $minute, int $timeStart, int $timeEnd): bool
    {
        if ($timeStart === 0 && $timeEnd === 1440) {
            return true;
        }
        if ($timeStart <= $timeEnd) {
            return $minute >= $timeStart && $minute <= $timeEnd;
        }
        return $minute >= $timeStart || $minute <= $timeEnd;
    }

    public static function isAllowed(
        int $timestamp,
        ?int $offsetMinutes,
        int $timeStart,
        int $timeEnd
    ): bool {
        return self::isMinuteAllowed(
            self::minuteOfDay($timestamp, $offsetMinutes),
            $timeStart,
            $timeEnd
        );
    }
}
