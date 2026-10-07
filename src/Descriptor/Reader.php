<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;
use PhpIso\Util\Buffer;

class Reader
{
    /**
     * @param int $sector number of the sector the next read() starts at (only used in error messages)
     */
    public function __construct(protected IsoFile $isoFile, protected int $sector = 16)
    {
    }

    public function read(): ?Descriptor
    {
        $string = $this->isoFile->read(2048);
        $sector = $this->sector++;

        if ($string === false) {
            return null;
        }

        if (strlen($string) < 2048) {
            throw new Exception('Truncated or invalid ISO image: volume descriptor at sector ' . $sector . ' is incomplete');
        }

        /** @var array<int, int>|false $bytes */
        $bytes = unpack('C*', $string);

        if ($bytes === false) {
            return null;
        }

        $offset = 1;

        if (! isset($bytes[$offset])) {
            throw new Exception('Failed to read buffer entry ' . $offset);
        }

        $type = $bytes[$offset];
        $offset++;
        $stdId = Buffer::getString($bytes, 5, $offset);

        if (! isset($bytes[$offset])) {
            throw new Exception('Failed to read buffer entry ' . $offset);
        }

        $version = $bytes[$offset];
        $offset++;

        $iso9660Types = [Type::PRIMARY_VOLUME_DESC, Type::SUPPLEMENTARY_VOLUME_DESC, Type::PARTITION_VOLUME_DESC, Type::TERMINATOR_DESC];
        if (in_array($type, $iso9660Types, true) && $stdId !== 'CD001') {
            throw new Exception('Not an ISO 9660 volume descriptor');
        }

        // Check for UDF-specific descriptors
        if ($type === Type::BOOT_RECORD_DESC) {
            $type = match ($stdId) {
                'CD001' => Type::BOOT_RECORD_DESC,
                UdfType::BEA01 => Type::UDF_BEA_VOLUME_DESC,
                UdfType::NSR02 => Type::UDF_NSR2_VOLUME_DESC,
                UdfType::NSR03 => Type::UDF_NSR3_VOLUME_DESC,
                UdfType::TEA01 => Type::UDF_TEA_VOLUME_DESC,
                default => throw new Exception('Failed to detect UDF'),
            };
        }

        return Factory::create($type, $stdId, $version, $bytes, $offset);
    }
}
