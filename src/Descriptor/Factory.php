<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;

class Factory
{
    /**
     * @param array<int, int> $bytes
     */
    public static function create(int $type, string $stdId = '', int $version = 0, ?array $bytes = null): Descriptor
    {
        return match ($type) {
            Type::BOOT_RECORD_DESC => new Boot($stdId, $version, $bytes),
            Type::PRIMARY_VOLUME_DESC => new PrimaryVolume($stdId, $version, $bytes),
            Type::SUPPLEMENTARY_VOLUME_DESC => new SupplementaryVolume($stdId, $version, $bytes),
            Type::PARTITION_VOLUME_DESC => new Partition($stdId, $version, $bytes),
            Type::TERMINATOR_DESC => new Terminator($stdId, $version, $bytes),
            Type::UDF_BEA_VOLUME_DESC => new UdfBeaDescriptor($stdId, $version, $bytes),
            Type::UDF_NSR2_VOLUME_DESC => new UdfNsr2Descriptor($stdId, $version, $bytes),
            Type::UDF_NSR3_VOLUME_DESC => new UdfNsr3Descriptor($stdId, $version, $bytes),
            Type::UDF_TEA_VOLUME_DESC => new UdfTeaDescriptor($stdId, $version, $bytes),
            default => throw new Exception('Invalid descriptor type received: ' . $type),
        };
    }
}
