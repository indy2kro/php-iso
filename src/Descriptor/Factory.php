<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;

class Factory
{
    /**
     * @param array<int, int> $bytes the descriptor sector
     * @param int $offset position after the descriptor header, moved after the parsed fields
     */
    public static function create(int $type, string $stdId, int $version, array $bytes, int &$offset): Descriptor
    {
        return match ($type) {
            Type::BOOT_RECORD_DESC => new Boot($stdId, $version, $bytes, $offset),
            Type::PRIMARY_VOLUME_DESC => new PrimaryVolume($stdId, $version, $bytes, $offset),
            Type::SUPPLEMENTARY_VOLUME_DESC => new SupplementaryVolume($stdId, $version, $bytes, $offset),
            Type::PARTITION_VOLUME_DESC => new Partition($stdId, $version, $bytes, $offset),
            Type::TERMINATOR_DESC => new Terminator($stdId, $version),
            Type::UDF_BEA_VOLUME_DESC => new UdfBeaDescriptor($stdId, $version),
            Type::UDF_NSR2_VOLUME_DESC => new UdfNsr2Descriptor($stdId, $version),
            Type::UDF_NSR3_VOLUME_DESC => new UdfNsr3Descriptor($stdId, $version),
            Type::UDF_TEA_VOLUME_DESC => new UdfTeaDescriptor($stdId, $version),
            default => throw new Exception('Invalid descriptor type received: ' . $type),
        };
    }
}
