<?php

declare(strict_types=1);

namespace PhpIso\Udf;

use Generator;
use PhpIso\BrowsesEntries;
use PhpIso\Exception;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Util\IsoDate;

/**
 * Read only access to the UDF file system of an image (ECMA-167 / OSTA UDF, 2048 bytes blocks,
 * plain partition maps; sparable, virtual and metadata partitions are not supported)
 *
 * For the entries produced here "location" and the extents hold absolute byte offsets in the image
 * (a negative offset is a sparse extent, read as zeros).
 */
final class UdfFileSystem implements FileSystem
{
    use BrowsesEntries;

    private const SECTOR = 2048;

    private const TAG_PRIMARY_VOLUME = 1;
    private const TAG_ANCHOR = 2;
    private const TAG_PARTITION = 5;
    private const TAG_LOGICAL_VOLUME = 6;
    private const TAG_TERMINATING = 8;
    private const TAG_FILE_SET = 256;
    private const TAG_FILE_ID = 257;
    private const TAG_ALLOCATION_EXTENT = 258;
    private const TAG_FILE_ENTRY = 261;
    private const TAG_EXTENDED_FILE_ENTRY = 266;

    private const FILE_TYPE_DIRECTORY = 4;

    /**
     * Limits that keep a crafted image from exhausting memory or looping
     */
    private const MAX_VDS_SECTORS = 64;
    private const MAX_EXTENTS = 100000;
    private const MAX_CONTINUATIONS = 64;

    /**
     * @param array<int, int> $partitionStarts partition map index => first sector of the partition
     */
    private function __construct(
        private readonly array $partitionStarts,
        private readonly int $rootPartition,
        private readonly int $rootBlock,
        public readonly string $volumeId,
    ) {
    }

    /**
     * Open the UDF file system of the image
     *
     * @return self|null null when the image has no UDF anchor
     *
     * @throws Exception when the UDF structures are present but unsupported or corrupt
     */
    public static function open(IsoFile $isoFile): ?self
    {
        $sectors = intdiv($isoFile->getSize(), self::SECTOR);
        if ($sectors < 257) {
            return null;
        }

        $anchor = null;
        foreach ([256, $sectors - 257, $sectors - 1] as $candidate) {
            $data = self::readSector($isoFile, $candidate);
            if ($data !== null && self::tag($data) === self::TAG_ANCHOR) {
                $anchor = $data;
                break;
            }
        }

        if ($anchor === null) {
            return null;
        }

        $volumeId = '';
        $partitions = [];
        $maps = [];
        $fileSet = null;

        // main sequence first, the reserve sequence when the main one is unusable
        foreach ([16, 24] as $extentOffset) {
            $length = self::u32($anchor, $extentOffset);
            $location = self::u32($anchor, $extentOffset + 4);

            $partitions = [];
            $maps = [];
            $fileSet = null;
            $volumeId = '';

            for ($i = 0; $i < min(intdiv($length, self::SECTOR), self::MAX_VDS_SECTORS); $i++) {
                $data = self::readSector($isoFile, $location + $i);
                if ($data === null) {
                    break;
                }

                $tag = self::tag($data);
                if ($tag === self::TAG_TERMINATING || $tag === 0) {
                    break;
                }

                if ($tag === self::TAG_PRIMARY_VOLUME) {
                    $volumeId = self::dstring(substr($data, 24, 32));
                } elseif ($tag === self::TAG_PARTITION) {
                    $partitions[self::u16($data, 22)] = self::u32($data, 188);
                } elseif ($tag === self::TAG_LOGICAL_VOLUME) {
                    [$maps, $fileSet] = self::parseLogicalVolume($data);
                }
            }

            if ($maps !== [] && $fileSet !== null) {
                break;
            }
        }

        if ($maps === [] || $fileSet === null) {
            throw new Exception('Incomplete UDF volume descriptor sequence');
        }

        $starts = [];
        foreach ($maps as $index => $partitionNumber) {
            if (! isset($partitions[$partitionNumber])) {
                throw new Exception('UDF partition ' . $partitionNumber . ' not found');
            }
            $starts[$index] = $partitions[$partitionNumber];
        }

        [$fsdLength, $fsdBlock, $fsdPartition] = $fileSet;
        if (! isset($starts[$fsdPartition])) {
            throw new Exception('Invalid partition reference in the UDF file set descriptor');
        }

        // the file set descriptor holds the root directory
        for ($i = 0; $i < min(max(intdiv($fsdLength, self::SECTOR), 1), 16); $i++) {
            $data = self::readSector($isoFile, $starts[$fsdPartition] + $fsdBlock + $i);
            if ($data !== null && self::tag($data) === self::TAG_FILE_SET) {
                return new self($starts, self::u16($data, 408), self::u32($data, 404), $volumeId);
            }
        }

        throw new Exception('UDF file set descriptor not found');
    }

