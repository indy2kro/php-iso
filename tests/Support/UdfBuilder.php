<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

/**
 * Builds small UDF images (plain partition, 2048 bytes blocks) from a directory tree
 *
 * Options:
 *  - adType: 0 short allocation descriptors, 1 long ones
 *  - extended: write extended file entries (tag 266) instead of file entries (tag 261)
 *  - inline: store files up to this many bytes (and small directories) inside their file entry, 0 disables
 *  - fragment: split the data of every file in extents of one block
 *  - maxAds: number of allocation descriptors kept in a file entry, the rest goes to allocation extents
 *  - sparse: write blocks made only of zeros as holes
 *  - mapType: partition map type (1 is the only supported one)
 *  - metadata: UDF 2.50 layout, the file entries, directories and the file set descriptor live in a metadata
 *    partition (second partition map) whose metadata file is stored in two extents of the physical partition
 *  - loop: add an entry pointing back to its own directory to every directory
 *  - ghosts: the root directory also lists entries pointing to an unknown partition and beyond the end of the image
 *  - overrun: the root directory ends with an entry whose name is longer than the data
 */
final class UdfBuilder
{
    private const int SECTOR = 2048;
    private const int PARTITION_START = 260;

    private int $next = 1;

    private int $metaNext = 1;

    private int $metadataFile = 0;

    /**
     * @var array<int, string> metadata partition blocks (metadata option)
     */
    private array $metaBlocks = [];

    private readonly IsoBuilder $image;

    /**
     * @var array{adType: int, extended: bool, inline: int, fragment: bool, maxAds: int, sparse: bool, mapType: int, loop: bool, ghosts: bool, overrun: bool, metadata: bool}
     */
    private readonly array $options;

    /**
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool, mapType?: int, loop?: bool, ghosts?: bool, overrun?: bool, metadata?: bool} $options
     */
    private function __construct(array $options)
    {
        $this->options = [
            'adType' => $options['adType'] ?? 0,
            'extended' => $options['extended'] ?? false,
            'inline' => $options['inline'] ?? 0,
            'fragment' => $options['fragment'] ?? false,
            'maxAds' => $options['maxAds'] ?? 1000,
            'sparse' => $options['sparse'] ?? false,
            'mapType' => $options['mapType'] ?? 1,
            'loop' => $options['loop'] ?? false,
            'ghosts' => $options['ghosts'] ?? false,
            'overrun' => $options['overrun'] ?? false,
            'metadata' => $options['metadata'] ?? false,
        ];
        $this->image = new IsoBuilder();
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool, mapType?: int, loop?: bool, ghosts?: bool, overrun?: bool, metadata?: bool} $options
     */
    public static function build(array $tree, array $options = []): IsoBuilder
    {
        $builder = new self($options);
        $builder->layout($tree);

        return $builder->image;
    }

    /**
     * @param array<array-key, mixed> $tree
     */
    private function layout(array $tree): void
    {
        // file set descriptor at block 0, root directory at block 1
        $meta = $this->options['metadata'];
        $this->writeBlock(0, self::tag(256, 0) . str_repeat("\0", 384) . self::longAd(self::SECTOR, 1, $meta ? 1 : 0), $meta);
        $this->writeNode(1, $tree, 1);

        if ($meta) {
            $this->writeMetadataFile();
        }

        $this->writeAnchorAndVolumeSequence();
    }

    /**
     * Store the metadata blocks in the physical partition as a metadata file made of two extents with a gap between
     */
    private function writeMetadataFile(): void
    {
        $count = $this->metaNext + 1;
        $first = intdiv($count + 1, 2);
        $entryBlock = $this->next + 1;
        $starts = [$entryBlock + 1, $entryBlock + 1 + $first + 3];

        foreach (range(0, $count - 1) as $block) {
            $target = $block < $first ? $starts[0] + $block : $starts[1] + $block - $first;
            $this->image->setSector(self::PARTITION_START + $target, $this->metaBlocks[$block] ?? '');
        }

        $descriptors = self::allocationDescriptor(0, $first * self::SECTOR, $starts[0], 0, 0)
            . self::allocationDescriptor(0, ($count - $first) * self::SECTOR, $starts[1], 0, 0);

        $entry = self::tag(261, $entryBlock);
        $entry = str_pad($entry, 27, "\0") . chr(250);
        $entry = str_pad($entry, 56, "\0") . pack('P', $count * self::SECTOR);
        $entry = str_pad($entry, 168, "\0") . pack('V', 0) . pack('V', strlen($descriptors));
        $this->writeBlock($entryBlock, str_pad($entry, 176, "\0") . $descriptors);

        $this->metadataFile = $entryBlock;
    }

