<?php

declare(strict_types=1);

namespace PhpIso\Util;

use Carbon\Carbon;

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
        $year = 1900 + (int) $buffer[$offset + 0];
        $month = (int) $buffer[$offset + 1];
        $day = (int) $buffer[$offset + 2];
        $hour = (int) $buffer[$offset + 3];
        $min = (int) $buffer[$offset + 4];
        $sec = (int) $buffer[$offset + 5];
        $utcOffset = $buffer[$offset + 6];
        $utcOffsetHours = (int) round($utcOffset / 4);

        $offset += 7;

        if ($year === 1900 || $month === 0 || $day === 0) {
            return null;
        }

        return Carbon::create($year, $month, $day, $hour, $min, $sec, $utcOffsetHours);
    }
    /**
     * Create from a "17 bytes" date
     *
     * @param array<int, int> $buffer
     */
    public static function init17(array &$buffer, int &$offset): ?Carbon
    {
        $date = Buffer::getString($buffer, 16, $offset);

        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 4, 2);
        $day = (int) substr($date, 6, 2);
        $hour = (int) substr($date, 8, 2);
        $min = (int) substr($date, 10, 2);
        $sec = (int) substr($date, 12, 2);
        $ms = (int) substr($date, 14, 2);
        $utcOffset = (int) substr($date, 16, 2);
        $utcOffsetHours = (int) round($utcOffset / 4);

        $offset += 1;

        if ($year === 0 || $month === 0 || $day === 0) {
            return null;
        }

        $date = Carbon::create($year, $month, $day, $hour, $min, $sec, $utcOffsetHours);

        if ($date !== null) {
            $date->addMilliseconds($ms);
        }

        return $date;
    }
}
