<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\PrimaryVolume;
use PhpIso\Descriptor\Reader;
use PhpIso\Descriptor\SupplementaryVolume;
use PhpIso\Descriptor\Type;
use PhpIso\Descriptor\UdfDescriptor;
use PhpIso\Descriptor\UdfTeaDescriptor;
use PhpIso\Descriptor\Volume;

class IsoFile
{
    /**
     * Upper bound for a single read, protects against sizes taken from a crafted image
     */
    public const MAX_READ_LENGTH = 64 * 1024 * 1024;

    /**
     * Descriptors of a type that was already present (the first one wins), kept in file order
     *
     * @var array<int, Descriptor>
     */
    public array $additionalDescriptors = [];

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

    /**
     * Size of the ISO file in bytes (0 when unknown)
     */
    public function getSize(): int
    {
        $size = filesize($this->isoFilePath);

        return $size === false ? 0 : $size;
    }

    /**
     * Copy a byte range of the ISO to a file on disk
     *
     * @throws Exception
     */
    public function extractRange(int $offset, int $length, string $destinationFile): void
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->getSize()) {
            throw new Exception('Requested range is outside of the ISO file');
        }

        if ($this->seek($offset, SEEK_SET) === -1) {
            throw new Exception('Failed to seek to location');
        }

        $writeHandle = fopen($destinationFile, 'wb');

        if ($writeHandle === false) {
            throw new Exception('Failed to open file for writing: ' . $destinationFile);
        }

        try {
            $this->copyRange($offset, $length, $writeHandle);
        } finally {
            fclose($writeHandle);
        }
    }

    /**
     * Copy a byte range of the ISO to an open stream
     *
     * @param resource $output
     *
     * @throws Exception
     */
    public function copyRange(int $offset, int $length, mixed $output): void
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->getSize()) {
            throw new Exception('Requested range is outside of the ISO file');
        }

        if ($this->seek($offset, SEEK_SET) === -1) {
            throw new Exception('Failed to seek to location');
        }

        $remaining = $length;
        while ($remaining > 0) {
            $chunk = $this->read(min(8192, $remaining));

            if ($chunk === false || $chunk === '') {
                throw new Exception('Unexpected end of ISO data while reading');
            }

            if (fwrite($output, $chunk) === false) {
                throw new Exception('Failed to write the data');
            }

            $remaining -= strlen($chunk);
        }
    }

    /**
     * The primary volume descriptor, if present
     */
    public function getPrimaryVolume(): ?PrimaryVolume
    {
        $descriptor = $this->descriptors[Type::PRIMARY_VOLUME_DESC] ?? null;

        return $descriptor instanceof PrimaryVolume ? $descriptor : null;
    }

    /**
     * The supplementary (Joliet) volume descriptor, if present
     */
    public function getSupplementaryVolume(): ?SupplementaryVolume
    {
        $descriptor = $this->descriptors[Type::SUPPLEMENTARY_VOLUME_DESC] ?? null;

        return $descriptor instanceof SupplementaryVolume ? $descriptor : null;
    }

    /**
     * The boot record descriptor, if present
     */
    public function getBootRecord(): ?Boot
    {
        $descriptor = $this->descriptors[Type::BOOT_RECORD_DESC] ?? null;

        return $descriptor instanceof Boot ? $descriptor : null;
    }

    /**
     * The volume that should be used to browse files: Joliet (long, Unicode names) when available, otherwise primary
     */
    public function getPreferredVolume(): ?Volume
    {
        return $this->getSupplementaryVolume() ?? $this->getPrimaryVolume();
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
        if ($length < 1 || $length > self::MAX_READ_LENGTH) {
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

    protected function processFile(): void
    {
        if ($this->seek(16 * 2048, SEEK_SET) === -1) {
            return;
        }

        $reader = new Reader($this);

        $foundTerminator = false;
        while (true) {
            try {
                $descriptor = $reader->read();

                if ($descriptor === null) {
                    throw new Exception('Finished reading');
                }

                if (isset($this->descriptors[$descriptor->getType()]) && ! ($descriptor instanceof UdfDescriptor)) {
                    // e.g. an enhanced volume descriptor next to a Joliet one: keep the first, do not stop reading
                    $this->additionalDescriptors[] = $descriptor;
                } else {
                    $this->descriptors[$descriptor->getType()] = $descriptor;
                }
            } catch (Exception $ex) {
                if ($foundTerminator) {
                    break;
                }
                throw $ex;
            }

            // If it's a UDF descriptor, handle it separately
            if ($descriptor instanceof UdfDescriptor) {
                if ($descriptor instanceof UdfTeaDescriptor) {
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
    }
}
