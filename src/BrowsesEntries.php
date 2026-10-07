<?php

declare(strict_types=1);

namespace PhpIso;

use Generator;

/**
 * Lookup and read helpers shared by the file systems, built on top of walk() and copyEntryTo()
 */
trait BrowsesEntries
{
    /**
     * Find an entry by its path (exact match first, then case insensitive: ISO 9660 names are usually upper case)
     */
    public function find(IsoFile $isoFile, string $path): ?IsoEntry
    {
        $wanted = '/' . trim(str_replace('\\', '/', $path), '/');
        $insensitive = null;

        foreach ($this->walk($isoFile) as $entry) {
            if ($entry->path === $wanted) {
                return $entry;
            }

            if ($insensitive === null && strcasecmp($entry->path, $wanted) === 0) {
                $insensitive = $entry;
            }
        }

        return $insensitive;
    }

    /**
     * Entries whose name matches a shell style pattern (case insensitive), e.g. "*.txt"
     *
     * A pattern containing "/" is matched against the whole path instead ("docs/*.txt", leading "/" optional)
     * and "*" does not cross directories.
     *
     * @return Generator<int, IsoEntry>
     */
    public function search(IsoFile $isoFile, string $pattern): Generator
    {
        $byPath = str_contains($pattern, '/');
        $pattern = $byPath ? '/' . ltrim($pattern, '/') : $pattern;

        foreach ($this->walk($isoFile) as $entry) {
            $matches = $byPath
                ? fnmatch($pattern, '/' . ltrim($entry->path, '/'), FNM_CASEFOLD | FNM_PATHNAME)
                : fnmatch($pattern, $entry->name, FNM_CASEFOLD);

            if ($matches) {
                yield $entry;
            }
        }
    }

    /**
     * Open the content of a file entry as a readable stream (memory first, temporary file for big files)
     *
     * @return resource
     *
     * @throws Exception
     */
    public function openStream(IsoFile $isoFile, IsoEntry $entry): mixed
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new Exception('Failed to open a temporary stream');
        }

        $this->copyEntryTo($isoFile, $entry, $stream);
        rewind($stream);

        return $stream;
    }

    /**
     * Read the whole content of a file entry
     *
     * @throws Exception when the file is bigger than $maxSize
     */
    public function readFile(IsoFile $isoFile, IsoEntry $entry, int $maxSize = IsoFile::MAX_READ_LENGTH): string
    {
        if ($entry->size > $maxSize) {
            throw new Exception('File is too big to be read in memory: ' . $entry->path);
        }

        $stream = $this->openStream($isoFile, $entry);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content === false ? '' : $content;
    }
}
