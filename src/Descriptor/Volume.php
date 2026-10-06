<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use Carbon\Carbon;
use PhpIso\Descriptor;
use PhpIso\FileDirectory;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\PathTableRecord;
use PhpIso\Util\Buffer;
use PhpIso\Util\IsoDate;

abstract class Volume extends Descriptor
{
    public string $systemId;
    public string $volumeId;
    public int $volumeSpaceSize;
    public int $volumeSetSize;
    public int $volumeSeqNum;
    public int $blockSize;
    public int $pathTableSize;
    public int $lPathTablePos;
    public int $optLPathTablePos;
    public int $mPathTablePos;
    public int $optMPathTablePos;
    public FileDirectory $rootDirectory;
    public string $volumeSetId;
    public string $publisherId;
    public string $preparerId;
    public string $appId;
    public string $copyrightFileId;
    public string $abstractFileId;
    public string $bibliographicFileId;
    public ?Carbon $creationDate = null;
    public ?Carbon $modificationDate = null;
    public ?Carbon $expirationDate = null;
    public ?Carbon $effectiveDate = null;
    public int $fileStructureVersion;
    public int $jolietLevel = 0;

    public function init(IsoFile $isoFile, int &$offset): void
    {
        if ($this->bytes === null) {
            return;
        }

        // unused first entry
        $unused = $this->bytes[$offset];

        $offset++;

        $this->systemId = trim(Buffer::readAString($this->bytes, 32, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));
        $this->volumeId = trim(Buffer::readDString($this->bytes, 32, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));

        // unused
        $unused = Buffer::getBytes($this->bytes, 8, $offset);

        $this->volumeSpaceSize = Buffer::readBBO($this->bytes, 8, $offset);

        // joliet escape sequence
        $jolietEscapeSequence = Buffer::getRawBytes($this->bytes, 32, $offset);

        // Joliet Detection - If this is a Supplementary Volume Descriptor
        if ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC) {
            // Joliet escape sequences: %/@ (level 1), %/C (level 2), %/E (level 3)
            $jolietLevels = [
                1 => [0x25, 0x2F, 0x40],
                2 => [0x25, 0x2F, 0x43],
                3 => [0x25, 0x2F, 0x45],
            ];

            foreach ($jolietLevels as $level => $sequence) {
                if (array_slice($jolietEscapeSequence, 0, 3) === $sequence) {
                    $this->jolietLevel = $level;
                    break;
                }
            }
        }

        $this->volumeSetSize = Buffer::readBBO($this->bytes, 4, $offset);
        $this->volumeSeqNum = Buffer::readBBO($this->bytes, 4, $offset);
        $this->blockSize = Buffer::readBBO($this->bytes, 4, $offset);
        $this->pathTableSize = Buffer::readBBO($this->bytes, 8, $offset);

        $this->lPathTablePos = Buffer::readLSB($this->bytes, 4, $offset);
        $this->optLPathTablePos = Buffer::readLSB($this->bytes, 4, $offset);
        $this->mPathTablePos = Buffer::readMSB($this->bytes, 4, $offset);
        $this->optMPathTablePos = Buffer::readMSB($this->bytes, 4, $offset);

