<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

/**
 * An El Torito boot catalog entry (initial/default entry or a section entry)
 */
final readonly class BootEntry
{
    public const int MEDIA_NO_EMULATION = 0;
    public const int MEDIA_FLOPPY_1_2 = 1;
    public const int MEDIA_FLOPPY_1_44 = 2;
    public const int MEDIA_FLOPPY_2_88 = 3;
    public const int MEDIA_HARD_DISK = 4;

    public function __construct(
        public bool $bootable,
        public int $mediaType,
        public int $loadSegment,
        public int $systemType,
        public int $sectorCount,
        public int $loadRba,
        public int $platformId,
    ) {
    }

    /**
     * The media emulation type (one of the MEDIA_* constants)
     */
    public function getEmulationType(): int
    {
        return $this->mediaType;
    }

    /**
     * Size in bytes of the boot image: the emulated floppy size, or the sector count in 512 bytes virtual sectors
     */
    public function getImageSize(): int
    {
        return match ($this->mediaType) {
            self::MEDIA_FLOPPY_1_2 => 1228800,
            self::MEDIA_FLOPPY_1_44 => 1474560,
            self::MEDIA_FLOPPY_2_88 => 2949120,
            default => $this->sectorCount * 512,
        };
    }

    public function getMediaName(): string
    {
        return match ($this->mediaType) {
            self::MEDIA_NO_EMULATION => 'No emulation',
            self::MEDIA_FLOPPY_1_2 => '1.2 MB floppy',
            self::MEDIA_FLOPPY_1_44 => '1.44 MB floppy',
            self::MEDIA_FLOPPY_2_88 => '2.88 MB floppy',
            self::MEDIA_HARD_DISK => 'Hard disk',
            default => 'Unknown (' . $this->mediaType . ')',
        };
    }

    public function getPlatformName(): string
    {
        return match ($this->platformId) {
            0 => 'x86',
            1 => 'PowerPC',
            2 => 'Mac',
            0xEF => 'EFI',
            default => 'Unknown (' . $this->platformId . ')',
        };
    }
}
