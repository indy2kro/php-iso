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
    public string $bootSysId = '';

    public int $bootCatalogLocation;

    /**
     * An identification of the boot system specified in the Boot System Use field of the Boot Record.
     */
    public string $bootId = '';

    public string $name = 'Boot volume descriptor';

    protected int $type = Type::BOOT_RECORD_DESC;

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

    public function init(IsoFile $isoFile, int &$offset): void
    {
        if ($this->bytes === null) {
            return;
        }

        $this->bootSysId = Buffer::getString($this->bytes, 32, $offset);
        $this->bootId = Buffer::getString($this->bytes, 32, $offset);
        $this->bootCatalogLocation = Buffer::readLSB($this->bytes, 4, $offset);

        // free some space...
        $this->bytes = null;
    }
}