    public function walk(IsoFile $isoFile, int $maxDepth = 64): Generator
    {
        try {
            $root = $this->readNode($isoFile, $this->rootPartition, $this->rootBlock);
        } catch (Exception) {
            return;
        }

        if (! $root instanceof UdfNode || ! $root->isDirectory) {
            return;
        }

        $visited = [$this->rootPartition . ':' . $this->rootBlock => true];

        /** @var list<array{string, UdfNode, int}> $stack path, directory, depth */
        $stack = [['', $root, 0]];

        while ($stack !== []) {
            [$base, $directory, $depth] = array_pop($stack);

            $subDirectories = [];
            foreach ($this->readDirectory($isoFile, $directory) as [$name, $hidden, $partition, $block]) {
                // a corrupt or unsupported entry is skipped, the rest of the tree stays readable
                try {
                    $node = $this->readNode($isoFile, $partition, $block);
                } catch (Exception) {
                    continue;
                }

                if (! $node instanceof UdfNode) {
                    continue;
                }

                $path = $base . '/' . $name;
                $location = $node->extents[0][0] ?? 0;

                yield new IsoEntry($path, $name, $node->isDirectory, $node->size, max($location, 0), $node->modified, $hidden, $node->extents);

                $key = $partition . ':' . $block;
                if ($node->isDirectory && $depth < $maxDepth && ! isset($visited[$key])) {
                    $visited[$key] = true;
                    $subDirectories[] = [$path, $node, $depth + 1];
                }
            }

            foreach (array_reverse($subDirectories) as $sub) {
                $stack[] = $sub;
            }
        }
    }

    public function copyEntryTo(IsoFile $isoFile, IsoEntry $entry, mixed $output): void
    {
        if ($entry->isDirectory) {
            throw new Exception('Cannot read the content of a directory: ' . $entry->path);
        }

        foreach ($entry->getExtents() as [$offset, $length]) {
            if ($offset < 0) {
                // sparse extent
                for ($remaining = $length; $remaining > 0; $remaining -= 8192) {
                    if (fwrite($output, str_repeat("\0", min(8192, $remaining))) === false) {
                        throw new Exception('Failed to write the data');
                    }
                }
                continue;
            }

            $isoFile->copyRange($offset, $length, $output);
        }
    }

    /**
     * @return array{array<int, int>, array{int, int, int}} partition maps (index => partition number) and the file set location
     *
     * @throws Exception
     */
    private static function parseLogicalVolume(string $data): array
    {
        if (self::u32($data, 212) !== self::SECTOR) {
            throw new Exception('Unsupported UDF logical block size: ' . self::u32($data, 212));
        }

        $maps = [];
        $count = min(self::u32($data, 268), 32);
        $offset = 440;
        for ($i = 0; $i < $count; $i++) {
            if (! isset($data[$offset + 1])) {
                break;
            }

            $type = ord($data[$offset]);
            $length = ord($data[$offset + 1]);

            if ($type !== 1 || $length < 6) {
                throw new Exception('Unsupported UDF partition map (type ' . $type . '): only plain partitions can be read');
            }

            $maps[$i] = self::u16($data, $offset + 4);
            $offset += $length;
        }

        return [$maps, [self::u32($data, 248), self::u32($data, 252), self::u16($data, 256)]];
    }

