<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class SupplementaryVolume extends Volume
{
    public string $name = 'Supplementary volume descriptor';
    protected int $type = Type::SUPPLEMENTARY_VOLUME_DESC;
}
