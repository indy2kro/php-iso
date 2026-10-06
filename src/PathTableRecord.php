<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Util\Buffer;

class PathTableRecord
{
    /**
     * The directory number
     */
    public int $dirNum;

    /**
     * The length of the Dir Identifier
     */
    public int $dirIdLen;

    /**
     * The length of the extended attributes
     */
    public int $extendedAttrLength;

    /**
     * The location of this "Path Table Record"
     */
    public int $location;

    /**
     * The parent's directory number
     */
    public int $parentDirNum;

    /**
     * The directory identifier.
     */
    public string $dirIdentifier;

    /**
     * Set the directory number
     */
    public function setDirectoryNumber(int $dirNum): void
    {
        $this->dirNum = $dirNum;
    }

    /**
     * Load the "Path Table Record" from buffer
     *
     * @param array<int, int> $bytes
     */
    public function init(array &$bytes, int &$offset, bool $supplementary = false, bool $littleEndian = false): bool
    {
        $offsetTmp = $offset;

        if (! isset($bytes[$offsetTmp])) {
            return false;
        }

        $this->dirIdLen = $bytes[$offsetTmp];
        $offsetTmp++;

        if ($this->dirIdLen === 0) {
            return false;
        }

        // the fixed part is 8 bytes, followed by the identifier
        if (! isset($bytes[$offsetTmp + 6 + $this->dirIdLen])) {
            return false;
        }

        $this->extendedAttrLength = $bytes[$offsetTmp];
        $offsetTmp++;
        if ($littleEndian) {
            $this->location = Buffer::readLSB($bytes, 4, $offsetTmp);
            $this->parentDirNum = Buffer::readLSB($bytes, 2, $offsetTmp);
        } else {
            $this->location = Buffer::readInt32($bytes, $offsetTmp);
            $this->parentDirNum = Buffer::readInt16($bytes, $offsetTmp);
        }
        $this->dirIdentifier = Buffer::readAString($bytes, $this->dirIdLen, $offsetTmp, $supplementary);

        if ($this->dirIdLen % 2 !== 0) {
            $offsetTmp++;
        }

        $offset = $offsetTmp;
        return true;
    }

    /**
     * Load the "File Directory Descriptors"(extents) from ISO file
     *
     * @return array<int, FileDirectory>|false
     */
    public function loadExtents(IsoFile &$isoFile, int $blockSize, bool $supplementary = false, int $jolietLevel = 0): array|false
    {
        return FileDirectory::loadExtentsSt($isoFile, $blockSize, $this->location, $supplementary, $jolietLevel);
    }

    /**
     * Extract a file to disk
     *
     * @throws Exception
     */
    public function extractFile(IsoFile &$isoFile, int $blockSize, int $location, int $dataLength, string $destinationFile): void
    {
        $isoFile->extractRange($location * $blockSize, $dataLength, $destinationFile);
    }

    /**
     * Build the full path of a PathTableRecord object based on it's parent(s)
     *
     * @param array<int, PathTableRecord> $pathTable
     *
     * @throws Exception
     */
    public function getFullPath(array $pathTable): string
    {
        if ($this->parentDirNum === 1) {
            if ($this->dirIdentifier === '') {
                return DIRECTORY_SEPARATOR;
            }

            return DIRECTORY_SEPARATOR . $this->dirIdentifier . DIRECTORY_SEPARATOR;
        }

        $path = $this->dirIdentifier;
        $used = $pathTable[$this->parentDirNum] ?? throw new Exception('Missing parent directory in path table: ' . $this->parentDirNum);

        $depth = 0;
        while (true) {
            $depth++;

            // max depth check
            if ($depth > 1000) {
                throw new Exception('Maximum depth of 1000 reached');
            }

            $path = $used->dirIdentifier . DIRECTORY_SEPARATOR . $path;

            if ($used->parentDirNum === 1) {
                break;
            }

            $used = $pathTable[$used->parentDirNum] ?? throw new Exception('Missing parent directory in path table: ' . $used->parentDirNum);
        }

        return DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR;
    }
}
