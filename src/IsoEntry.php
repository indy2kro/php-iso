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
        /**
         * Target of a symbolic link (UDF symlinks; Rock Ridge ones are in $rockRidge)
         */
        public ?string $symlinkTarget = null,
        /**
         * Owner user id (Rock Ridge PX, UDF file entry), null when unknown
         */
        public ?int $uid = null,
        /**
         * Owner group id (Rock Ridge PX, UDF file entry), null when unknown
         */
        public ?int $gid = null,
        /**
         * POSIX mode (permissions, plus the file type bits when known), null when unknown
         */
        public ?int $mode = null,
    ) {
    }

    public function isSymlink(): bool
    {
        return $this->symlinkTarget !== null || ($this->rockRidge instanceof RockRidgeInfo && $this->rockRidge->isSymlink());
    }

    /**
     * Target of the symbolic link, null when the entry is not a link
     */
    public function getSymlinkTarget(): ?string
    {
        return $this->symlinkTarget ?? $this->rockRidge?->symlink;
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
            'mode' => $this->mode ?? $this->rockRidge?->mode,
            'uid' => $this->uid ?? $this->rockRidge?->uid,
            'gid' => $this->gid ?? $this->rockRidge?->gid,
            'symlink' => $this->getSymlinkTarget(),
        ];
    }
}