    private function writeAnchorAndVolumeSequence(): void
    {
        // the recognition sequence
        foreach (['BEA01', 'NSR02', 'TEA01'] as $index => $identifier) {
            $this->image->setSector(16 + $index, chr(0) . $identifier . chr(1));
        }

        $this->image->setSector(256, self::tag(2, 256) . pack('VV', 16 * self::SECTOR, 32) . pack('VV', 16 * self::SECTOR, 48));

        foreach ([32, 48] as $base) {
            $volumeId = chr(8) . 'TESTUDF';
            $this->image->setSector($base, self::tag(1, $base) . str_repeat("\0", 8) . str_pad($volumeId, 31, "\0") . chr(strlen($volumeId)));

            $partition = self::tag(5, $base + 1) . pack('V', 1) . pack('v', 1) . pack('v', 1) . str_repeat("\0", 164) . pack('V', self::PARTITION_START) . pack('V', 100000);
            $this->image->setSector($base + 1, $partition);

            $mapType = $this->options['mapType'];
            $meta = $this->options['metadata'];
            $logical = self::tag(6, $base + 2) . str_repeat("\0", 196) . pack('V', self::SECTOR) . str_repeat("\0", 32) . self::longAd(self::SECTOR, 0, $meta ? 1 : 0);
            $logical = str_pad($logical, 264, "\0") . pack('V', $meta ? 70 : 6) . pack('V', $meta ? 2 : 1);
            $logical = str_pad($logical, 440, "\0") . chr($mapType) . chr(6) . pack('v', 1) . pack('v', 1);
            if ($meta) {
                $logical .= chr(2) . chr(64) . "\0\0" . "\0" . str_pad('*UDF Metadata Partition', 23, "\0") . str_repeat("\0", 8)
                    . pack('v', 1) . pack('v', 1) . pack('V', $this->metadataFile) . pack('V', 0xFFFFFFFF) . pack('V', 0xFFFFFFFF)
                    . pack('V', 1) . pack('v', 0) . str_repeat("\0", 6);
            }
            $this->image->setSector($base + 2, $logical);

            $this->image->setSector($base + 3, self::tag(8, $base + 3));
        }
    }

    /**
     * Write the file entry of a file or directory at the given (partition relative) block and its data
     *
     * @param array<array-key, mixed>|string $content
     */
    private function writeNode(int $block, array|string $content, int $parentBlock): void
    {
        $isDirectory = is_array($content);
        $data = $isDirectory ? $this->directoryData($content, $block, $parentBlock) : $content;
        $length = strlen($data);

        $inline = $this->options['inline'] > 0 && $length <= $this->options['inline'];
        $adType = $this->options['adType'];

        if ($inline) {
            $descriptors = $data;
            $flags = 3;
        } else {
            $flags = $adType;
            $descriptors = $this->extentDescriptors($data, $adType, $isDirectory);
        }

        $extended = $this->options['extended'];
        $entry = self::tag($extended ? 266 : 261, $block);
        $entry = str_pad($entry, 27, "\0") . chr($isDirectory ? 4 : 5);
        $entry = str_pad($entry, 34, "\0") . pack('v', $flags);
        $entry = str_pad($entry, 56, "\0") . pack('P', $length);
        $entry = str_pad($entry, $extended ? 92 : 84, "\0") . self::timestamp();
        $entry = str_pad($entry, $extended ? 208 : 168, "\0") . pack('V', 0) . pack('V', strlen($descriptors));
        $entry = str_pad($entry, $extended ? 216 : 176, "\0") . $descriptors;

        $this->writeBlock($block, $entry, $this->options['metadata']);
    }

    /**
     * Allocate blocks for the data and return the allocation descriptors describing them
     */
    private function extentDescriptors(string $data, int $adType, bool $isDirectory): string
    {
        // in the metadata layout the directories are metadata, the files stay in the physical partition
        $meta = $this->options['metadata'] && $isDirectory;
        $reference = $meta ? 1 : 0;
        /** @var list<array{int, int, int}> $extents */
        $extents = [];
        $sparse = $this->options['sparse'];
        $fragment = $this->options['fragment'];
        $position = 0;
        $length = strlen($data);

        while ($position < $length) {
            $size = $fragment ? self::SECTOR : $length - $position;
            $size = min($size, $length - $position);
            $chunk = substr($data, $position, $size);

            if ($sparse && $size === self::SECTOR && trim($chunk, "\0") === '') {
                $extents[] = [1, $size, 0];
            } else {
                $blocks = (int) ceil($size / self::SECTOR);
                $start = $this->allocate($blocks, $meta);
                foreach (str_split($chunk, self::SECTOR) as $i => $part) {
                    $this->writeBlock($start + $i, $part, $meta);
                }
                $extents[] = [0, $size, $start];
            }

            $position += $size;
        }

        $maxAds = $this->options['maxAds'];

        if (count($extents) <= $maxAds) {
            return self::encodeAll($extents, $adType, $reference);
        }

        // the descriptors that do not fit go to allocation extents (chained when needed)
        $head = array_slice($extents, 0, $maxAds - 1);
        $rest = array_slice($extents, $maxAds - 1);

        return self::encodeAll($head, $adType, $reference) . $this->continuation($rest, $maxAds, $adType, $reference);
    }

