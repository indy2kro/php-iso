<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Descriptor\Reader;
use PhpIso\Descriptor\Type;
use PhpIso\Descriptor\UdfDescriptor;
use PhpIso\Descriptor\UdfType;

class IsoFile
{
    /**
     * @var array<int, Descriptor>
     */
    public array $descriptors = [];

    /**
     * @var ?resource
     */
    protected mixed $fileHandle;

    public function __construct(protected string $isoFilePath)
    {
        $this->openFile();

        $this->processFile();
    }

    public function __destruct()
    {
        $this->closeFile();
    }

    public function seek(int $offset, int $whence = SEEK_SET): int
    {
        if ($this->fileHandle === null) {
            return -1;
        }

        return fseek($this->fileHandle, $offset, $whence);
    }

    public function read(int $length): string|false
    {
        if ($length < 1) {
            return false;
        }

        if ($this->fileHandle === null) {
            return false;
        }

        return fread($this->fileHandle, $length);
    }

    public function openFile(): void
    {
        if (file_exists($this->isoFilePath) === false) {
            throw new Exception('File does not exist: ' . $this->isoFilePath);
        }

        $fileHandle = fopen($this->isoFilePath, 'rb');

        if ($fileHandle === false) {
            throw new Exception('Cannot open file for reading: ' . $this->isoFilePath);
        }

        $this->fileHandle = $fileHandle;
    }

    public function closeFile(): void
    {
        if ($this->fileHandle === null) {
            return;
        }

        fclose($this->fileHandle);
        $this->fileHandle = null;
    }

    protected function processFile(): bool
    {
        if ($this->seek(16 * 2048, SEEK_SET) === -1) {
            return false;
        }

        $reader = new Reader($this);

        $foundTerminator = false;
        while (true) {
            try {
                $descriptor = $reader->read();

                if ($descriptor === null) {
                    throw new Exception('Finished reading');
                }

                if (isset($this->descriptors[$descriptor->getType()])) {
                    throw new Exception('Descriptor already exists');
                }

                $this->descriptors[$descriptor->getType()] = $descriptor;
            } catch (Exception $ex) {
                if ($foundTerminator) {
                    break;
                }
                throw $ex;
            }

            // If it's a UDF descriptor, handle it separately
            if ($descriptor instanceof UdfDescriptor) {
                if ($descriptor->udfType === UdfType::TEA01) {
                    break; // Stop at Terminating Extended Area Descriptor
                }
            } else {
                if ($foundTerminator) {
                    break;
                }
            }

            if ($descriptor->getType() === Type::TERMINATOR_DESC) {
                if ($foundTerminator) {
                    break;
                }

                $foundTerminator = true;
                // Keep going if UDF might still be present
                continue;
            }
        }

        return count($this->descriptors) > 0;
    }
}
