<?php

declare(strict_types=1);

namespace PhpIso;

use Carbon\Carbon;
use PhpIso\Util\Buffer;
use PhpIso\Util\IsoDate;

class FileDirectory
{
    /**
     * If set to ZERO, shall mean that the existence of the file shall be made known to the
     * user upon an inquiry by the user.
     * If set to ONE, shall mean that the existence of the file need not be made known to
     * the user.
     */
    public const FILE_MODE_HIDDEN = 0x01;

    /**
     * If set to ZERO, shall mean that the Directory Record does not identify a directory.
     * If set to ONE, shall mean that the Directory Record identifies a directory.
     */
    public const FILE_MODE_DIRECTORY = 0x02;

    /**
     * If set to ZERO, shall mean that the file is not an Associated File.
     * If set to ONE, shall mean that the file is an Associated File.
     */
    public const FILE_MODE_ASSOCIATED = 0x04;

    /**
     * If set to ZERO, shall mean that the structure of the information in the file is not
     * specified by the Record Format field of any associated Extended Attribute Record (see 9.5.8).
     * If set to ONE, shall mean that the structure of the information in the file has a
     * record format specified by a number other than zero in the Record Format Field of
     * the Extended Attribute Record (see 9.5.8).
     */
    public const FILE_MODE_RECORD = 0x08;
    /**
     * If set to ZERO, shall mean that
     * - an Owner Identification and a Group Identification are not specified for the file (see 9.5.1 and 9.5.2);
     * - any user may read or execute the file (see 9.5.3). If set to ONE, shall mean that
     * - an Owner Identification and a Group Identification are specified for the file (see 9.5.1 and 9.5.2);
     * - at least one of the even-numbered bits or bit 0 in the Permissions field of the associated Extended Attribute Record is set to ONE (see 9.5.3).
     */
    public const FILE_MODE_PROTECTED = 0x10;
    /**
     * If set to ZERO, shall mean that this is the final Directory Record for the file.
     * If set to ONE, shall mean that this is not the final Directory Record for the file.
     */
    public const FILE_MODE_MULTI_EXTENT = 0x80;

    /**
     * Size of a logical sector, directory records never cross its boundaries
     */
    public const SECTOR_SIZE = 2048;

    private function __construct(
        /**
         * The length of the "Directory Record"
         */
        public readonly int $dirRecLength,
        /**
         * The length of the "Directory Record" extended attribute record
         */
        public readonly int $extendedAttrRecordLength,
        /**
         * Location of extents
         */
        public readonly int $location,
        /**
         * The length of the data (the content for a file, the "child file & folder for a directory...
         */
        public readonly int $dataLength,
        /**
         * The recording date
         */
        public readonly ?Carbon $recordingDate,
        /**
         * File (or folder) flags.
         */
        public readonly int $flags,
        /**
         * The File Unit Size
         */
        public readonly int $fileUnitSize,
        /**
         * The Interleave Gap Size
         */
        public readonly int $interleaveGapSize,
        /**
         * The ordinal number of the volume in the Volume Set
         */
        public readonly int $volumeSeqNum,
        /**
         * The length of the file identifier
         */
        public readonly int $fileIdLength,
        /**
         * The file identifier
         */
        public readonly string $fileId,
        public readonly int $jolietLevel,
        /**
         * Raw system use area of the record (Rock Ridge / SUSP entries)
         */
        public readonly string $systemUse
    ) {
    }

    /**
     * Read a "Directory Record" from the buffer, moving the offset after it
     *
     * @param array<int, int> $buffer
     *
     * @return self|null null at the end of the records (no data, or a zero length record)
     *
     * @throws Exception when the record is corrupt
     */
    public static function read(array &$buffer, int &$offset, bool $supplementary = false, int $jolietLevel = 0): ?self
    {
        $tmp = $offset;

        if (! isset($buffer[$tmp])) {
            return null;
        }

        $dirRecLength = $buffer[$tmp];
        $tmp++;
        if ($dirRecLength === 0) {
            return null;
        }

        // a record shorter than its fixed part, or running past the buffer, is corrupt
        if ($dirRecLength < 34 || ! isset($buffer[$offset + $dirRecLength - 1])) {
            throw new Exception('Invalid directory record length: ' . $dirRecLength);
        }

        $extendedAttrRecordLength = $buffer[$tmp];
        $tmp++;

        $location = Buffer::readBBO($buffer, 8, $tmp);
        $dataLength = Buffer::readBBO($buffer, 8, $tmp);

        $recordingDate = IsoDate::init7($buffer, $tmp);

        $flags = $buffer[$tmp];
        $tmp++;
        $fileUnitSize = $buffer[$tmp];
        $tmp++;
        $interleaveGapSize = $buffer[$tmp];
        $tmp++;

        $volumeSeqNum = Buffer::readBBO($buffer, 4, $tmp);

        $fileIdLength = $buffer[$tmp];
        $tmp++;

        if ($fileIdLength === 1 && $buffer[$tmp] === 0) {
            $fileId = '.';
            $tmp++;
        } elseif ($fileIdLength === 1 && $buffer[$tmp] === 1) {
            $fileId = '..';
            $tmp++;
        } else {
            $fileId = Buffer::readDString($buffer, $fileIdLength, $tmp, $supplementary);

            $pos = strpos($fileId, ';1');
            if ($pos !== false && $pos === strlen($fileId) - 2) {
                $fileId = substr($fileId, 0, strlen($fileId) - 2);
            }

            $fileId = trim($fileId);
        }

        // the system use area (SUSP / Rock Ridge) follows the identifier and its padding byte
        $areaStart = $tmp + ($fileIdLength % 2 === 0 ? 1 : 0);
        $areaEnd = $offset + $dirRecLength;
        $systemUse = '';
        for ($i = $areaStart; $i < $areaEnd; $i++) {
            $systemUse .= chr($buffer[$i]);
        }

        $offset += $dirRecLength;

        return new self($dirRecLength, $extendedAttrRecordLength, $location, $dataLength, $recordingDate, $flags, $fileUnitSize, $interleaveGapSize, $volumeSeqNum, $fileIdLength, $fileId, $jolietLevel, $systemUse);
    }
    /**
     * Test if the "Directory Record" is hidden
     */
    public function isHidden(): bool
    {
        return ($this->flags & self::FILE_MODE_HIDDEN) === self::FILE_MODE_HIDDEN;
    }

