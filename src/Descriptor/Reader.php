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

        $udfType = '';

        // Check for UDF-specific descriptors
        if ($type === Type::BOOT_RECORD_DESC) {
            switch ($stdId) {
                case 'CD001':
                    $type = Type::BOOT_RECORD_DESC;
                    break;
                case UdfType::BEA01:
                    $type = Type::UDF_VOLUME_DESC;
                    $udfType = $stdId;
                    break;
                case UdfType::NSR02:
                    $type = Type::UDF_VOLUME_DESC;
                    $udfType = $stdId;
                    break;
                case UdfType::NSR03:
                    $type = Type::UDF_VOLUME_DESC;
                    $udfType = $stdId;
                    break;
                case UdfType::TEA01:
                    $type = Type::UDF_VOLUME_DESC;
                    $udfType = $stdId;
                    break;
                default:
                    throw new Exception('Failed to detect UDF');
            }
        }

        $descriptor = Factory::create($type, $stdId, $version, $bytes, $udfType);
        $descriptor->init($this->isoFile, $offset);

        return $descriptor;
    }
}
