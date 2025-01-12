<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;

class Terminator extends Descriptor
{
    protected int $type = Type::TERMINATOR_DESC;
    protected string $name = 'Terminator descriptor';

    public function init(IsoFile $isoFile, int &$offset): void
    {
        // free some space...
        unset($this->bytes);
    }
}
