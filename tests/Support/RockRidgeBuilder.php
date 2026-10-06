<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

/**
 * Builds Rock Ridge / SUSP entries for the system use area of a directory record
 */
final class RockRidgeBuilder
{
    public static function nm(string $name, int $flags = 0): string
    {
        return self::entry('NM', chr($flags) . $name);
    }

    public static function px(int $mode, int $links = 1, int $uid = 0, int $gid = 0): string
    {
        return self::entry('PX', IsoBuilder::bbo32($mode) . IsoBuilder::bbo32($links) . IsoBuilder::bbo32($uid) . IsoBuilder::bbo32($gid));
    }

    public static function sl(string $target): string
    {
        $components = '';
        $parts = explode('/', $target);

        foreach ($parts as $index => $part) {
            if ($index === 0 && $part === '' && count($parts) > 1) {
                $components .= chr(0x08) . chr(0);
            } elseif ($part === '.') {
                $components .= chr(0x02) . chr(0);
            } elseif ($part === '..') {
                $components .= chr(0x04) . chr(0);
            } elseif ($part !== '') {
                $components .= chr(0) . chr(strlen($part)) . $part;
            }
        }

        return self::entry('SL', chr(0) . $components);
    }

    public static function re(): string
    {
        return self::entry('RE', '');
    }

    public static function cl(int $location): string
    {
        return self::entry('CL', IsoBuilder::bbo32($location));
    }

    public static function ce(int $block, int $offset, int $length): string
    {
        return self::entry('CE', IsoBuilder::bbo32($block) . IsoBuilder::bbo32($offset) . IsoBuilder::bbo32($length));
    }

    private static function entry(string $signature, string $payload): string
    {
        return $signature . chr(4 + strlen($payload)) . chr(1) . $payload;
    }
}
