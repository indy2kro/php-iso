<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class UdfBeaDescriptor extends UdfDescriptor
{
    public string $name = 'UDF BEA01 descriptor';
    protected int $type = Type::UDF_BEA_VOLUME_DESC;
}
