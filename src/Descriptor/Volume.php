<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use Carbon\Carbon;
use PhpIso\Descriptor;
use PhpIso\BrowsesEntries;
use PhpIso\Exception;
use PhpIso\FileSystem;
use PhpIso\FileDirectory;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\PathTableRecord;
use PhpIso\RockRidgeInfo;
use PhpIso\Util\Buffer;
use PhpIso\Util\IsoDate;

abstract class Volume extends Descriptor implements FileSystem
{
    use BrowsesEntries;

    public readonly string $systemId;
    public readonly string $volumeId;
    public readonly int $volumeSpaceSize;
    public readonly int $volumeSetSize;
    public readonly int $volumeSeqNum;
    public readonly int $blockSize;
    public readonly int $pathTableSize;
    public readonly int $lPathTablePos;
    public readonly int $optLPathTablePos;
    public readonly int $mPathTablePos;
    public readonly int $optMPathTablePos;
    public readonly FileDirectory $rootDirectory;
    public readonly string $volumeSetId;
    public readonly string $publisherId;
    public readonly string $preparerId;
    public readonly string $appId;
    public readonly string $copyrightFileId;
    public readonly string $abstractFileId;
    public readonly string $bibliographicFileId;
    public readonly ?Carbon $creationDate;
    public readonly ?Carbon $modificationDate;
    public readonly ?Carbon $expirationDate;
    public readonly ?Carbon $effectiveDate;
    public readonly int $fileStructureVersion;
    public readonly int $jolietLevel;

    /**
     * @param array<int, int> $bytes the descriptor sector
     * @param int $offset position after the descriptor header, moved after the parsed fields
     */
    public function __construct(string $stdId, int $version, array $bytes, int &$offset)
    {
        parent::__construct($stdId, $version);

        $supplementary = (static::TYPE === Type::SUPPLEMENTARY_VOLUME_DESC);

        // unused first entry
        Buffer::getRawBytes($bytes, 1, $offset);

        $this->systemId = trim(Buffer::readAString($bytes, 32, $offset, $supplementary, true));
        $this->volumeId = trim(Buffer::readDString($bytes, 32, $offset, $supplementary, true));

        // unused
        Buffer::getRawBytes($bytes, 8, $offset);

        $this->volumeSpaceSize = Buffer::readBBO($bytes, 8, $offset);

        // joliet escape sequence
        $jolietEscapeSequence = Buffer::getRawBytes($bytes, 32, $offset);

        // Joliet Detection - If this is a Supplementary Volume Descriptor
        $jolietLevel = 0;
        if (static::TYPE === Type::SUPPLEMENTARY_VOLUME_DESC) {
            // Joliet escape sequences: %/@ (level 1), %/C (level 2), %/E (level 3)
            $jolietLevels = [
                1 => [0x25, 0x2F, 0x40],
                2 => [0x25, 0x2F, 0x43],
                3 => [0x25, 0x2F, 0x45],
            ];

            foreach ($jolietLevels as $level => $sequence) {
                if (array_slice($jolietEscapeSequence, 0, 3) === $sequence) {
                    $jolietLevel = $level;
                    break;
                }
            }
        }

        $this->jolietLevel = $jolietLevel;

        $this->volumeSetSize = Buffer::readBBO($bytes, 4, $offset);
        $this->volumeSeqNum = Buffer::readBBO($bytes, 4, $offset);
        $this->blockSize = Buffer::readBBO($bytes, 4, $offset);
        $this->pathTableSize = Buffer::readBBO($bytes, 8, $offset);

        $this->lPathTablePos = Buffer::readLSB($bytes, 4, $offset);
        $this->optLPathTablePos = Buffer::readLSB($bytes, 4, $offset);
        $this->mPathTablePos = Buffer::readMSB($bytes, 4, $offset);
        $this->optMPathTablePos = Buffer::readMSB($bytes, 4, $offset);

        $this->rootDirectory = FileDirectory::read($bytes, $offset, $supplementary, $jolietLevel) ?? throw new Exception('Missing root directory record in the volume descriptor');

        $this->volumeSetId = trim(Buffer::readDString($bytes, 128, $offset, $supplementary, true));
        $this->publisherId = trim(Buffer::readAString($bytes, 128, $offset, $supplementary, true));
        $this->preparerId = trim(Buffer::readAString($bytes, 128, $offset, $supplementary, true));
        $this->appId = trim(Buffer::readAString($bytes, 128, $offset, $supplementary, true));

        $this->copyrightFileId = trim(Buffer::readDString($bytes, 37, $offset, $supplementary, true));
        $this->abstractFileId = trim(Buffer::readDString($bytes, 37, $offset, $supplementary, true));

        $this->bibliographicFileId = trim(Buffer::readDString($bytes, 37, $offset, $supplementary, true));

        $this->creationDate = IsoDate::init17($bytes, $offset);

        $this->modificationDate = IsoDate::init17($bytes, $offset);

        $this->expirationDate = IsoDate::init17($bytes, $offset);

        $this->effectiveDate = IsoDate::init17($bytes, $offset);

        $this->fileStructureVersion = $bytes[$offset];
        $offset++;
    }

