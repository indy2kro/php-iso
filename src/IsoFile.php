<?php

declare(strict_types=1);

namespace PhpIso;

class IsoFile
{
    /**
     * @var resource
     */
    protected mixed $fileHandle;

    public function __construct(protected string $isoFilePath)
    {
        $this->openFile();
    }

    public function __destruct()
    {
        $this->closeFile();
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