        $this->rootDirectory = new FileDirectory();
        $this->rootDirectory->jolietLevel = $this->jolietLevel;
        $this->rootDirectory->init($this->bytes, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));

        $this->volumeSetId = trim(Buffer::readDString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));
        $this->publisherId = trim(Buffer::readAString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));
        $this->preparerId = trim(Buffer::readAString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));
        $this->appId = trim(Buffer::readAString($this->bytes, 128, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));

        $this->copyrightFileId = trim(Buffer::readDString($this->bytes, 37, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));
        $this->abstractFileId = trim(Buffer::readDString($this->bytes, 37, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));

        $this->bibliographicFileId = trim(Buffer::readDString($this->bytes, 37, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC)));

        $this->creationDate = IsoDate::init17($this->bytes, $offset);

        $this->modificationDate = IsoDate::init17($this->bytes, $offset);

        $this->expirationDate = IsoDate::init17($this->bytes, $offset);

        $this->effectiveDate = IsoDate::init17($this->bytes, $offset);

        $this->fileStructureVersion = $this->bytes[$offset];
        $offset++;

        // free some space...
        $this->bytes = null;
        unset($unused);
    }

    /**
     * Walk the whole directory tree of the volume (depth first), without needing the path table
     *
     * Entries are untrusted: names are not sanitized here, see Util\SafePath before using them on disk.
     *
     * @return \Generator<int, IsoEntry>
     */
    public function walk(IsoFile $isoFile, int $maxDepth = 64): \Generator
    {
        if ($this->blockSize <= 0) {
            return;
        }

        $supplementary = ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC);
        $visited = [$this->rootDirectory->location => true];

        // explicit stack instead of recursion: a hostile image cannot exhaust the PHP stack
        /** @var list<array{string, int, int, int}> $stack path, location, length, depth */
        $stack = [['', $this->rootDirectory->location, $this->rootDirectory->dataLength, 0]];

        while ($stack !== []) {
            [$base, $location, $length, $depth] = array_pop($stack);

            $records = FileDirectory::loadExtentsSt($isoFile, $this->blockSize, $location, $supplementary, $this->jolietLevel, $length);
            if ($records === false) {
                continue;
            }

            $subDirectories = [];
            foreach ($records as $record) {
                if ($record->isThis() || $record->isParent()) {
                    continue;
                }

                $path = $base . '/' . $record->fileId;

                yield new IsoEntry($path, $record->fileId, $record->isDirectory(), $record->dataLength, $record->location, $record->recordingDate, $record->isHidden());

                if ($record->isDirectory() && $depth < $maxDepth && ! isset($visited[$record->location])) {
                    $visited[$record->location] = true;
                    $subDirectories[] = [$path, $record->location, $record->dataLength, $depth + 1];
                }
            }

            // keep alphabetical-ish disk order by pushing in reverse
            foreach (array_reverse($subDirectories) as $sub) {
                $stack[] = $sub;
            }
        }
    }

    /**
     * Load the path table
     *
     * @return array<int, PathTableRecord>|null
     */
    public function loadTable(IsoFile $isoFile): ?array
    {
        if ($this->isMPathTable()) {
            return $this->loadMPathTable($isoFile);
        }

        // fall back to the little endian table
        if ($this->isLPathTable()) {
            return $this->loadLPathTable($isoFile);
        }

        // unknown path table
        return null;
    }

    /**
     * Tell if a "M Path Table" is present
     */
    public function isMPathTable(): bool
    {
        return $this->mPathTablePos !== 0;
    }

    /**
     * Tell if a "L Path Table" is present
     */
    public function isLPathTable(): bool
    {
        return $this->lPathTablePos !== 0;
    }

    /**
     * Load the "M Path Table"
     *
     * @return array<int, PathTableRecord>|null
     */
    public function loadMPathTable(IsoFile $isoFile): ?array
    {
        return $this->loadGenPathTable($isoFile, $this->mPathTablePos, false);
    }

    /**
     * Load the "L Path Table"
     *
     * @return array<int, PathTableRecord>|null
     */
    public function loadLPathTable(IsoFile $isoFile): ?array
    {
        return $this->loadGenPathTable($isoFile, $this->lPathTablePos, true);
    }

    /**
     * Load the "L Path Table" or "M Path Table"
     *
     * @return array<int, PathTableRecord>|null
     */
    protected function loadGenPathTable(IsoFile $isoFile, int $pathTablePos, bool $littleEndian = false): ?array
    {
        if ($pathTablePos === 0 || $this->blockSize <= 0 || $this->pathTableSize <= 0) {
            return null;
        }

        // a hostile size must not drive huge allocations
        $fileSize = $isoFile->getSize();
        if ($fileSize > 0 && ($this->pathTableSize > $fileSize || $pathTablePos * $this->blockSize >= $fileSize)) {
            return null;
        }

        if ($isoFile->seek($pathTablePos * $this->blockSize, SEEK_SET) === -1) {
            return null;
        }

        $pathTableSize = Buffer::align($this->pathTableSize, $this->blockSize);

        $string = $isoFile->read($fileSize > 0 ? min($pathTableSize, $fileSize - $pathTablePos * $this->blockSize) : $pathTableSize);

        if ($string === false) {
            return null;
        }

        /** @var array<int, int>|false $bytes */
        $bytes = unpack('C*', $string);

        if ($bytes === false) {
            return null;
        }

        $pathTable = [];

        $offset = 1;
        $dirNum = 1;
        $ptRec = new PathTableRecord();
        $supplementary = ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC);
        $bres = $ptRec->init($bytes, $offset, $supplementary, $littleEndian);
        while ($bres === true) {
            $ptRec->setDirectoryNumber($dirNum);

            $pathTable[$dirNum] = $ptRec;
            $dirNum++;

            $ptRec = new PathTableRecord();
            $bres = $ptRec->init($bytes, $offset, $supplementary, $littleEndian);
        }

        return $pathTable;
    }
}
