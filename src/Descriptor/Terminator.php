<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;

class Terminator extends Descriptor
{
    protected const string NAME = 'Terminator descriptor';
    protected const int TYPE = Type::TERMINATOR_DESC;
}