    /**
     * @param array<int, array{int, int, int}> $extents
     */
    private function continuation(array $extents, int $maxAds, int $adType, int $reference): string
    {
        $meta = $this->options['metadata'];
        $block = $this->allocate(1, $meta);

        if (count($extents) > $maxAds) {
            $area = self::encodeAll(array_slice($extents, 0, $maxAds - 1), $adType, $reference) . $this->continuation(array_slice($extents, $maxAds - 1), $maxAds, $adType, $reference);
        } else {
            $area = self::encodeAll($extents, $adType, $reference);
        }

        $this->writeBlock($block, self::tag(258, $block) . pack('V', 0) . pack('V', strlen($area)) . $area, $meta);

        return self::allocationDescriptor(3, self::SECTOR, $block, $adType, $meta ? 1 : 0);
    }

    /**
     * @param array<array-key, mixed> $children
     */
    private function directoryData(array $children, int $block, int $parentBlock): string
    {
        $reference = $this->options['metadata'] ? 1 : 0;
        $data = self::fileIdentifier('', $parentBlock, 0x0A, $reference);

        // a directory containing itself
        if ($this->options['loop']) {
            $data .= self::fileIdentifier('loop', $block, 0x02, $reference);
        }

        if ($block === 1 && $this->options['ghosts']) {
            $data .= self::fileIdentifier('ghost-partition', 5, 0x00, 7);
            $data .= self::fileIdentifier('ghost-block', 999999, 0x00, $reference);
        }

        foreach ($children as $name => $content) {
            if (! is_array($content) && ! is_string($content)) {
                continue;
            }

            $childBlock = $this->allocate(1, $this->options['metadata']);
            $this->writeNode($childBlock, $content, $block);
            $data .= self::fileIdentifier((string) $name, $childBlock, is_array($content) ? 0x02 : 0x00, $reference);
        }

        if ($block === 1 && $this->options['overrun']) {
            // claims a 200 bytes name, only a few bytes follow
            $data .= substr(self::tag(257, 0), 0, 16) . pack('v', 1) . chr(0) . chr(200) . self::longAd(self::SECTOR, 2, 0) . pack('v', 0) . 'abc';
        }

        return $data;
    }

    private static function fileIdentifier(string $name, int $block, int $characteristics, int $partition = 0): string
    {
        $encoded = '';
        if ($name !== '') {
            $encoded = mb_check_encoding($name, 'ASCII')
                ? chr(8) . $name
                : chr(16) . mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
        }

        $record = self::tag(257, 0) . pack('v', 1) . chr($characteristics) . chr(strlen($encoded)) . self::longAd(self::SECTOR, $block, $partition) . pack('v', 0) . $encoded;

        return str_pad($record, (int) (ceil(strlen($record) / 4) * 4), "\0");
    }

    /**
     * @param array<int, array{int, int, int}> $extents kind, length, block
     */
    private static function encodeAll(array $extents, int $adType, int $reference = 0): string
    {
        $encoded = '';
        foreach ($extents as [$kind, $length, $block]) {
            $encoded .= self::allocationDescriptor($kind, $length, $block, $adType, $reference);
        }

        return $encoded;
    }

    private static function allocationDescriptor(int $kind, int $length, int $block, int $adType, int $reference = 0): string
    {
        $raw = pack('V', $length | ($kind << 30)) . pack('V', $block);

        return $adType === 1 ? $raw . pack('v', $reference) . str_repeat("\0", 6) : $raw;
    }

    private static function longAd(int $length, int $block, int $partition): string
    {
        return pack('V', $length) . pack('V', $block) . pack('v', $partition) . str_repeat("\0", 6);
    }

    public static function tag(int $id, int $location): string
    {
        $raw = pack('vvCCvvvV', $id, 3, 0, 0, 1, 0, 0, $location);
        $sum = 0;
        foreach ([0, 1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] as $index) {
            $sum += ord($raw[$index]);
        }
        $raw[4] = chr($sum & 0xFF);

        return $raw;
    }

    private static function timestamp(): string
    {
        // type 1 (local time), +60 minutes, 2024-05-06 07:08:09
        return pack('v', (1 << 12) | 60) . pack('v', 2024) . chr(5) . chr(6) . chr(7) . chr(8) . chr(9) . str_repeat("\0", 3);
    }

    private function allocate(int $blocks, bool $meta = false): int
    {
        if ($meta) {
            $start = ++$this->metaNext;
            $this->metaNext += max($blocks, 1) - 1;

            return $start;
        }

        $start = ++$this->next;
        $this->next += max($blocks, 1) - 1;

        return $start;
    }

    private function writeBlock(int $block, string $data, bool $meta = false): void
    {
        if ($meta) {
            $this->metaBlocks[$block] = $data;

            return;
        }

        $this->image->setSector(self::PARTITION_START + $block, $data);
    }
}
