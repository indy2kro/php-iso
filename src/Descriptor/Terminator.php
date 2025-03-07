<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;

class Terminator extends Descriptor
{
    public string $name = 'Terminator descriptor';
    protected int $type = Type::TERMINATOR_DESC;

    public function init(IsoFile $isoFile, int &$offset): void
    {
        // free some space...
        $this->bytes = null;
    }
}
