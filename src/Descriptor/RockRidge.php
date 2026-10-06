<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Exception;
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
    public static function parse(string $systemUse, ?IsoFile $isoFile = null, int $blockSize = 2048): ?RockRidgeInfo
    {
        if (strlen($systemUse) < 4) {
            return null;
        }

        $name = null;
        $symlink = null;
        $mode = $links = $uid = $gid = $childLocation = null;
        $relocated = false;
        $found = false;
        $nameContinues = false;
        $linkContinues = false;
        $continuations = 0;

        $data = $systemUse;
        $offset = 0;

        while (true) {
            if ($offset + 4 > strlen($data)) {
                break;
            }

            $signature = substr($data, $offset, 2);
            $length = ord($data[$offset + 2]);

            // an entry must be at least its header and stay inside the area, padding bytes end the list
            if ($length < 4 || $offset + $length > strlen($data) || preg_match('/^[A-Z]{2}$/', $signature) !== 1) {
                break;
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

                case 'CE':
                    if ($isoFile === null || ++$continuations > self::MAX_CONTINUATIONS) {
                        break;
                    }

                    $block = self::bothEndian($payload, 0);
                    $start = self::bothEndian($payload, 8);
                    $size = self::bothEndian($payload, 16);
                    if ($block === null || $start === null || $size === null || $size <= 0 || $size > $blockSize) {
                        break;
                    }

                    $extra = self::readArea($isoFile, $block * $blockSize + $start, $size);
                    if ($extra !== null) {
                        // continue parsing in the continuation area
                        $data = $extra;
                        $offset = 0;
                    }
                    break;

                case 'ST':
                    break 2;

                default:
                    break;
            }
        }

        if (! $found) {
            return null;
        }

        return new RockRidgeInfo($name, $mode, $links, $uid, $gid, $symlink, $relocated, $childLocation);
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