    /**
     * Test if the "Directory Record" is directory
     */
    public function isDirectory(): bool
    {
        return ($this->flags & self::FILE_MODE_DIRECTORY) === self::FILE_MODE_DIRECTORY;
    }

    /**
     * Test if the "Directory Record" is associated
     */
    public function isAssociated(): bool
    {
        return ($this->flags & self::FILE_MODE_ASSOCIATED) === self::FILE_MODE_ASSOCIATED;
    }

    /**
     * Test if the "Directory Record" is record
     */
    public function isRecord(): bool
    {
        return ($this->flags & self::FILE_MODE_RECORD) === self::FILE_MODE_RECORD;
    }

    /**
     * Test if the "Directory Record" is protected
     */
    public function isProtected(): bool
    {
        return ($this->flags & self::FILE_MODE_PROTECTED) === self::FILE_MODE_PROTECTED;
    }

    /**
     * Test if the "Directory Record" is a multi-extent
     */
    public function isMultiExtent(): bool
    {
        return ($this->flags & self::FILE_MODE_MULTI_EXTENT) === self::FILE_MODE_MULTI_EXTENT;
    }

    /**
     * Test if the "Directory Record" is a "node" to itself
     */
    public function isThis(): bool
    {
        if ($this->fileIdLength > 1) {
            return false;
        }

        return $this->fileId === '.';
    }

    /**
     * Test if the "Directory Record" is a "node" to its parent
     */
    public function isParent(): bool
    {
        if ($this->fileIdLength > 1) {
            return false;
        }

        return $this->fileId === '..';
    }

    /**
     * Load the "File Directory Descriptors" (extents) from ISO file
     *
     * @return array<int, FileDirectory>|false
     */
    public function loadExtents(IsoFile $isoFile, int $blockSize, bool $supplementary = false, int $jolietLevel = 0): array|false
    {
        return self::loadExtentsSt($isoFile, $blockSize, $this->location, $supplementary, $jolietLevel, $this->dataLength);
    }

    /**
     * Load the "File Directory Descriptors"(extents) from ISO file
     *
     * When the directory size is not known, it is read from the "." record at the start of the directory.
     *
     * @return array<int, FileDirectory>|false
     */
    public static function loadExtentsSt(IsoFile $isoFile, int $blockSize, int $location, bool $supplementary = false, int $jolietLevel = 0, ?int $dataLength = null): array|false
    {
        $sector = self::SECTOR_SIZE;

        $position = $location * $blockSize;

        $string = self::readAt($isoFile, $position, $dataLength === null ? $sector : self::boundedLength($isoFile, $position, $dataLength));
        if ($string === false) {
            return false;
        }

        if ($dataLength === null) {
            // peek at the "." record to find the real size of the directory
            /** @var array<int, int>|false $first */
            $first = unpack('C*', $string);
            $offset = 1;
            $self = null;
            if ($first !== false) {
                try {
                    $self = self::read($first, $offset, $supplementary, $jolietLevel);
                } catch (Exception) {
                    $self = null;
                }
            }

            if ($self instanceof self && $self->dataLength > strlen($string)) {
                $full = self::readAt($isoFile, $position, self::boundedLength($isoFile, $position, $self->dataLength));
                if ($full !== false) {
                    $string = $full;
                }
            }
        }

        /** @var array<int, int>|false $bytes */
        $bytes = unpack('C*', $string);

        if ($bytes === false) {
            return false;
        }

        $total = count($bytes);
        $offset = 1;
        $extents = [];

        while ($offset <= $total) {
            try {
                $fdDesc = self::read($bytes, $offset, $supplementary, $jolietLevel);
            } catch (Exception) {
                // corrupt record: keep what was parsed so far
                break;
            }

            if ($fdDesc instanceof self) {
                $extents[] = $fdDesc;
                continue;
            }

            // records never cross a sector boundary: a zero length means padding up to the next sector
            $next = (int) (floor(($offset - 1) / $sector) + 1) * $sector + 1;
            if ($next <= $offset) {
                break;
            }
            $offset = $next;
        }

        return $extents;
    }

    protected static function readAt(IsoFile $isoFile, int $position, int $length): string|false
    {
        if ($isoFile->seek($position, SEEK_SET) === -1) {
            return false;
        }

        return $isoFile->read($length);
    }

    /**
     * Never read more than what is left in the ISO file (protects against hostile sizes)
     */
    protected static function boundedLength(IsoFile $isoFile, int $position, int $length): int
    {
        $size = $isoFile->getSize();

        // mocks / unknown sizes: fall back to the requested length with a sane cap
        if ($size <= 0) {
            return max(0, min($length, 16 * 1024 * 1024));
        }

        return max(0, min($length, $size - $position));
    }
}
