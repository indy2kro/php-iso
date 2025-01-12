<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class SupplementaryVolume extends Volume
{
    protected int $type = Type::SUPPLEMENTARY_VOLUME_DESC;
    protected string $name = 'Supplementary volume descriptor';
}
