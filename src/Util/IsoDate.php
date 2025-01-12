<?php

declare(strict_types=1);

namespace PhpIso\Util;

use Carbon\Carbon;

class IsoDate
{
    /**
     * Create from a "7 bytes" date
     *
     * @param array<int, mixed> $buffer
     */
    public static function init7(array &$buffer, int &$offset): ?Carbon
    {
        $year = 1900 + $buffer[$offset + 0];
        $month = $buffer[$offset + 1];
        $day = $buffer[$offset + 2];
        $hour = $buffer[$offset + 3];
        $min = $buffer[$offset + 4];
        $sec = $buffer[$offset + 5];
        // $tz = $buffer[$offset + 6];

        $offset += 7;

        return Carbon::create($year, $month, $day, $hour, $min, $sec);
    }
    /**
     * Create from a "17 bytes" date
     *
     * @param array<int, mixed> $buffer
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
        // $ms = (int) substr($date, 14, 2);
        // $tz = $buffer[16];

        $offset += 1;

        return Carbon::create($year, $month, $day, $hour, $min, $sec);
    }
}
