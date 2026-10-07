<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use Carbon\CarbonImmutable;
use PhpIso\Exception;
use PhpIso\Util\IsoDate;
use PhpIso\IsoFile;
use PhpIso\RockRidgeInfo;

/**
 * Parser of the Rock Ridge / SUSP entries stored in the system use area of a directory record
 */
final class RockRidge
{
    /**
     * Maximum number of continuation areas ("CE") followed for one record
     */
    private const int MAX_CONTINUATIONS = 8;

    /**
     * @return RockRidgeInfo|null null when the system use area holds no Rock Ridge data
     */
    public static function parse(string $systemUse, ?IsoFile $isoFile = null, int $blockSize = 2048, int $skip = 0): ?RockRidgeInfo
    {
        if (strlen($systemUse) < 4) {
            return null;
        }

        $name = null;
        $symlink = null;
        $mode = $links = $uid = $gid = $childLocation = null;
        $deviceHigh = $deviceLow = null;
        $times = [];
        $relocated = false;
        $found = false;
        $nameContinues = false;
        $linkContinues = false;
        $continuations = 0;
        /** @var array{int, int, int}|null $pendingContinuation block, start, size of the continuation area to read once the current area is done */
        $pendingContinuation = null;

        $data = $systemUse;
        $offset = max(0, $skip);

        while (true) {
            $signature = '';
            $length = 0;
            $valid = $offset + 4 <= strlen($data);
            if ($valid) {
                $signature = substr($data, $offset, 2);
                $length = ord($data[$offset + 2]);

                // an entry must be at least its header and stay inside the area, padding bytes end the list
                $valid = $length >= 4 && $offset + $length <= strlen($data) && preg_match('/^[A-Z]{2}$/', $signature) === 1;
            }

            // end of the current area: go on with the continuation area, if there is one
            if (! $valid || $signature === 'ST') {
                if ($pendingContinuation === null || $isoFile === null) {
                    break;
                }

                [$block, $start, $size] = $pendingContinuation;
                $pendingContinuation = null;
                $extra = self::readArea($isoFile, $block * $blockSize + $start, $size);
                if ($extra === null) {
                    break;
                }

                $data = $extra;
                $offset = 0;
                continue;
            }

            $payload = substr($data, $offset + 4, $length - 4);
            $offset += $length;

            switch ($signature) {
                case 'NM':
                    $found = true;
                    if ($payload === '') {
                        break;
                    }
                    $flags = ord($payload[0]);
                    // CURRENT and PARENT names are not real names
                    if (($flags & 0x06) === 0) {
                        $name = ($nameContinues ? (string) $name : '') . substr($payload, 1);
                    }
                    $nameContinues = ($flags & 0x01) !== 0;
                    break;

                case 'PX':
                    $found = true;
                    $mode = self::bothEndian($payload, 0);
                    $links = self::bothEndian($payload, 8);
                    $uid = self::bothEndian($payload, 16);
                    $gid = self::bothEndian($payload, 24);
                    break;

                case 'SL':
                    $found = true;
                    $symlink = self::symlink($payload, $symlink, $linkContinues);
                    break;

                case 'RE':
                    $found = true;
                    $relocated = true;
                    break;

                case 'CL':
                    $found = true;
                    $childLocation = self::bothEndian($payload, 0);
                    break;

                case 'TF':
                    $found = true;
                    $times = self::timestamps($payload);
                    break;

                case 'PN':
                    $found = true;
                    $deviceHigh = self::bothEndian($payload, 0);
                    $deviceLow = self::bothEndian($payload, 8);
                    break;

                case 'CE':
                    // the rest of the current area is still parsed, the continuation area comes after it
                    if ($isoFile === null || $pendingContinuation !== null || $continuations >= self::MAX_CONTINUATIONS) {
                        break;
                    }

                    $block = self::bothEndian($payload, 0);
                    $start = self::bothEndian($payload, 8);
                    $size = self::bothEndian($payload, 16);
                    if ($block === null || $start === null || $size === null || $size <= 0 || $size > $blockSize) {
                        break;
                    }

                    $continuations++;
                    $pendingContinuation = [$block, $start, $size];
                    break;

                default:
                    break;
            }
        }

        if (! $found) {
            return null;
        }

        return new RockRidgeInfo($name, $mode, $links, $uid, $gid, $symlink, $relocated, $childLocation, $times[0] ?? null, $times[1] ?? null, $times[2] ?? null, $times[3] ?? null, $deviceHigh, $deviceLow);
    }

