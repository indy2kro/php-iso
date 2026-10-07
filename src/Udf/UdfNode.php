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
        /** File entry type (ECMA-167 4/14.6.6): 4 directory, 5 regular file, 12 symbolic link, ... */
        public int $fileType = 5,
        public ?int $uid = null,
        public ?int $gid = null,
        /** POSIX mode (file type and permission bits) built from the UDF permissions */
        public ?int $mode = null,
    ) {
    }

    public function isSymlink(): bool
    {
        return $this->fileType === 12;
    }

    /**
     * Block and character devices, FIFOs, sockets and terminals: they have no file data to read
     */
    public function isSpecial(): bool
    {
        return in_array($this->fileType, [6, 7, 9, 10, 11], true);
    }
}