    private function readNode(IsoFile $isoFile, int $partition, int $block): ?UdfNode
    {
        $start = $this->partitionStarts[$partition] ?? null;
        if ($start === null) {
            return null;
        }

        $data = self::readSector($isoFile, $start + $block);
        if ($data === null) {
            return null;
        }

        $tag = self::tag($data);
        if ($tag !== self::TAG_FILE_ENTRY && $tag !== self::TAG_EXTENDED_FILE_ENTRY) {
            return null;
        }

        $extended = $tag === self::TAG_EXTENDED_FILE_ENTRY;
        $fileType = ord($data[27]);
        $flags = self::u16($data, 34);
        $size = self::u64($data, 56);
        $modified = self::timestamp($data, $extended ? 92 : 84);
        $eaLength = self::u32($data, $extended ? 208 : 168);
        $adLength = self::u32($data, $extended ? 212 : 172);
        $adStart = ($extended ? 216 : 176) + $eaLength;

        if ($adStart + $adLength > self::SECTOR) {
            return null;
        }

        $extents = $this->allocationExtents($isoFile, $data, $adStart, $adLength, $flags & 0x07, $size, $partition, ($start + $block) * self::SECTOR);

        return new UdfNode($fileType === self::FILE_TYPE_DIRECTORY, $size, $extents, $modified);
    }

    /**
     * @return list<array{int, int}> (absolute byte offset, length) pairs, a negative offset marks a sparse extent
     *
     * @throws Exception
     */
    private function allocationExtents(IsoFile $isoFile, string $data, int $adStart, int $adLength, int $type, int $size, int $partition, int $sectorOffset): array
    {
        // data embedded in the file entry itself
        if ($type === 3) {
            return [[$sectorOffset + $adStart, min($adLength, $size)]];
        }

        if ($type !== 0 && $type !== 1) {
            throw new Exception('Unsupported UDF allocation descriptor type: ' . $type);
        }

        $descriptorSize = $type === 0 ? 8 : 16;
        $extents = [];
        $total = 0;
        $continuations = 0;
        $area = substr($data, $adStart, $adLength);

        while ($area !== '') {
            for ($pos = 0; $pos + $descriptorSize <= strlen($area); $pos += $descriptorSize) {
                $raw = self::u32($area, $pos);
                $kind = $raw >> 30;
                $length = $raw & 0x3FFFFFFF;
                $block = self::u32($area, $pos + 4);
                $reference = $type === 1 ? self::u16($area, $pos + 8) : $partition;

                if ($length === 0 && $kind === 0) {
                    break 2;
                }

                // the next allocation descriptors are stored in an allocation extent
                if ($kind === 3) {
                    if (++$continuations > self::MAX_CONTINUATIONS || ! isset($this->partitionStarts[$reference])) {
                        throw new Exception('Invalid UDF allocation extent chain');
                    }

                    $next = self::readSector($isoFile, $this->partitionStarts[$reference] + $block);
                    if ($next === null || self::tag($next) !== self::TAG_ALLOCATION_EXTENT) {
                        throw new Exception('Invalid UDF allocation extent');
                    }

                    $area = substr($next, 24, min(self::u32($next, 20), self::SECTOR - 24));
                    continue 2;
                }

                if (count($extents) >= self::MAX_EXTENTS) {
                    throw new Exception('Too many UDF extents');
                }

                $length = min($length, $size - $total);
                if ($length <= 0) {
                    break 2;
                }

                if ($kind === 0 && isset($this->partitionStarts[$reference])) {
                    $extents[] = [($this->partitionStarts[$reference] + $block) * self::SECTOR, $length];
                } else {
                    $extents[] = [-1, $length];
                }

                $total += $length;
            }

            break;
        }

        return $extents;
    }

