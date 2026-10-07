<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Util\Buffer;

class PathTableRecord
{
    private function __construct(
        /**
         * The directory number
         */
        public readonly int $dirNum,
        /**
         * The length of the Dir Identifier
         */
        public readonly int $dirIdLen,
        /**
         * The length of the extended attributes
         */
        public readonly int $extendedAttrLength,
        /**
         * The location of this "Path Table Record"
         */
        public readonly int $location,
        /**
         * The parent's directory number
         */
        public readonly int $parentDirNum,
        /**
         * The directory identifier.
         */
        public readonly string $dirIdentifier
    ) {
    }

    /**
     * Read a "Path Table Record" from the buffer, moving the offset after it
     *
     * @param array<int, int> $bytes
     *
     * @return self|null null at the end of the table (no data, a zero length identifier or a truncated record)
     */
    public static function read(array &$bytes, int &$offset, int $dirNum, bool $supplementary = false, bool $littleEndian = false): ?self
    {
        $offsetTmp = $offset;

        if (! isset($bytes[$offsetTmp])) {
            return null;
        }

        $dirIdLen = $bytes[$offsetTmp];
        $offsetTmp++;

        if ($dirIdLen === 0) {
            return null;
        }

        // the fixed part is 8 bytes, followed by the identifier
        if (! isset($bytes[$offsetTmp + 6 + $dirIdLen])) {
            return null;
        }

        $extendedAttrLength = $bytes[$offsetTmp];
        $offsetTmp++;
        if ($littleEndian) {
            $location = Buffer::readLSB($bytes, 4, $offsetTmp);
            $parentDirNum = Buffer::readLSB($bytes, 2, $offsetTmp);
        } else {
            $location = Buffer::readInt32($bytes, $offsetTmp);
            $parentDirNum = Buffer::readInt16($bytes, $offsetTmp);
        }

        $dirIdentifier = Buffer::readAString($bytes, $dirIdLen, $offsetTmp, $supplementary);

        if ($dirIdLen % 2 !== 0) {
            $offsetTmp++;
        }

        $offset = $offsetTmp;

        return new self($dirNum, $dirIdLen, $extendedAttrLength, $location, $parentDirNum, $dirIdentifier);
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
                return '/';
            }

            return '/' . $this->dirIdentifier . '/';
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

            $path = $used->dirIdentifier . '/' . $path;

            if ($used->parentDirNum === 1) {
                break;
            }

            $used = $pathTable[$used->parentDirNum] ?? throw new Exception('Missing parent directory in path table: ' . $used->parentDirNum);
        }

        return '/' . $path . '/';
    }
}
