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
 *  - loop: add an entry pointing back to its own directory to every directory
 */
final class UdfBuilder
{
    private const int SECTOR = 2048;
    private const int PARTITION_START = 260;

    private int $next = 1;

    private readonly IsoBuilder $image;

    /**
     * @var array{adType: int, extended: bool, inline: int, fragment: bool, maxAds: int, sparse: bool, mapType: int, loop: bool}
     */
    private readonly array $options;

    /**
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool, mapType?: int, loop?: bool} $options
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
        ];
        $this->image = new IsoBuilder();
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool, mapType?: int, loop?: bool} $options
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
        $this->writeBlock(0, self::tag(256, 0) . str_repeat("\0", 384) . self::longAd(self::SECTOR, 1, 0));
        $this->writeNode(1, $tree, 1);

        $this->writeAnchorAndVolumeSequence();
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
            $logical = self::tag(6, $base + 2) . str_repeat("\0", 196) . pack('V', self::SECTOR) . str_repeat("\0", 32) . self::longAd(self::SECTOR, 0, 0);
            $logical = str_pad($logical, 264, "\0") . pack('V', 6) . pack('V', 1);
            $logical = str_pad($logical, 440, "\0") . chr($mapType) . chr(6) . pack('v', 1) . pack('v', 1);
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
            $descriptors = $this->extentDescriptors($data, $adType);
        }

        $extended = $this->options['extended'];
        $entry = self::tag($extended ? 266 : 261, self::PARTITION_START + $block);
        $entry = str_pad($entry, 27, "\0") . chr($isDirectory ? 4 : 5);
        $entry = str_pad($entry, 34, "\0") . pack('v', $flags);
        $entry = str_pad($entry, 56, "\0") . pack('P', $length);
        $entry = str_pad($entry, $extended ? 92 : 84, "\0") . self::timestamp();
        $entry = str_pad($entry, $extended ? 208 : 168, "\0") . pack('V', 0) . pack('V', strlen($descriptors));
        $entry = str_pad($entry, $extended ? 216 : 176, "\0") . $descriptors;

        $this->writeBlock($block, $entry);
    }

    /**
     * Allocate blocks for the data and return the allocation descriptors describing them
     */
    private function extentDescriptors(string $data, int $adType): string
    {
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
                $start = $this->allocate($blocks);
                foreach (str_split($chunk, self::SECTOR) as $i => $part) {
                    $this->writeBlock($start + $i, $part);
                }
                $extents[] = [0, $size, $start];
            }

            $position += $size;
        }

        $maxAds = $this->options['maxAds'];

        if (count($extents) <= $maxAds) {
            return self::encodeAll($extents, $adType);
        }

        // the descriptors that do not fit go to allocation extents (chained when needed)
        $head = array_slice($extents, 0, $maxAds - 1);
        $rest = array_slice($extents, $maxAds - 1);

        return self::encodeAll($head, $adType) . $this->continuation($rest, $maxAds, $adType);
    }

    /**
     * @param array<int, array{int, int, int}> $extents
     */
    private function continuation(array $extents, int $maxAds, int $adType): string
    {
        $block = $this->allocate(1);

        if (count($extents) > $maxAds) {
            $area = self::encodeAll(array_slice($extents, 0, $maxAds - 1), $adType) . $this->continuation(array_slice($extents, $maxAds - 1), $maxAds, $adType);
        } else {
            $area = self::encodeAll($extents, $adType);
        }

        $this->writeBlock($block, self::tag(258, self::PARTITION_START + $block) . pack('V', 0) . pack('V', strlen($area)) . $area);

        return self::allocationDescriptor(3, self::SECTOR, $block, $adType);
    }

    /**
     * @param array<array-key, mixed> $children
     */
    private function directoryData(array $children, int $block, int $parentBlock): string
    {
        $data = self::fileIdentifier('', $parentBlock, 0x0A);

        // a directory containing itself
        if ($this->options['loop']) {
            $data .= self::fileIdentifier('loop', $block, 0x02);
        }

        foreach ($children as $name => $content) {
            if (! is_array($content) && ! is_string($content)) {
                continue;
            }

            $childBlock = $this->allocate(1);
            $this->writeNode($childBlock, $content, $block);
            $data .= self::fileIdentifier((string) $name, $childBlock, is_array($content) ? 0x02 : 0x00);
        }

        return $data;
    }

    private static function fileIdentifier(string $name, int $block, int $characteristics): string
    {
        $encoded = '';
        if ($name !== '') {
            $encoded = mb_check_encoding($name, 'ASCII')
                ? chr(8) . $name
                : chr(16) . mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
        }

        $record = self::tag(257, 0) . pack('v', 1) . chr($characteristics) . chr(strlen($encoded)) . self::longAd(self::SECTOR, $block, 0) . pack('v', 0) . $encoded;

        return str_pad($record, (int) (ceil(strlen($record) / 4) * 4), "\0");
    }

    /**
     * @param array<int, array{int, int, int}> $extents kind, length, block
     */
    private static function encodeAll(array $extents, int $adType): string
    {
        $encoded = '';
        foreach ($extents as [$kind, $length, $block]) {
            $encoded .= self::allocationDescriptor($kind, $length, $block, $adType);
        }

        return $encoded;
    }
    private static function allocationDescriptor(int $kind, int $length, int $block, int $adType): string
    {
        $raw = pack('V', $length | ($kind << 30)) . pack('V', $block);

        return $adType === 1 ? $raw . pack('v', 0) . str_repeat("\0", 6) : $raw;
    }

    private static function longAd(int $length, int $block, int $partition): string
    {
        return pack('V', $length) . pack('V', $block) . pack('v', $partition) . str_repeat("\0", 6);
    }

    private static function tag(int $id, int $location): string
    {
        return pack('vvCCvvvV', $id, 3, 0, 0, 1, 0, 0, $location);
    }

    private static function timestamp(): string
    {
        // type 1 (local time), +60 minutes, 2024-05-06 07:08:09
        return pack('v', (1 << 12) | 60) . pack('v', 2024) . chr(5) . chr(6) . chr(7) . chr(8) . chr(9) . str_repeat("\0", 3);
    }

    private function allocate(int $blocks): int
    {
        $start = ++$this->next;
        $this->next += max($blocks, 1) - 1;

        return $start;
    }

    private function writeBlock(int $block, string $data): void
    {
        $this->image->setSector(self::PARTITION_START + $block, $data);
    }
}
