<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use Carbon\Carbon;
use PhpIso\Descriptor;
use PhpIso\FileDirectory;
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
        $jolietEscapeSequence = Buffer::getBytes($this->bytes, 32, $offset);

        // Joliet Detection - If this is a Supplementary Volume Descriptor
        if ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC) {
            // Define Joliet escape sequences as their byte values
            $jolietLevels = [
                1 => '374764', // %/@ in byte format
                2 => '374767', // %/C in byte format
                3 => '374769', // %/E in byte format
            ];

            // Check for Joliet escape sequences indicating Unicode support
            foreach ($jolietLevels as $level => $sequence) {
                if (str_contains($jolietEscapeSequence, $sequence)) {
                    $this->jolietLevel = $level; // Record the Joliet level if found
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
        unset($this->bytes);
        unset($unused);
    }

    /**
     * Load the path table
     *
     * @return array<int, PathTableRecord>|null
     */
    public function loadTable(IsoFile $isoFile): ?array
    {
        // only M-Path should be used for amd64 platforms
        if ($this->isMPathTable()) {
            return $this->loadMPathTable($isoFile);
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
        return $this->loadGenPathTable($isoFile, $this->mPathTablePos);
    }

    /**
     * Load the "L Path Table"
     *
     * @return array<int, PathTableRecord>|null
     */
    public function loadLPathTable(IsoFile $isoFile): ?array
    {
        return $this->loadGenPathTable($isoFile, $this->lPathTablePos);
    }

    /**
     * Load the "L Path Table" or "M Path Table"
     *
     * @return array<int, PathTableRecord>|null
     */
    protected function loadGenPathTable(IsoFile $isoFile, int $pathTablePos): ?array
    {
        if ($pathTablePos === 0 || $this->blockSize === 0) {
            return null;
        }

        if ($isoFile->seek($pathTablePos * $this->blockSize, SEEK_SET) === -1) {
            return null;
        }

        $pathTableSize = Buffer::align($this->pathTableSize, $this->blockSize);

        $string = $isoFile->read($pathTableSize);

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
        $bres = $ptRec->init($bytes, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        while ($bres === true) {
            $ptRec->setDirectoryNumber($dirNum);
            $ptRec->loadExtents($isoFile, $this->blockSize, true);

            $pathTable[$dirNum] = $ptRec;
            $dirNum++;

            $ptRec = new PathTableRecord();
            $bres = $ptRec->init($bytes, $offset, ($this->type === Type::SUPPLEMENTARY_VOLUME_DESC));
        }

        return $pathTable;
    }
}
