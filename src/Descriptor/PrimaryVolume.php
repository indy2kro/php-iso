<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class PrimaryVolume extends Volume
{
    protected const string NAME = 'Primary volume descriptor';
    protected const int TYPE = Type::PRIMARY_VOLUME_DESC;
}
