<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\IsoFile;

class UdfDescriptor extends Volume
{
    public string $name = 'UDF descriptor';
    protected int $type = Type::UDF_VOLUME_DESC;

    public function __construct(public string $stdId = '', public int $version = 0, protected ?array $bytes = null, public string $udfType = '')
    {
    }

    public function init(IsoFile $isoFile, int &$offset): void
    {
        // TODO - add UDF processing

        // free some space...
        unset($this->bytes);
    }
}
