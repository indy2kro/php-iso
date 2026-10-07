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
use PhpIso\Udf\UdfFileSystem;
use Throwable;

class IsoFile
{
    /**
     * Upper bound for a single read, protects against sizes taken from a crafted image
     */
    public const MAX_READ_LENGTH = 64 * 1024 * 1024;

    /**
     * Maximum number of volume descriptors read before giving up (a crafted image could chain them endlessly)
     */
    public const MAX_DESCRIPTORS = 64;

    /**
     * Descriptors of a type that was already present (the first one wins), kept in file order
     *
     * @var array<int, Descriptor>
     */
    public readonly array $additionalDescriptors;

    /**
     * Largest stream accepted by fromStream() by default (4 GiB)
     */
    public const MAX_STREAM_BYTES = 4 * 1024 * 1024 * 1024;

    private ?string $temporaryFile = null;

    private ?UdfFileSystem $udf = null;

    private bool $udfLoaded = false;

    /**
     * @var array<int, Descriptor>
     */
    public readonly array $descriptors;

    /**
     * @var ?resource
     */
    protected mixed $fileHandle = null;

    public function __construct(protected string $isoFilePath)
    {
        $this->openFile();

        [$this->descriptors, $this->additionalDescriptors] = $this->readDescriptors();
    }

    public function __destruct()
    {
        $this->closeFile();

        if ($this->temporaryFile !== null && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    /**
     * Open an image coming from a stream that cannot be seeked (standard input, a pipe, a socket...)
     *
     * The stream is first copied to a temporary file, removed when the object is destroyed.
     *
     * @param resource $stream
     * @param int $maxBytes refuse streams bigger than this
     *
     * @throws Exception
     */
    public static function fromStream(mixed $stream, int $maxBytes = self::MAX_STREAM_BYTES): self
    {
        $path = tempnam(sys_get_temp_dir(), 'pis');
        if ($path === false) {
            throw new Exception('Cannot create a temporary file');
        }

        try {
            $output = fopen($path, 'wb');
            if ($output === false) {
                throw new Exception('Cannot open the temporary file for writing');
            }

            try {
                $copied = stream_copy_to_stream($stream, $output, $maxBytes + 1);
            } finally {
                fclose($output);
            }

            if ($copied === false) {
                throw new Exception('Failed to read the input stream');
            }

            if ($copied > $maxBytes) {
                throw new Exception('The input stream is bigger than ' . $maxBytes . ' bytes');
            }

            $isoFile = new self($path);
        } catch (Throwable $throwable) {
            unlink($path);

            throw $throwable;
        }

        $isoFile->temporaryFile = $path;

        return $isoFile;
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
     * The Joliet volume descriptor (a supplementary one with a Joliet escape sequence), if present
     *
     * Plain supplementary descriptors and enhanced volume descriptors (see getEnhancedVolume()) are not returned.
     */
    public function getSupplementaryVolume(): ?SupplementaryVolume
    {
        foreach ([...$this->descriptors, ...$this->additionalDescriptors] as $descriptor) {
            if ($descriptor instanceof SupplementaryVolume && $descriptor->jolietLevel > 0) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * The enhanced volume descriptor (ISO 9660:1999, a supplementary descriptor of version 2), if present
     */
    public function getEnhancedVolume(): ?SupplementaryVolume
    {
        foreach ([...$this->descriptors, ...$this->additionalDescriptors] as $descriptor) {
            if ($descriptor instanceof SupplementaryVolume && $descriptor->version === 2) {
                return $descriptor;
            }
        }

        return null;
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
     * The UDF file system of the image, null when there is none
     *
     * @throws Exception when the UDF structures are present but unsupported or corrupt
     */
    public function getUdfFileSystem(): ?UdfFileSystem
    {
        if (! $this->udfLoaded) {
            $this->udf = UdfFileSystem::open($this);
            $this->udfLoaded = true;
        }

        return $this->udf;
    }

    /**
     * The file system to browse: the preferred ISO 9660 volume, or the UDF file system for UDF only images
     *
     * Windows install media and other UDF bridge images carry a stub ISO 9660 tree (typically a single README.TXT)
     * next to the real UDF one: when the ISO 9660 root has no subdirectory and at most one file while the UDF
     * root has more entries, the UDF file system is returned.
     *
     * @throws Exception when only an unsupported UDF file system is present
     */
    public function getFileSystem(): ?FileSystem
    {
        $volume = $this->getPreferredVolume();

        if ($volume === null) {
            return $this->getUdfFileSystem();
        }

        [$directories, $files] = self::countRootEntries($this, $volume, 2);
        if ($directories > 0 || $files > 1) {
            return $volume;
        }

        try {
            $udf = $this->getUdfFileSystem();
        } catch (Exception) {
            return $volume;
        }

        if ($udf instanceof UdfFileSystem) {
            [$udfDirectories, $udfFiles] = self::countRootEntries($this, $udf, $directories + $files + 1);
            if ($udfDirectories + $udfFiles > $directories + $files) {
                return $udf;
            }
        }

        return $volume;
    }

    /**
     * Number of directories and files directly in the root of a file system, counting stops after $limit entries
     *
     * @return array{int, int}
     */
    private static function countRootEntries(self $isoFile, FileSystem $fileSystem, int $limit): array
    {
        $directories = 0;
        $files = 0;

        foreach ($fileSystem->walk($isoFile) as $entry) {
            if (substr_count($entry->path, '/') > 1) {
                continue;
            }

            if ($entry->isDirectory) {
                $directories++;
            } else {
                $files++;
            }

            if ($directories + $files >= $limit) {
                break;
            }
        }

        return [$directories, $files];
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

        if (! is_file($this->isoFilePath)) {
            throw new Exception('Not a file: ' . $this->isoFilePath);
        }

        // opening twice must not leak the previous handle
        $this->closeFile();

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

    /**
     * Read the volume descriptors that follow the system area
     *
     * @return array{array<int, Descriptor>, array<int, Descriptor>} descriptors by type, then the duplicated ones
     */
    protected function readDescriptors(): array
    {
        if ($this->seek(16 * 2048, SEEK_SET) === -1) {
            return [[], []];
        }

        /** @var array<int, Descriptor> $descriptors */
        $descriptors = [];
        /** @var array<int, Descriptor> $additional */
        $additional = [];

        $reader = new Reader($this);

        $foundTerminator = false;
        $count = 0;
        while (true) {
            if (++$count > self::MAX_DESCRIPTORS) {
                throw new Exception('Too many volume descriptors (more than ' . self::MAX_DESCRIPTORS . '), not a valid ISO image');
            }

            try {
                $descriptor = $reader->read();

                if ($descriptor === null) {
                    throw new Exception('Finished reading');
                }

                if (isset($descriptors[$descriptor->getType()]) && ! ($descriptor instanceof UdfDescriptor)) {
                    // e.g. an enhanced volume descriptor next to a Joliet one: keep the first, do not stop reading
                    $additional[] = $descriptor;
                } else {
                    $descriptors[$descriptor->getType()] = $descriptor;
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

        return [$descriptors, $additional];
    }
}
