<?php

declare(strict_types=1);

namespace PhpIso;

use Generator;

/**
 * A browsable file system found in an ISO image (an ISO 9660 volume or a UDF file system)
 */
interface FileSystem
{
    /**
     * Walk the whole directory tree (depth first)
     *
     * Entries are untrusted: names are not sanitized here, see Util\SafePath before using them on disk.
     *
     * @return Generator<int, IsoEntry>
     */
    public function walk(IsoFile $isoFile, int $maxDepth = 64): Generator;

    /**
     * Find an entry by its path (exact match first, then case insensitive)
     */
    public function find(IsoFile $isoFile, string $path): ?IsoEntry;

    /**
     * Entries whose name matches a shell style pattern (case insensitive), e.g. "*.txt"
     *
     * @return Generator<int, IsoEntry>
     */
    public function search(IsoFile $isoFile, string $pattern): Generator;

    /**
     * Copy the content of a file entry to an open stream
     *
     * @param resource $output
     *
     * @throws Exception
     */
    public function copyEntryTo(IsoFile $isoFile, IsoEntry $entry, mixed $output): void;

    /**
     * Open the content of a file entry as a readable stream
     *
     * @return resource
     *
     * @throws Exception
     */
    public function openStream(IsoFile $isoFile, IsoEntry $entry): mixed;

    /**
     * Read the whole content of a file entry
     *
     * @throws Exception when the file is bigger than $maxSize
     */
    public function readFile(IsoFile $isoFile, IsoEntry $entry, int $maxSize = IsoFile::MAX_READ_LENGTH): string;
}
