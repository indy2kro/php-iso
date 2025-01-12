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
        $offset = 0;

        $string = $this->isoFile->read(2048);

        if ($string === false) {
            return null;
        }

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

        $descriptor = Factory::create($type, $stdId, $version, $bytes);
        $descriptor->init($this->isoFile, $offset);

        return $descriptor;
    }
}
