<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

class Type
{
    public const BOOT_RECORD_DESC = 0;
    public const PRIMARY_VOLUME_DESC = 1;
    public const SUPPLEMENTARY_VOLUME_DESC = 2;
    public const PARTITION_VOLUME_DESC = 3;
    public const UDF_BEA_VOLUME_DESC = 100;
    public const UDF_NSR2_VOLUME_DESC = 101;
    public const UDF_NSR3_VOLUME_DESC = 102;
    public const UDF_TEA_VOLUME_DESC = 103;
    public const TERMINATOR_DESC = 255;
}
