<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;
use PhpIso\Util\Buffer;

class Reader
{
    public function __construct(protected IsoFile $isoFile)
    {
    }

    public function read(): ?Descriptor
    {
        $string = $this->isoFile->read(2048);

        if ($string === false) {
            return null;
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

        $descriptor = Factory::create($type, $stdId, $version, $bytes);
        $descriptor->init($this->isoFile, $offset);

        return $descriptor;
    }
}
