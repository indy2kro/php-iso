<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class SupplementaryVolume extends Volume
{
    protected const string NAME = 'Supplementary volume descriptor';
    protected const int TYPE = Type::SUPPLEMENTARY_VOLUME_DESC;
}
