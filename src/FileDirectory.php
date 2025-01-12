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
     * The length of the "Directory Record"
     */
    public int $DirRecLen = 0;
    /**
     * The length of the "Directory Record" extended attribut record
     */
    public int $ExtAttrRecLen;
    /**
     * Location of extents
     */
    public int $Location;
    /**
     * The length of the data (the content for a file, the "child file & folder for a directory...
     */
    public int $DataLen;
    /**
     * The recording date
     */
    public ?Carbon $isoRecDate = null;
    /**
     * File (or folder) flags.
     */
    public int $FileFlags;
    /**
     * The File Unit Size
     */
    public int $FileUnitSize;
    /**
     * The Interleave Gap Size
     */
    public int $InterleaveGapSize;
    /**
     * The ordinal number of the volume in the Volume Set
     */
    public int $VolSeqNum;
    /**
     * The length of the file identifier
     */
    public int $FileIdLen;
    /**
     * The file identifier
     */
    public string $strd_FileId;

    /**
     * Load the "Directory Record" from buffer
     *
     * @param array<int, mixed> $buffer
     */
    public function init(array &$buffer, int &$offset, bool $supplementary = false): bool
    {
        $tmp = $offset;

        $this->DirRecLen = (int) $buffer[$tmp];
        $tmp++;
        if ($this->DirRecLen === 0) {
            return false;
        }

        $this->ExtAttrRecLen = $buffer[$tmp];
        $tmp++;

        $this->Location = Buffer::readBBO($buffer, 8, $tmp);
        $this->DataLen = Buffer::readBBO($buffer, 8, $tmp);

        $this->isoRecDate = IsoDate::init7($buffer, $tmp);

        $this->FileFlags = $buffer[$tmp];
        $tmp++;
        $this->FileUnitSize = $buffer[$tmp];
        $tmp++;
        $this->InterleaveGapSize = $buffer[$tmp];
        $tmp++;

        $this->VolSeqNum = Buffer::readBBO($buffer, 4, $tmp);

        $this->FileIdLen = $buffer[$tmp];
        $tmp++;

        if ($this->FileIdLen === 1 && $buffer[$tmp] === 0) {
            $this->strd_FileId = '.';
            $tmp++;
        } elseif ($this->FileIdLen === 1 && $buffer[$tmp] === 1) {
            $this->strd_FileId = '..';
            $tmp++;
        } else {
            $this->strd_FileId = Buffer::readDString($buffer, $this->FileIdLen, $tmp, $supplementary);

            $pos = strpos($this->strd_FileId, ';1');
            if ($pos !== false && $pos === strlen($this->strd_FileId) - 2) {
                $this->strd_FileId = substr($this->strd_FileId, 0, strlen($this->strd_FileId) - 2);
            }
        }

        $offset += $this->DirRecLen;
        return true;
    }
    /**
     * Test if the "Directory Record" is hidden
     */
    public function isHidden(): bool
    {
        return ($this->FileFlags & self::FILE_MODE_HIDDEN) === self::FILE_MODE_HIDDEN;
    }
    /**
     * \fn public function IsDirectory()
     * \brief Test if the "Directory Record" is directory
     * return boolean true OR false
     * \see FILE_MODE_DIRECTORY
     */
    public function isDirectory(): bool
    {
        return ($this->FileFlags & self::FILE_MODE_DIRECTORY) === self::FILE_MODE_DIRECTORY;
    }
    /**
     * \fn public function IsAssociated()
     * \brief Test if the "Directory Record" is associated
     * \return boolean true OR false
     * \see FILE_MODE_ASSOCIATED
     */
    public function isAssociated(): bool
    {
        return ($this->FileFlags & self::FILE_MODE_ASSOCIATED) === self::FILE_MODE_ASSOCIATED;
    }
    /**
     * \fn public function IsRecord()
     * \brief Test if the "Directory Record" is record
     * \return boolean true OR false
     * \see FILE_MODE_RECORD
     */
    public function isRecord(): bool
    {
        return ($this->FileFlags & self::FILE_MODE_RECORD) === self::FILE_MODE_RECORD;
    }
    /**
     * \fn public function IsProtected()
     * \brief Test if the "Directory Record" is protected
     * \return boolean true OR false
     * \see FILE_MODE_PROTECTED
     */
    public function isProtected(): bool
    {
        return ($this->FileFlags & self::FILE_MODE_PROTECTED) === self::FILE_MODE_PROTECTED;
    }
    /**
     * \fn public function IsMultiExtent()
     * \brief Test if the "Directory Record" is a multi-extent
     * \return boolean true OR false
     * \see FILE_MODE_MULTI_EXTENT
     */
    public function isMultiExtent(): bool
    {
        return ($this->FileFlags & self::FILE_MODE_MULTI_EXTENT) === self::FILE_MODE_MULTI_EXTENT;
    }
    /**
     * \fn public function IsThis()
     * \brief Test if the "Directory Record" is a "node" to itself
     * \return boolean true OR false
     */
    public function isThis(): bool
    {
        if ($this->FileIdLen > 1) {
            return false;
        }

        return $this->strd_FileId === '.';
    }
    /**
     * \fn public function IsParent()
     * \brief Test if the "Directory Record" is a "node" to its parent
     * \return boolean true OR false
     */
    public function isParent(): bool
    {
        if ($this->FileIdLen > 1) {
            return false;
        }

        return $this->strd_FileId === '..';
    }

    /**
     * Load the "File Directory Descriptors"(extents) from ISO file
     *
     * @return array<int, FileDirectory>|false
     */
    public function loadExtents(IsoFile $isoFile, int $BlockSize, bool $supplementary = false): array|false
    {
        return self::loadExtentsSt($isoFile, $BlockSize, $this->Location, $supplementary);
    }

    /**
     * Load the "File Directory Descriptors"(extents) from ISO file
     *
     * @return array<int, FileDirectory>|false
     */
    public static function loadExtentsSt(IsoFile $isoFile, int $BlockSize, int $Location, bool $supplementary = false): array|false
    {
        if (! $isoFile->seek($Location * $BlockSize, SEEK_SET)) {
            return false;
        }

        $string = $isoFile->read(4096);
        if ($string === false) {
            return false;
        }

        $bytes = unpack('C*', $string);

        if ($bytes === false) {
            return false;
        }

        $offset = 1;
        $fdDesc = new self();
        $Extents = [];

        while ($fdDesc->Init($bytes, $offset, $supplementary) !== false) {
            $Extents[] = $fdDesc;
            $fdDesc = new self();
        }

        return $Extents;
    }
}
