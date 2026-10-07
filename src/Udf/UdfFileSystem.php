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
use PhpIso\WalkWarnings;

/**
 * Read only access to the UDF file system of an image (ECMA-167 / OSTA UDF, 2048 bytes blocks,
 * plain and metadata (UDF 2.50) partition maps; sparable and virtual partitions are not supported)
 *
 * Descriptor tags are verified (checksum and location, ECMA-167 3/7.2): a descriptor failing the check is
 * treated as absent. Allocation extents are checked against the length of their partition.
 *
 * Limits of the metadata partition support: the metadata file is read, the mirror file and the bitmap file are
 * ignored (no fallback when the metadata file is damaged), and the data of files whose short allocation
 * descriptors sit in a file entry of the metadata partition is read from the underlying physical partition.
 *
 * For the entries produced here "location" and the extents hold absolute byte offsets in the image
 * (a negative offset is a sparse extent, read as zeros).
 */
final readonly class UdfFileSystem implements FileSystem
{
    use BrowsesEntries;

    private const int SECTOR = 2048;

    private const int TAG_PRIMARY_VOLUME = 1;
    private const int TAG_ANCHOR = 2;
    private const int TAG_PARTITION = 5;
    private const int TAG_LOGICAL_VOLUME = 6;
    private const int TAG_TERMINATING = 8;
    private const int TAG_FILE_SET = 256;
    private const int TAG_FILE_ID = 257;
    private const int TAG_ALLOCATION_EXTENT = 258;
    private const int TAG_FILE_ENTRY = 261;
    private const int TAG_EXTENDED_FILE_ENTRY = 266;

    private const int FILE_TYPE_DIRECTORY = 4;

    /**
     * Limits that keep a crafted image from exhausting memory or looping
     */
    private const int MAX_VDS_SECTORS = 64;
    private const int MAX_EXTENTS = 100000;
    private const int MAX_CONTINUATIONS = 64;

    private const string METADATA_IDENTIFIER = '*UDF Metadata Partition';

    /**
     * @param array<int, UdfPartition> $partitions partition map index => partition
     */
    private function __construct(
        private array $partitions,
        private int $rootPartition,
        private int $rootBlock,
        public string $volumeId,
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
            $data = self::readDescriptor($isoFile, $candidate, $candidate);
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
                $data = self::readDescriptor($isoFile, $location + $i, $location + $i);
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
                    $partitions[self::u16($data, 22)] = [self::u32($data, 188), self::u32($data, 192)];
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

        // plain partitions first, a metadata partition lives inside of one of them
        $physical = [];
        foreach ($maps as $index => [$partitionNumber, $metadataBlock]) {
            if (! isset($partitions[$partitionNumber])) {
                throw new Exception('UDF partition ' . $partitionNumber . ' not found');
            }

            if ($metadataBlock < 0) {
                $physical[$index] = UdfPartition::physical(...$partitions[$partitionNumber]);
            }
        }

        $starts = $physical;
        foreach ($maps as $index => [$partitionNumber, $metadataBlock]) {
            if ($metadataBlock >= 0) {
                $underlying = UdfPartition::physical(...$partitions[$partitionNumber]);
                $starts[$index] = self::metadataPartition($isoFile, $physical, $underlying, $metadataBlock);
            }
        }
        ksort($starts);

        [$fsdLength, $fsdBlock, $fsdPartition] = $fileSet;
        if (! isset($starts[$fsdPartition])) {
            throw new Exception('Invalid partition reference in the UDF file set descriptor');
        }

        // the file set descriptor holds the root directory
        for ($i = 0; $i < min(max(intdiv($fsdLength, self::SECTOR), 1), 16); $i++) {
            $offset = $starts[$fsdPartition]->offset($fsdBlock + $i);
            $data = $offset === null ? null : self::readDescriptor($isoFile, intdiv($offset, self::SECTOR), $fsdBlock + $i);
            if ($data !== null && self::tag($data) === self::TAG_FILE_SET) {
                return new self($starts, self::u16($data, 408), self::u32($data, 404), $volumeId);
            }
        }

        throw new Exception('UDF file set descriptor not found');
    }

    public function walk(IsoFile $isoFile, int $maxDepth = 64, ?WalkWarnings $warnings = null): Generator
    {
        $root = $this->readRoot($isoFile, $warnings);
        if (! $root instanceof UdfNode) {
            return;
        }

        $visited = [$this->rootPartition . ':' . $this->rootBlock => true];

        /** @var list<array{string, UdfNode, int}> $stack path, directory, depth */
        $stack = [['', $root, 0]];

        while ($stack !== []) {
            [$base, $directory, $depth] = array_pop($stack);

            $subDirectories = [];
            foreach ($this->directoryEntries($isoFile, $base, $directory, $warnings) as [$entry, $node, $key]) {
                yield $entry;

                if (! $node->isDirectory || isset($visited[$key])) {
                    continue;
                }

                if ($depth >= $maxDepth) {
                    $warnings?->add('depth limit (' . $maxDepth . ') reached, not listing ' . $entry->path);
                    continue;
                }

                $visited[$key] = true;
                $subDirectories[] = [$entry->path, $node, $depth + 1];
            }

            foreach (array_reverse($subDirectories) as $sub) {
                $stack[] = $sub;
            }
        }
    }

    /**
     * The root directory node, null (with a warning) when it cannot be read
     */
    private function readRoot(IsoFile $isoFile, ?WalkWarnings $warnings): ?UdfNode
    {
        try {
            $root = $this->readNode($isoFile, $this->rootPartition, $this->rootBlock);
        } catch (Exception $exception) {
            $warnings?->add('UDF root directory cannot be read (partition ' . $this->rootPartition . ', block ' . $this->rootBlock . '): ' . $exception->getMessage());

            return null;
        }

        if (! $root instanceof UdfNode || ! $root->isDirectory) {
            $warnings?->add('UDF root directory not found (partition ' . $this->rootPartition . ', block ' . $this->rootBlock . ')');

            return null;
        }

        return $root;
    }

    public function listDirectory(IsoFile $isoFile, ?IsoEntry $directory = null, ?WalkWarnings $warnings = null): Generator
    {
        if (! $directory instanceof IsoEntry) {
            $node = $this->readRoot($isoFile, $warnings);
            if (! $node instanceof UdfNode) {
                return;
            }

            $base = '';
        } elseif ($directory->isDirectory) {
            $node = new UdfNode(true, $directory->size, $directory->extents, null);
            $base = $directory->path;
        } else {
            return;
        }

        foreach ($this->directoryEntries($isoFile, $base, $node, $warnings) as [$entry]) {
            yield $entry;
        }
    }

    /**
     * @return list<array{int, int}> absolute byte ranges, a negative offset is a sparse extent (zeros)
     */
    public function getEntryRanges(IsoFile $isoFile, IsoEntry $entry): array
    {
        return $entry->getExtents();
    }

    /**
     * The entries of a single directory: non regular entries (devices, FIFOs, sockets) are not reported
     *
     * @return Generator<int, array{IsoEntry, UdfNode, string}> the entry, its node and the partition:block key of the node
     */
    private function directoryEntries(IsoFile $isoFile, string $base, UdfNode $directory, ?WalkWarnings $warnings = null): Generator
    {
        foreach ($this->readDirectory($isoFile, $directory, $base, $warnings) as [$name, $hidden, $partition, $block]) {
            // a corrupt or unsupported entry is skipped, the rest of the tree stays readable
            try {
                $node = $this->readNode($isoFile, $partition, $block);
            } catch (Exception $exception) {
                $warnings?->add('UDF entry ' . $base . '/' . $name . ' skipped (partition ' . $partition . ', block ' . $block . '): ' . $exception->getMessage());
                continue;
            }

            if (! $node instanceof UdfNode) {
                $warnings?->add('UDF entry ' . $base . '/' . $name . ' skipped, no file entry at partition ' . $partition . ', block ' . $block);
                continue;
            }

            // devices, FIFOs and sockets carry no data: skipped rather than reported as empty or bogus files
            if ($node->isSpecial()) {
                continue;
            }

            $location = $node->extents[0][0] ?? 0;
            $target = $node->isSymlink() ? $this->readSymlinkTarget($isoFile, $node) : null;

            yield [
                new IsoEntry($base . '/' . $name, $name, $node->isDirectory, $node->size, max($location, 0), $node->modified, $hidden, $node->extents, null, $target, $node->uid, $node->gid, $node->mode),
                $node,
                $partition . ':' . $block,
            ];
        }
    }

    /**
     * Decode the path component records (ECMA-167 4/14.16) stored as the data of a symbolic link
     *
     * Component types: 1 and 2 root directory (a type 1 component carrying a name is read as a name, like Linux
     * does), 3 parent directory, 4 current directory, 5 a name. An unreadable link gives an empty target.
     */
    private function readSymlinkTarget(IsoFile $isoFile, UdfNode $node): string
    {
        if ($node->size > 65536) {
            return '';
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return '';
        }

        try {
            $this->copyEntryTo($isoFile, new IsoEntry('', '', false, $node->size, 0, null, false, $node->extents), $stream);
            rewind($stream);
            $data = (string) stream_get_contents($stream);
        } catch (Exception) {
            return '';
        } finally {
            fclose($stream);
        }

        $root = false;
        $parts = [];
        for ($pos = 0; $pos + 4 <= strlen($data);) {
            $type = ord($data[$pos]);
            $length = ord($data[$pos + 1]);
            $identifier = substr($data, $pos + 4, $length);
            $pos += 4 + $length;

            if ($type === 1 && $length > 0) {
                $type = 5;
            }

            if ($type === 1 || $type === 2) {
                $root = $parts === [];
            } elseif ($type === 3) {
                $parts[] = '..';
            } elseif ($type === 4) {
                $parts[] = '.';
            } elseif ($type === 5 && $length > 0) {
                $parts[] = self::decodeName($identifier);
            }
        }

        return ($root ? '/' : '') . implode('/', $parts);
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
     * Read the metadata file of a metadata partition and build the partition from its extents
     *
     * @param array<int, UdfPartition> $physical the plain partitions known so far (allocation descriptor references)
     *
     * @throws Exception when the metadata file cannot be read
     */
    private static function metadataPartition(IsoFile $isoFile, array $physical, UdfPartition $underlying, int $metadataBlock): UdfPartition
    {
        $reader = new self($physical, 0, 0, '');
        $node = $reader->readNodeIn($isoFile, $underlying, $metadataBlock);

        if (! $node instanceof UdfNode || $node->isDirectory) {
            throw new Exception('UDF metadata file not found');
        }

        return UdfPartition::metadata($node->extents, $underlying);
    }

    /**
     * @return array{array<int, array{int, int}>, array{int, int, int}} partition maps (index => partition number and
     *         block of the metadata file, -1 for a plain partition) and the file set location
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

            if ($type === 1 && $length >= 6) {
                $maps[$i] = [self::u16($data, $offset + 4), -1];
            } elseif ($type === 2 && $length >= 44) {
                // partition type identifier: entity identifier at offset 4 (flags, then 23 characters)
                $identifier = rtrim(substr($data, $offset + 5, 23), "\0");
                if ($identifier !== self::METADATA_IDENTIFIER) {
                    throw new Exception('Unsupported UDF partition map (' . ($identifier === '' ? 'type 2' : $identifier) . '): only plain and metadata partitions can be read');
                }

                $maps[$i] = [self::u16($data, $offset + 38), self::u32($data, $offset + 40)];
            } else {
                throw new Exception('Unsupported UDF partition map (type ' . $type . '): only plain and metadata partitions can be read');
            }

            $offset += $length;
        }

        return [$maps, [self::u32($data, 248), self::u32($data, 252), self::u16($data, 256)]];
    }

    private function readNode(IsoFile $isoFile, int $partition, int $block): ?UdfNode
    {
        $reference = $this->partitions[$partition] ?? null;

        return $reference === null ? null : $this->readNodeIn($isoFile, $reference, $block);
    }

    /**
     * @throws Exception
     */
    private function readNodeIn(IsoFile $isoFile, UdfPartition $partition, int $block): ?UdfNode
    {
        $offset = $partition->offset($block);
        if ($offset === null) {
            return null;
        }

        $data = self::readDescriptor($isoFile, intdiv($offset, self::SECTOR), $block);
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

        $isDirectory = $fileType === self::FILE_TYPE_DIRECTORY;
        $extents = $this->allocationExtents($isoFile, $data, $adStart, $adLength, $flags & 0x07, $size, $partition, $isDirectory ? $partition : $partition->dataPartition(), $offset);

        $permissions = self::u32($data, 44);
        $mode = (($permissions & 7) | ((($permissions >> 5) & 7) << 3) | ((($permissions >> 10) & 7) << 6))
            | match ($fileType) {
                self::FILE_TYPE_DIRECTORY => 0040000,
                12 => 0120000,
                5 => 0100000,
                default => 0,
            };
        $uid = self::u32($data, 36);
        $gid = self::u32($data, 40);

        // 0xFFFFFFFF means "not specified"
        return new UdfNode($isDirectory, $size, $extents, $modified, $fileType, $uid === 0xFFFFFFFF ? null : $uid, $gid === 0xFFFFFFFF ? null : $gid, $mode);
    }

    /**
     * @return list<array{int, int}> (absolute byte offset, length) pairs, a negative offset marks a sparse extent
     *
     * @throws Exception
     */
    private function allocationExtents(IsoFile $isoFile, string $data, int $adStart, int $adLength, int $type, int $size, UdfPartition $partition, UdfPartition $dataPartition, int $sectorOffset): array
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
                $reference = $type === 1 ? self::u16($area, $pos + 8) : null;

                if ($length === 0 && $kind === 0) {
                    break 2;
                }

                // the next allocation descriptors are stored in an allocation extent
                if ($kind === 3) {
                    $target = $reference === null ? $partition : ($this->partitions[$reference] ?? null);
                    if (++$continuations > self::MAX_CONTINUATIONS || $target === null) {
                        throw new Exception('Invalid UDF allocation extent chain');
                    }

                    $offset = $target->offset($block);
                    $next = $offset === null ? null : self::readDescriptor($isoFile, intdiv($offset, self::SECTOR), $block);
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

                $target = $reference === null ? $dataPartition : ($this->partitions[$reference] ?? null);
                if ($kind === 0 && $target !== null) {
                    // an extent leaving its partition is corrupt (it may point anywhere in the image)
                    $ranges = $target->ranges($block, $length);
                    if ($ranges === null) {
                        throw new Exception('UDF extent outside of its partition');
                    }

                    if (count($extents) + count($ranges) > self::MAX_EXTENTS) {
                        throw new Exception('Too many UDF extents');
                    }

                    array_push($extents, ...$ranges);
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
    private function readDirectory(IsoFile $isoFile, UdfNode $directory, string $base = '', ?WalkWarnings $warnings = null): Generator
    {
        if ($directory->size > IsoFile::MAX_READ_LENGTH) {
            $warnings?->add('UDF directory too large to be read: ' . ($base === '' ? '/' : $base));

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
        } catch (Exception $exception) {
            $warnings?->add('UDF directory cannot be read: ' . ($base === '' ? '/' : $base) . ' (' . $exception->getMessage() . ')');

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
                $warnings?->add('UDF directory ends with a truncated entry at offset ' . $pos . ': ' . ($base === '' ? '/' : $base));
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

    private static function timestamp(string $data, int $offset): ?\Carbon\CarbonImmutable
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

    /**
     * Read a descriptor and verify its tag: the checksum (byte 4 is the sum of the other bytes of the first 16
     * modulo 256) and the location (the sector number of the descriptor, relative to the partition for the
     * descriptors inside of one)
     *
     * @return string|null null when the sector cannot be read or the tag is not valid
     */
    private static function readDescriptor(IsoFile $isoFile, int $sector, int $location): ?string
    {
        $data = self::readSector($isoFile, $sector);
        if ($data === null) {
            return null;
        }

        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            if ($i !== 4) {
                $sum += ord($data[$i]);
            }
        }

        if (($sum & 0xFF) !== ord($data[4]) || self::u32($data, 12) !== $location) {
            return null;
        }

        return $data;
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
