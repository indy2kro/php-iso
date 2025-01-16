<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class UdfTeaDescriptor extends UdfDescriptor
{
    public string $name = 'UDF TEA descriptor';
    protected int $type = Type::UDF_TEA_VOLUME_DESC;
}
