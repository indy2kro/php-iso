<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;
use PhpIso\Util\Buffer;

class Boot extends Descriptor
{
    /**
     * Specify an identification of a system which can recognize and act upon the content of the Boot Identifier and Boot System Use fields in the Boot Record
     */
    public readonly string $bootSysId;

    public readonly int $bootCatalogLocation;

    /**
     * An identification of the boot system specified in the Boot System Use field of the Boot Record.
     */
    public readonly string $bootId;

    protected const string NAME = 'Boot volume descriptor';

    protected const int TYPE = Type::BOOT_RECORD_DESC;

    public const EL_TORITO_ID = 'EL TORITO SPECIFICATION';

    /**
     * Tell if this boot record points to an El Torito boot catalog
     */
    public function isElTorito(): bool
    {
        return $this->bootSysId === self::EL_TORITO_ID;
    }

    /**
     * Load the El Torito boot catalog, null when this is not an El Torito boot record
     *
     * @throws \PhpIso\Exception when the catalog is invalid
     */
    public function loadCatalog(IsoFile $isoFile): ?BootCatalog
    {
        if (! $this->isElTorito()) {
            return null;
        }

        return BootCatalog::load($isoFile, $this->bootCatalogLocation);
    }

    /**
     * @param array<int, int> $bytes the descriptor sector
     * @param int $offset position after the descriptor header, moved after the parsed fields
     */
    public function __construct(string $stdId, int $version, array $bytes, int &$offset)
    {
        parent::__construct($stdId, $version);

        $this->bootSysId = Buffer::getString($bytes, 32, $offset);
        $this->bootId = Buffer::getString($bytes, 32, $offset);
        $this->bootCatalogLocation = Buffer::readLSB($bytes, 4, $offset);
    }
}
