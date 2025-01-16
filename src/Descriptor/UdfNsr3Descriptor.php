<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class UdfNsr3Descriptor extends UdfDescriptor
{
    public string $name = 'UDF NSR03 descriptor';
    protected int $type = Type::UDF_NSR3_VOLUME_DESC;
}