    /**
     * @return Generator<int, array{string, bool, int, int}> name, hidden, partition reference and block of the entry
     */
    private function readDirectory(IsoFile $isoFile, UdfNode $directory): Generator
    {
        if ($directory->size > IsoFile::MAX_READ_LENGTH) {
            return;
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return;
        }

        try {
            $entry = new IsoEntry('', '', false, $directory->size, 0, null, false, $directory->extents);
            $this->copyEntryTo($isoFile, $entry, $stream);
            rewind($stream);
            $data = (string) stream_get_contents($stream);
        } catch (Exception) {
            return;
        } finally {
            fclose($stream);
        }

        $length = strlen($data);
        $pos = 0;
        while ($pos + 38 <= $length && self::u16($data, $pos) === self::TAG_FILE_ID) {
            $characteristics = ord($data[$pos + 18]);
            $nameLength = ord($data[$pos + 19]);
            $implementationLength = self::u16($data, $pos + 36);
            $total = ($pos + 38 + $implementationLength + $nameLength + 3) & ~3;
            $total -= $pos;

            if ($pos + 38 + $implementationLength + $nameLength > $length) {
                break;
            }

            // bit 2: deleted, bit 3: parent directory
            if (($characteristics & 0x0C) === 0 && $nameLength > 0) {
                $name = self::decodeName(substr($data, $pos + 38 + $implementationLength, $nameLength));
                if ($name !== '') {
                    yield [$name, ($characteristics & 0x01) !== 0, self::u16($data, $pos + 28), self::u32($data, $pos + 24)];
                }
            }

            $pos += $total;
        }
    }

    /**
     * Decode an OSTA compressed unicode name (compression id 8: one byte per character, 16: UTF-16BE)
     */
    private static function decodeName(string $raw): string
    {
        $compression = ord($raw[0]);
        $text = substr($raw, 1);

        return match ($compression) {
            8 => mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'),
            16 => mb_convert_encoding($text, 'UTF-8', 'UTF-16BE'),
            default => $text,
        };
    }

    /**
     * A fixed length d-string: the last byte holds the number of bytes used
     */
    private static function dstring(string $raw): string
    {
        $length = ord($raw[strlen($raw) - 1]);
        if ($length < 1 || $length >= strlen($raw)) {
            return '';
        }

        return trim(self::decodeName(substr($raw, 0, $length)));
    }

    private static function timestamp(string $data, int $offset): ?\Carbon\Carbon
    {
        $parts = unpack('vzone/vyear/Cmonth/Cday/Chour/Cminute/Csecond', substr($data, $offset, 9));
        if ($parts === false) {
            return null;
        }

        [$zone, $year, $month, $day, $hour, $minute, $second] = array_map(static fn (mixed $value): int => is_int($value) ? $value : 0, array_values($parts));

        $zone &= 0x0FFF;
        if ($zone >= 0x800) {
            $zone -= 0x1000;
        }

        // -2047 means "no time zone specified"
        $minutes = $zone === -2047 ? 0 : $zone;

        return IsoDate::createWithOffsetMinutes($year, $month, $day, $hour, $minute, $second, $minutes);
    }

    private static function readSector(IsoFile $isoFile, int $sector): ?string
    {
        if ($sector < 0 || ($sector + 1) * self::SECTOR > $isoFile->getSize()) {
            return null;
        }

        if ($isoFile->seek($sector * self::SECTOR, SEEK_SET) === -1) {
            return null;
        }

        $data = $isoFile->read(self::SECTOR);

        return ($data === false || strlen($data) < self::SECTOR) ? null : $data;
    }

    private static function tag(string $data): int
    {
        return self::u16($data, 0);
    }

    private static function u16(string $data, int $offset): int
    {
        $value = unpack('v', substr($data, $offset, 2));

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function u32(string $data, int $offset): int
    {
        $value = unpack('V', substr($data, $offset, 4));

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function u64(string $data, int $offset): int
    {
        $value = unpack('P', substr($data, $offset, 8));

        return is_array($value) && is_int($value[1] ?? null) ? max($value[1], 0) : 0;
    }
}
