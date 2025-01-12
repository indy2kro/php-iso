<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class PrimaryVolume extends Volume
{
    protected int $type = Type::PRIMARY_VOLUME_DESC;
    protected string $name = 'Primary volume descriptor';
}