    /**
     * Walk the whole directory tree of the volume (depth first), without needing the path table
     *
     * Entries are untrusted: names are not sanitized here, see Util\SafePath before using them on disk.
     *
     * On a primary volume the Rock Ridge extensions (long POSIX names, mode, owner, symbolic links, relocated
     * directories) are applied unless $rockRidge is false.
     *
     * @return \Generator<int, IsoEntry>
     */
    public function walk(IsoFile $isoFile, int $maxDepth = 64, bool $rockRidge = true): \Generator
    {
        if ($this->blockSize <= 0) {
            return;
        }

        $supplementary = (static::TYPE === Type::SUPPLEMENTARY_VOLUME_DESC);
        $visited = [$this->rootDirectory->location => true];

        // explicit stack instead of recursion: a hostile image cannot exhaust the PHP stack
        /** @var list<array{string, int, int|null, int}> $stack path, location, length (null: read it from the directory), depth */
        $stack = [['', $this->rootDirectory->location, $this->rootDirectory->dataLength, 0]];

        while ($stack !== []) {
            [$base, $location, $length, $depth] = array_pop($stack);

            $records = FileDirectory::loadExtentsSt($isoFile, $this->blockSize, $location, $supplementary, $this->jolietLevel, $length);
            if ($records === false) {
                continue;
            }

            $subDirectories = [];
            $pending = null;
            foreach ($records as $record) {
                if ($record->isThis() || $record->isParent()) {
                    continue;
                }

                $rr = ($supplementary || ! $rockRidge) ? null : RockRidge::parse($record->systemUse, $isoFile, $this->blockSize);

                // the real directory is listed through its "child link" placeholder
                if ($rr instanceof RockRidgeInfo && $rr->relocated) {
                    continue;
                }

                $rrName = $rr instanceof RockRidgeInfo ? $rr->name : null;
                $name = ($rrName !== null && $rrName !== '') ? $rrName : $record->fileId;
                $path = $base . '/' . $name;

                // a multi-extent file is stored as several records (all but the last flagged), report it once
                if (! $record->isDirectory() && ($record->isMultiExtent() || $pending !== null)) {
                    if ($pending !== null && $pending->name !== $record->fileId) {
                        yield $pending;
                        $pending = null;
                    }

                    $pending = $this->appendExtent($pending, $path, $record);

                    if (! $record->isMultiExtent()) {
                        yield $pending;
                        $pending = null;
                    }

                    continue;
                }

                $link = $rr?->childLocation;
                $isDirectory = $record->isDirectory() || $link !== null;
                $location = $link ?? $record->location;

                yield new IsoEntry($path, $name, $isDirectory, $link === null ? $record->dataLength : 0, $location, $record->recordingDate, $record->isHidden(), [], $rr);

                if ($isDirectory && $depth < $maxDepth && ! isset($visited[$location])) {
                    $visited[$location] = true;
                    $subDirectories[] = [$path, $location, $link === null ? $record->dataLength : null, $depth + 1];
                }
            }

            if ($pending !== null) {
                yield $pending;
            }

            // keep alphabetical-ish disk order by pushing in reverse
            foreach (array_reverse($subDirectories) as $sub) {
                $stack[] = $sub;
            }
        }
    }

    /**
     * Add the extent of a record to the multi-extent entry being built (a new entry when there is none yet)
     */
    protected function appendExtent(?IsoEntry $pending, string $path, FileDirectory $record): IsoEntry
    {
        if (! $pending instanceof IsoEntry) {
            return new IsoEntry($path, $record->fileId, false, $record->dataLength, $record->location, $record->recordingDate, $record->isHidden(), [[$record->location, $record->dataLength]]);
        }

        $extents = $pending->extents;
        $extents[] = [$record->location, $record->dataLength];

        return new IsoEntry($pending->path, $pending->name, false, $pending->size + $record->dataLength, $pending->location, $pending->recordingDate, $pending->isHidden, $extents);
    }

    /**
     * Copy the content of a file entry (all of its extents) to an open stream
     *
     * @param resource $output
     *
     * @throws Exception
     */
    public function copyEntryTo(IsoFile $isoFile, IsoEntry $entry, mixed $output): void
    {
        if ($entry->isDirectory) {
            throw new Exception('Cannot read the content of a directory: ' . $entry->path);
        }

        foreach ($entry->getExtents() as [$location, $size]) {
            $isoFile->copyRange($location * $this->blockSize, $size, $output);
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
        $supplementary = (static::TYPE === Type::SUPPLEMENTARY_VOLUME_DESC);
        while (($ptRec = PathTableRecord::read($bytes, $offset, $dirNum, $supplementary, $littleEndian)) instanceof PathTableRecord) {
            $pathTable[$dirNum] = $ptRec;
            $dirNum++;
        }

        return $pathTable;
    }
}
