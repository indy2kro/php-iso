<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Descriptor\Reader;
use PhpIso\Descriptor\Type;

class IsoFile
{
    /**
     * @var array<int, Descriptor>
     */
    public array $descriptors = [];
    /**
     * @var resource
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
        return fseek($this->fileHandle, $offset, $whence);
    }

    public function read(int $length): string|false
    {
        if ($length < 1) {
            return false;
        }

        return fread($this->fileHandle, $length);
    }

    protected function processFile(): bool
    {
        if ($this->seek(16 * 2048, SEEK_SET) === -1) {
            return false;
        }

        $reader = new Reader($this);

        while (($descriptor = $reader->read()) !== null) {
            $this->descriptors[$descriptor->getType()] = $descriptor;

            if ($descriptor->getType() === Type::TERMINATOR_DESC) {
                break;
            }
        }

        return count($this->descriptors) > 0;
    }

    protected function openFile(): void
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

    protected function closeFile(): void
    {
        fclose($this->fileHandle);
    }
}
