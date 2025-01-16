<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class UdfNsr2Descriptor extends UdfDescriptor
{
    public string $name = 'UDF NSR02 descriptor';
    protected int $type = Type::UDF_NSR2_VOLUME_DESC;
}
