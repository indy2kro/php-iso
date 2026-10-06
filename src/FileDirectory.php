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

    /**
     * The length of the "Directory Record"
     */
    public int $dirRecLength = 0;

    /**
     * The length of the "Directory Record" extended attribute record
     */
    public int $extendedAttrRecordLength;

    /**
     * Location of extents
     */
    public int $location;

    /**
     * The length of the data (the content for a file, the "child file & folder for a directory...
     */
    public int $dataLength;

    /**
     * The recording date
     */
    public ?Carbon $recordingDate = null;

    /**
     * File (or folder) flags.
     */
    public int $flags;

    /**
     * The File Unit Size
     */
    public int $fileUnitSize;

    /**
     * The Interleave Gap Size
     */
    public int $interleaveGapSize;

    /**
     * The ordinal number of the volume in the Volume Set
     */
    public int $volumeSeqNum;

    /**
     * The length of the file identifier
     */
    public int $fileIdLength;

    /**
     * The file identifier
     */
    public string $fileId;

    public int $jolietLevel = 0;

    /**
     * Raw system use area of the record (Rock Ridge / SUSP entries)
     */
    public string $systemUse = '';

    /**
     * Load the "Directory Record" from buffer
     *
     * @param array<int, int> $buffer
     */
    public function init(array &$buffer, int &$offset, bool $supplementary = false): bool
    {
        $tmp = $offset;

        if (! isset($buffer[$tmp])) {
            return false;
        }

        $this->dirRecLength = $buffer[$tmp];
        $tmp++;
        if ($this->dirRecLength === 0) {
            return false;
        }

        // a record shorter than its fixed part, or running past the buffer, is corrupt
        if ($this->dirRecLength < 34 || ! isset($buffer[$offset + $this->dirRecLength - 1])) {
            throw new Exception('Invalid directory record length: ' . $this->dirRecLength);
        }

        $this->extendedAttrRecordLength = $buffer[$tmp];
        $tmp++;

        $this->location = Buffer::readBBO($buffer, 8, $tmp);
        $this->dataLength = Buffer::readBBO($buffer, 8, $tmp);

        $this->recordingDate = IsoDate::init7($buffer, $tmp);

        $this->flags = $buffer[$tmp];
        $tmp++;
        $this->fileUnitSize = $buffer[$tmp];
        $tmp++;
        $this->interleaveGapSize = $buffer[$tmp];
        $tmp++;

        $this->volumeSeqNum = Buffer::readBBO($buffer, 4, $tmp);

        $this->fileIdLength = $buffer[$tmp];
        $tmp++;

        if ($this->fileIdLength === 1 && $buffer[$tmp] === 0) {
            $this->fileId = '.';
            $tmp++;
        } elseif ($this->fileIdLength === 1 && $buffer[$tmp] === 1) {
            $this->fileId = '..';
            $tmp++;
        } else {
            $this->fileId = Buffer::readDString($buffer, $this->fileIdLength, $tmp, $supplementary);

            $pos = strpos($this->fileId, ';1');
            if ($pos !== false && $pos === strlen($this->fileId) - 2) {
                $this->fileId = substr($this->fileId, 0, strlen($this->fileId) - 2);
            }

            $this->fileId = trim($this->fileId);
        }

        // the system use area (SUSP / Rock Ridge) follows the identifier and its padding byte
        $areaStart = $tmp + ($this->fileIdLength % 2 === 0 ? 1 : 0);
        $areaEnd = $offset + $this->dirRecLength;
        $this->systemUse = '';
        for ($i = $areaStart; $i < $areaEnd; $i++) {
            $this->systemUse .= chr($buffer[$i]);
        }

        $offset += $this->dirRecLength;
        return true;
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
            $self = new self();
            if ($first !== false && $self->init($first, $offset, $supplementary) && $self->dataLength > strlen($string)) {
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
            $fdDesc = new self();
            $fdDesc->jolietLevel = $jolietLevel;

            try {
                $found = $fdDesc->init($bytes, $offset, $supplementary);
            } catch (Exception) {
                // corrupt record: keep what was parsed so far
                break;
            }

            if ($found) {
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
