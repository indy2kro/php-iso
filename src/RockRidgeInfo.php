<?php

declare(strict_types=1);

namespace PhpIso;

/**
 * Rock Ridge (IEEE P1282) attributes of a directory record
 */
final readonly class RockRidgeInfo
{
    public const int TYPE_MASK = 0170000;
    public const int TYPE_DIRECTORY = 0040000;
    public const int TYPE_FILE = 0100000;
    public const int TYPE_SYMLINK = 0120000;

    public function __construct(
        /** Alternate (POSIX) name, null when the record has none */
        public ?string $name = null,
        /** st_mode: file type and permissions */
        public ?int $mode = null,
        public ?int $links = null,
        public ?int $uid = null,
        public ?int $gid = null,
        /** Target of a symbolic link */
        public ?string $symlink = null,
        /** The record is the relocated copy of a deep directory (listed under its CL placeholder instead) */
        public bool $relocated = false,
        /** Location of the real directory when this record is a "child link" placeholder */
        public ?int $childLocation = null,
    ) {
    }

    public function isSymlink(): bool
    {
        return $this->symlink !== null || ($this->mode !== null && ($this->mode & self::TYPE_MASK) === self::TYPE_SYMLINK);
    }

    /**
     * The permission bits only (e.g. 0644)
     */
    public function getPermissions(): ?int
    {
        return $this->mode === null ? null : $this->mode & 07777;
    }
}
