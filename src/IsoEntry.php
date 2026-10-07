<?php

declare(strict_types=1);

namespace PhpIso;

use Carbon\CarbonImmutable;

/**
 * A file or directory found while walking the directory tree of a volume
 */
final readonly class IsoEntry
{
    public function __construct(
        public string $path,
        public string $name,
        public bool $isDirectory,
        public int $size,
        public int $location,
        public ?CarbonImmutable $recordingDate,
        public bool $isHidden,
        /**
         * Extents (location in blocks, size in bytes) of a multi-extent file, empty for a regular entry
         *
         * @var list<array{int, int}>
         */
        public array $extents = [],
        /**
         * Rock Ridge attributes (POSIX mode, owner, symbolic link target), null when the volume has none
         */
        public ?RockRidgeInfo $rockRidge = null,
    ) {
    }

    public function isSymlink(): bool
    {
        return $this->rockRidge instanceof RockRidgeInfo && $this->rockRidge->isSymlink();
    }

    /**
     * The (location, size) pairs holding the data of the file, in order
     *
     * @return list<array{int, int}>
     */
    public function getExtents(): array
    {
        return $this->extents === [] ? [[$this->location, $this->size]] : $this->extents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'type' => $this->isDirectory ? 'directory' : 'file',
            'size' => $this->size,
            'location' => $this->location,
            'date' => $this->recordingDate?->toIso8601String(),
            'hidden' => $this->isHidden,
            'extents' => count($this->getExtents()),
            'mode' => $this->rockRidge?->mode,
            'uid' => $this->rockRidge?->uid,
            'gid' => $this->rockRidge?->gid,
            'symlink' => $this->rockRidge?->symlink,
        ];
    }
}
