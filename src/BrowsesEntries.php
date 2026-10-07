<?php

declare(strict_types=1);

namespace PhpIso;

use Generator;
use PhpIso\Util\EntryStream;

/**
 * Lookup and read helpers shared by the file systems, built on top of walk() and copyEntryTo()
 */
trait BrowsesEntries
{
    /**
     * Find an entry by its path (exact match first, then case insensitive: ISO 9660 names are usually upper case)
     */
    public function find(IsoFile $isoFile, string $path, ?WalkWarnings $warnings = null): ?IsoEntry
    {
        $current = null;

        // descend one component at a time: only the directories on the path are read
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return null;
        }

        foreach ($parts as $part) {
            if ($current instanceof IsoEntry && ! $current->isDirectory) {
                return null;
            }

            $exact = null;
            $insensitive = null;
            foreach ($this->listDirectory($isoFile, $current, $warnings) as $child) {
                if ($child->name === $part) {
                    $exact = $child;
                    break;
                }

                if ($insensitive === null && strcasecmp($child->name, $part) === 0) {
                    $insensitive = $child;
                }
            }

            $current = $exact ?? $insensitive;
            if (! $current instanceof IsoEntry) {
                return null;
            }
        }

        return $current;
    }

    /**
     * Entries whose name matches a shell style pattern (case insensitive), e.g. "*.txt"
     *
     * A pattern containing "/" is matched against the whole path instead ("docs/*.txt", leading "/" optional)
     * and "*" does not cross directories.
     *
     * @return Generator<int, IsoEntry>
     */
    public function search(IsoFile $isoFile, string $pattern, ?WalkWarnings $warnings = null): Generator
    {
        $byPath = str_contains($pattern, '/');
        $pattern = $byPath ? '/' . ltrim($pattern, '/') : $pattern;

        foreach ($this->walk($isoFile, 64, $warnings) as $entry) {
            $matches = $byPath
                ? fnmatch($pattern, '/' . ltrim($entry->path, '/'), FNM_CASEFOLD | FNM_PATHNAME)
                : fnmatch($pattern, $entry->name, FNM_CASEFOLD);

            if ($matches) {
                yield $entry;
            }
        }
    }

    /**
     * Open the content of a file entry as a readable, seekable stream, read from the image on demand
     *
     * @return resource
     *
     * @throws Exception
     */
    public function openStream(IsoFile $isoFile, IsoEntry $entry): mixed
    {
        if ($entry->isDirectory) {
            throw new Exception('Cannot read the content of a directory: ' . $entry->path);
        }

        return EntryStream::open($isoFile, $this->getEntryRanges($isoFile, $entry));
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
