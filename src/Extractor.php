<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Descriptor\Volume;
use PhpIso\Util\SafePath;

/**
 * Extracts the content of a volume to a directory on disk
 */
class Extractor
{
    /**
     * @param callable(IsoEntry): void|null $onFile called before each file is extracted
     *
     * @return int number of files extracted
     *
     * @throws Exception
     */
    public function extract(IsoFile $isoFile, FileSystem $volume, string $destinationDir, ?callable $onFile = null): int
    {
        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0777, true) && ! is_dir($destinationDir)) {
            throw new Exception('Failed to create extract output directory: ' . $destinationDir);
        }

        $count = 0;

        foreach ($volume->walk($isoFile) as $entry) {
            $target = SafePath::join($destinationDir, $entry->path);

            if ($entry->isDirectory) {
                $this->ensureDirectory($target);
                continue;
            }

            // symbolic links are never materialised: they could point outside of the destination
            if ($entry->isSymlink()) {
                continue;
            }

            $this->ensureDirectory(dirname($target));

            if ($onFile !== null) {
                $onFile($entry);
            }

            $handle = fopen($target, 'wb');
            if ($handle === false) {
                throw new Exception('Failed to open file for writing: ' . $target);
            }

            try {
                $volume->copyEntryTo($isoFile, $entry, $handle);
            } finally {
                fclose($handle);
            }

            $count++;
        }

        return $count;
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new Exception('Failed to create directory: ' . $dir);
        }
    }
}
