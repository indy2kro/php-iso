<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

use PhpIso\FileDirectory;
use PhpIso\PathTableRecord;

/**
 * Builds directory and path table records through the real readers
 */
final class Records
{
    /**
     * @return array<int, int> the bytes as the readers expect them (1 based)
     */
    public static function bytes(string $raw): array
    {
        /** @var array<int, int>|false $bytes */
        $bytes = unpack('C*', $raw);

        return $bytes === false ? [] : $bytes;
    }

    public static function directory(string $id, int $flags = 0, int $location = 0, int $size = 0, int $jolietLevel = 0): FileDirectory
    {
        $bytes = self::bytes(IsoBuilder::record($id, $location, $size, $flags));
        $offset = 1;

        return FileDirectory::read($bytes, $offset, false, $jolietLevel) ?? throw new \LogicException('Invalid directory record');
    }

    public static function pathRecord(string $id, int $parent, int $dirNum = 1, int $location = 0): PathTableRecord
    {
        $raw = chr(strlen($id)) . "\0" . pack('N', $location) . pack('n', $parent) . $id . (strlen($id) % 2 === 1 ? "\0" : '');
        $bytes = self::bytes($raw);
        $offset = 1;

        return PathTableRecord::read($bytes, $offset, $dirNum) ?? throw new \LogicException('Invalid path table record');
    }
}