    /**
     * Read the "len_skp" of the SP entry that opens the system use area of the "." record of the root directory
     *
     * @return int|null null when the area does not start with a valid SP entry
     */
    public static function detectSkip(string $systemUse): ?int
    {
        if (strlen($systemUse) < 7 || ! str_starts_with($systemUse, 'SP') || ord($systemUse[2]) !== 7 || ord($systemUse[4]) !== 0xBE || ord($systemUse[5]) !== 0xEF) {
            return null;
        }

        return ord($systemUse[6]);
    }

    /**
     * Parse the timestamps of a TF entry: creation, modify, access and attributes (null when absent)
     *
     * @return array<int, CarbonImmutable|null>
     */
    private static function timestamps(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $flags = ord($payload[0]);
        $long = ($flags & 0x80) !== 0;
        $size = $long ? 17 : 7;
        $position = 1;
        $times = [];

        // the timestamps are stored in the order of the flag bits, only the first four are exposed
        for ($bit = 0; $bit < 4; $bit++) {
            if (($flags & (1 << $bit)) === 0) {
                $times[$bit] = null;
                continue;
            }

            if ($position + $size > strlen($payload)) {
                break;
            }

            /** @var array<int, int>|false $buffer */
            $buffer = unpack('C*', substr($payload, $position, $size));
            $position += $size;
            if ($buffer === false) {
                break;
            }

            $index = 1;
            try {
                $times[$bit] = $long ? IsoDate::init17($buffer, $index) : IsoDate::init7($buffer, $index);
            } catch (Exception) {
                $times[$bit] = null;
            }
        }

        return $times;
    }

    /**
     * Read the little endian half of a both-endian 32 bits value
     */
    private static function bothEndian(string $payload, int $position): ?int
    {
        if (strlen($payload) < $position + 4) {
            return null;
        }

        $value = unpack('V', substr($payload, $position, 4));

        $result = $value[1] ?? null;

        return is_int($result) ? $result : null;
    }

    /**
     * Append the components of a symbolic link entry to the target built so far
     */
    private static function symlink(string $payload, ?string $current, bool &$continues): string
    {
        $target = $current ?? '';
        $offset = 1; // the first byte holds the flags of the whole entry
        $length = strlen($payload);
        $continuesComponent = $continues;

        while ($offset + 2 <= $length) {
            $flags = ord($payload[$offset]);
            $size = ord($payload[$offset + 1]);
            $content = substr($payload, $offset + 2, $size);
            $offset += 2 + $size;

            if (! $continuesComponent && $target !== '' && ! str_ends_with($target, '/')) {
                $target .= '/';
            }

            if (($flags & 0x08) !== 0) {
                $target = '/';
            } elseif (($flags & 0x04) !== 0) {
                $target .= '..';
            } elseif (($flags & 0x02) !== 0) {
                $target .= '.';
            } else {
                $target .= $content;
            }

            $continuesComponent = ($flags & 0x01) !== 0;
        }

        $continues = $continuesComponent;

        return $target;
    }

    private static function readArea(IsoFile $isoFile, int $position, int $size): ?string
    {
        try {
            $handle = fopen('php://temp', 'w+b');
            if ($handle === false) {
                return null;
            }

            $isoFile->copyRange($position, $size, $handle);
            rewind($handle);
            $content = stream_get_contents($handle);
            fclose($handle);

            return $content === false ? null : $content;
        } catch (Exception) {
            return null;
        }
    }
}
