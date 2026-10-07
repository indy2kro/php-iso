<?php

declare(strict_types=1);

namespace PhpIso\Udf;

/**
 * A UDF partition: the translation of logical blocks (2048 bytes) of the partition to byte offsets in the image
 *
 * A physical partition is one run of the image. A metadata partition (UDF 2.50) is the content of the metadata
 * file stored in a physical partition, so its blocks follow the allocation extents of that file.
 */
final readonly class UdfPartition
{
    private const int SECTOR = 2048;

    /**
     * @param int $blocks length of the partition in blocks
     * @param list<array{int, int}> $runs consecutive (absolute byte offset, length in bytes) pairs holding the blocks,
     *                                    a negative offset is a sparse run
     * @param self|null $dataPartition partition holding the data of files whose allocation descriptors are short
     *                                 (the physical partition under a metadata partition), null for itself
     */
    public function __construct(
        public int $blocks,
        private array $runs,
        private ?self $dataPartition = null,
    ) {
    }

    public static function physical(int $start, int $blocks): self
    {
        return new self($blocks, [[$start * self::SECTOR, $blocks * self::SECTOR]]);
    }

    /**
     * @param list<array{int, int}> $extents the (offset, length) extents of the metadata file
     */
    public static function metadata(array $extents, self $physical): self
    {
        $total = 0;
        foreach ($extents as [, $length]) {
            $total += $length;
        }

        return new self(intdiv($total + self::SECTOR - 1, self::SECTOR), $extents, $physical);
    }

    public function dataPartition(): self
    {
        return $this->dataPartition ?? $this;
    }

    /**
     * Absolute byte offset of a block, null when the block is outside of the partition or sparse
     */
    public function offset(int $block): ?int
    {
        $ranges = $this->ranges($block, 1);
        if ($ranges === null || $ranges[0][0] < 0) {
            return null;
        }

        return $ranges[0][0];
    }

    /**
     * Translate a range of the partition (first block, length in bytes) to (absolute byte offset, length) pairs
     *
     * @return list<array{int, int}>|null null when the range is not entirely inside of the partition
     */
    public function ranges(int $block, int $length): ?array
    {
        if ($block < 0 || $length <= 0) {
            return null;
        }

        $position = $block * self::SECTOR;
        $end = $position + $length;
        if ($end > $this->blocks * self::SECTOR) {
            return null;
        }

        $ranges = [];
        $covered = 0;
        $base = 0;
        foreach ($this->runs as [$offset, $size]) {
            $runEnd = $base + $size;
            if ($position < $runEnd && $end > $base) {
                $from = max($position, $base);
                $to = min($end, $runEnd);
                $ranges[] = [$offset < 0 ? -1 : $offset + $from - $base, $to - $from];
                $covered += $to - $from;
            }

            $base = $runEnd;
            if ($base >= $end) {
                break;
            }
        }

        return $covered === $length ? $ranges : null;
    }
}
