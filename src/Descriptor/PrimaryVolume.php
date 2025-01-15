<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class PrimaryVolume extends Volume
{
    public string $name = 'Primary volume descriptor';
    protected int $type = Type::PRIMARY_VOLUME_DESC;
}
