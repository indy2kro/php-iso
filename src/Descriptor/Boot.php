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

    public function init(IsoFile $isoFile, int &$offset): void
    {
        if ($this->bytes === null) {
            return;
        }

        $this->bootSysId = Buffer::getString($this->bytes, 32, $offset);
        $this->bootId = Buffer::getString($this->bytes, 32, $offset);
        $this->bootCatalogLocation = Buffer::readLSB($this->bytes, 4, $offset);

        // free some space...
        unset($this->bytes);
    }
}
