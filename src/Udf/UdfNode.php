<?php

declare(strict_types=1);

namespace PhpIso\Udf;

use Carbon\Carbon;

/**
 * The parts of a UDF (extended) file entry needed to browse and read a file or directory
 */
final readonly class UdfNode
{
    /**
     * @param list<array{int, int}> $extents (absolute byte offset, length) pairs, a negative offset marks a sparse extent
     */
    public function __construct(
        public bool $isDirectory,
        public int $size,
        public array $extents,
        public ?Carbon $modified,
    ) {
    }
}
