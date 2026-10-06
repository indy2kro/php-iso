<?php

declare(strict_types=1);

namespace PhpIso\Util;

use Carbon\Carbon;
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
    public static function init7(array &$buffer, int &$offset): ?Carbon
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
    public static function init17(array &$buffer, int &$offset): ?Carbon
    {
        $date = Buffer::getString($buffer, 16, $offset);

        // the last byte is the signed GMT offset (tolerate a truncated buffer)
        $utcOffset = $buffer[$offset] ?? 0;

        $offset += 1;

        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 4, 2);
        $day = (int) substr($date, 6, 2);

        if ($year === 0 || $month === 0 || $day === 0) {
            return null;
        }

        $hour = (int) substr($date, 8, 2);
        $min = (int) substr($date, 10, 2);
        $sec = (int) substr($date, 12, 2);
        $hundredths = (int) substr($date, 14, 2);

        return self::create($year, $month, $day, $hour, $min, $sec, $utcOffset)?->addMilliseconds($hundredths * 10);
    }

    /**
     * Build the date, converting the signed 15 minutes interval byte to a timezone.
     * Invalid components (month 13, day 40...) return null instead of throwing.
     */
    protected static function create(int $year, int $month, int $day, int $hour, int $min, int $sec, int $offsetByte): ?Carbon
    {
        return self::createWithOffsetMinutes($year, $month, $day, $hour, $min, $sec, ($offsetByte > 127 ? $offsetByte - 256 : $offsetByte) * 15);
    }

    /**
     * Build a date from its components and an offset from UTC in minutes, null when a component is invalid
     */
    public static function createWithOffsetMinutes(int $year, int $month, int $day, int $hour, int $min, int $sec, int $minutes): ?Carbon
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $min > 59 || $sec > 59) {
            return null;
        }

        $sign = $minutes < 0 ? '-' : '+';
        $timezone = sprintf('%s%02d:%02d', $sign, intdiv(abs($minutes), 60), abs($minutes) % 60);

        try {
            return Carbon::create($year, $month, $day, $hour, $min, $sec, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
