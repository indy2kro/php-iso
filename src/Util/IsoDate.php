<?php

declare(strict_types=1);

namespace PhpIso\Util;

use Carbon\CarbonImmutable;
use Throwable;

class IsoDate
{
    /*
     * UTC offset = Offset from Greenwich Mean Time in number of 15 min intervals from -48 (West) to +52 (East) recorded according to 7.1.2
     */

    /**
     * Create from a "7 bytes" date
     *
     * @param array<int, int> $buffer
     */
    public static function init7(array &$buffer, int &$offset): ?CarbonImmutable
    {
        $bytes = Buffer::getRawBytes($buffer, 7, $offset);

        $year = 1900 + $bytes[0];
        $month = $bytes[1];
        $day = $bytes[2];

        if ($year === 1900 || $month === 0 || $day === 0) {
            return null;
        }

        return self::create($year, $month, $day, $bytes[3], $bytes[4], $bytes[5], $bytes[6]);
    }

    /**
     * Create from a "17 bytes" date
     *
     * @param array<int, int> $buffer
     */
    public static function init17(array &$buffer, int &$offset): ?CarbonImmutable
    {
        $date = Buffer::getString($buffer, 16, $offset);

        // the last byte is the signed GMT offset (tolerate a truncated buffer)
        $utcOffset = $buffer[$offset] ?? 0;

        $offset += 1;

        $year = self::digits($date, 0, 4);
        $month = self::digits($date, 4, 2);
        $day = self::digits($date, 6, 2);

        if ($year === 0 || $month === 0 || $day === 0) {
            return null;
        }

        $hour = self::digits($date, 8, 2);
        $min = self::digits($date, 10, 2);
        $sec = self::digits($date, 12, 2);
        $hundredths = self::digits($date, 14, 2);

        return self::create($year, $month, $day, $hour, $min, $sec, $utcOffset)?->addMilliseconds($hundredths * 10);
    }

    /**
     * The leading decimal digits of a field of a "17 bytes" date, 0 when there are none ("2e4" is not a number here)
     */
    private static function digits(string $date, int $start, int $length): int
    {
        return preg_match('/^\s*(\d+)/', substr($date, $start, $length), $matches) === 1 ? (int) $matches[1] : 0;
    }

    /**
     * Build the date, converting the signed 15 minutes interval byte to a timezone.
     * Invalid components (month 13, day 40...) return null instead of throwing.
     */
    protected static function create(int $year, int $month, int $day, int $hour, int $min, int $sec, int $offsetByte): ?CarbonImmutable
    {
        return self::createWithOffsetMinutes($year, $month, $day, $hour, $min, $sec, self::offsetUnitsToMinutes($offsetByte));
    }

    /**
     * Convert the signed byte of 15 minutes intervals to minutes, an offset outside -48..+52 is ignored (UTC)
     */
    private static function offsetUnitsToMinutes(int $offsetByte): int
    {
        $units = $offsetByte > 127 ? $offsetByte - 256 : $offsetByte;

        return $units < -48 || $units > 52 ? 0 : $units * 15;
    }

    /**
     * Build a date from its components and an offset from UTC in minutes, null when a component is invalid
     */
    public static function createWithOffsetMinutes(int $year, int $month, int $day, int $hour, int $min, int $sec, int $minutes): ?CarbonImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1 || $hour < 0 || $min < 0 || $sec < 0 || $hour > 23 || $min > 59 || $sec > 59 || !checkdate($month, $day, $year)) {
            return null;
        }

        // an offset outside -12:00..+13:00 is invalid, treat the date as UTC
        if ($minutes < -720 || $minutes > 780) {
            $minutes = 0;
        }

        $sign = $minutes < 0 ? '-' : '+';
        $timezone = sprintf('%s%02d:%02d', $sign, intdiv(abs($minutes), 60), abs($minutes) % 60);

        try {
            return CarbonImmutable::create($year, $month, $day, $hour, $min, $sec, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
