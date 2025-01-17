<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;

abstract class UdfDescriptor extends Descriptor
{
    public function init(IsoFile $isoFile, int &$offset): void
    {
        // TODO - add UDF processing

        // free some space...
        unset($this->bytes);
    }
}
