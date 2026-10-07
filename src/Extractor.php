<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Util\SafePath;

/**
 * Extracts the content of a volume to a directory on disk
 */
class Extractor
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param bool $preserveMode apply the Rock Ridge permissions (when present) to the extracted files
     * @param bool $continueOnError record unsafe names and failed writes (see getErrors()) instead of aborting
     */
    public function __construct(
        private readonly bool $preserveMode = false,
        private readonly bool $continueOnError = false,
    ) {
    }

    /**
     * Problems met by the last extract() call when $continueOnError is set (entry path => message)
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param callable(IsoEntry): void|null $onFile called before each file is extracted
     * @param WalkWarnings|null $warnings receives what was skipped because the listing is incomplete
     *
     * @return int number of files extracted
     *
     * @throws Exception
     */
    public function extract(IsoFile $isoFile, FileSystem $volume, string $destinationDir, ?callable $onFile = null, ?WalkWarnings $warnings = null): int
    {
        $this->errors = [];

        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0777, true) && ! is_dir($destinationDir)) {
            throw new Exception('Failed to create extract output directory: ' . $destinationDir);
        }

        $count = 0;
        /** @var list<array{string, IsoEntry}> $directories */
        $directories = [];

        foreach ($volume->walk($isoFile, 64, $warnings) as $entry) {
            try {
                $target = SafePath::join($destinationDir, $entry->path);

                if ($entry->isDirectory) {
                    $this->ensureDirectory($target);
                    $directories[] = [$target, $entry];
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

                $this->writeFile($isoFile, $volume, $entry, $target);
                $count++;
            } catch (Exception $exception) {
                if (! $this->continueOnError) {
                    throw $exception;
                }

                $this->errors[$entry->path] = $exception->getMessage();
            }
        }

        // directory times are set last: writing the files inside would update them
        foreach (array_reverse($directories) as [$directory, $entry]) {
            $this->applyAttributes($directory, $entry);
        }

        return $count;
    }

    /**
     * @throws Exception
     */
    protected function writeFile(IsoFile $isoFile, FileSystem $volume, IsoEntry $entry, string $target): void
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new Exception('Failed to open file for writing: ' . $target);
        }

        try {
            $volume->copyEntryTo($isoFile, $entry, $handle);
        } catch (\Throwable $throwable) {
            fclose($handle);
            @unlink($target);

            throw $throwable;
        }

        fclose($handle);
        $this->applyAttributes($target, $entry);
    }

    protected function applyAttributes(string $target, IsoEntry $entry): void
    {
        if ($this->preserveMode && $entry->rockRidge?->mode !== null) {
            @chmod($target, $entry->rockRidge->mode & 0777);
        }

        if ($entry->recordingDate !== null) {
            @touch($target, $entry->recordingDate->getTimestamp());
        }
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new Exception('Failed to create directory: ' . $dir);
        }
    }
}
