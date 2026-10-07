<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use Carbon\CarbonImmutable;
use PhpIso\Descriptor;
use PhpIso\BrowsesEntries;
use PhpIso\Exception;
use PhpIso\FileSystem;
use PhpIso\FileDirectory;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\PathTableRecord;
use PhpIso\RockRidgeInfo;
use PhpIso\WalkWarnings;
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
    public readonly ?CarbonImmutable $creationDate;
    public readonly ?CarbonImmutable $modificationDate;
    public readonly ?CarbonImmutable $expirationDate;
    public readonly ?CarbonImmutable $effectiveDate;
    public readonly int $fileStructureVersion;
    public readonly int $jolietLevel;

    /**
     * @param array<int, int> $bytes the descriptor sector
     * @param int $offset position after the descriptor header, moved after the parsed fields
     */
    public function __construct(string $stdId, int $version, array $bytes, int &$offset)
    {
        parent::__construct($stdId, $version);

        // only Joliet volumes use UCS-2 strings: enhanced volume descriptors and plain supplementary ones are 8 bit.
        // The escape sequences come after the names, so they are peeked at their fixed place first
        // (1 unused byte, 32 system id, 32 volume id, 8 unused, 8 space size)
        $escapeOffset = $offset + 1 + 32 + 32 + 8 + 8;
        $supplementary = static::TYPE === Type::SUPPLEMENTARY_VOLUME_DESC
            && self::detectJolietLevel(array_slice($bytes, $escapeOffset - 1, 3)) > 0;

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
        $jolietLevel = static::TYPE === Type::SUPPLEMENTARY_VOLUME_DESC ? self::detectJolietLevel($jolietEscapeSequence) : 0;

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
     * Joliet level (1 to 3) announced by an escape sequence, 0 when it is not a Joliet one
     *
     * @param array<int, int> $escapeSequence at least the first 3 bytes of the escape sequences field
     */
    private static function detectJolietLevel(array $escapeSequence): int
    {
        // Joliet escape sequences: %/@ (level 1), %/C (level 2), %/E (level 3)
        $jolietLevels = [
            1 => [0x25, 0x2F, 0x40],
            2 => [0x25, 0x2F, 0x43],
            3 => [0x25, 0x2F, 0x45],
        ];

        foreach ($jolietLevels as $level => $sequence) {
            if (array_slice($escapeSequence, 0, 3) === $sequence) {
                return $level;
            }
        }

        return 0;
    }

    /**
     * Walk the whole directory tree of the volume (depth first), without needing the path table
     *
     * Entries are untrusted: names are not sanitized here, see Util\SafePath before using them on disk.
     *
     * On a primary volume the Rock Ridge extensions (long POSIX names, mode, owner, symbolic links, relocated
     * directories) are applied unless $rockRidge is false.
     *
     * Directories that cannot be listed completely (depth limit, unreadable, truncated or corrupt) are recorded in
     * $warnings, or make a strict WalkWarnings throw.
     *
     * @return \Generator<int, IsoEntry>
     */
    public function walk(IsoFile $isoFile, int $maxDepth = 64, ?WalkWarnings $warnings = null, bool $rockRidge = true): \Generator
    {
        if ($this->blockSize <= 0) {
            return;
        }

        $visited = [$this->rootDirectory->location => true];
        $skip = 0;

        // explicit stack instead of recursion: a hostile image cannot exhaust the PHP stack
        /** @var list<array{string, int, int|null, int}> $stack path, location, length (null: read it from the directory), depth */
        $stack = [['', $this->rootDirectory->location, $this->rootDirectory->dataLength, 0]];

        while ($stack !== []) {
            [$base, $location, $length, $depth] = array_pop($stack);

            $subDirectories = [];
            foreach ($this->directoryEntries($isoFile, $base, $location, $length, $rockRidge, $depth === 0 && $base === '', $skip, $warnings) as [$entry, $subLocation, $subLength]) {
                yield $entry;

                if ($subLocation === null || isset($visited[$subLocation])) {
                    continue;
                }

                if ($depth >= $maxDepth) {
                    $warnings?->add('depth limit (' . $maxDepth . ') reached, not listing ' . $entry->path);
                    continue;
                }

                $visited[$subLocation] = true;
                $subDirectories[] = [$entry->path, $subLocation, $subLength, $depth + 1];
            }

            // keep alphabetical-ish disk order by pushing in reverse
            foreach (array_reverse($subDirectories) as $sub) {
                $stack[] = $sub;
            }
        }
    }

    /**
     * List the direct children of one directory (the root directory when $directory is null)
     *
     * Same entries as walk() reports for that directory (Rock Ridge names, multi-extent files, ...).
     *
     * @return \Generator<int, IsoEntry>
     */
    public function listDirectory(IsoFile $isoFile, ?IsoEntry $directory = null, ?WalkWarnings $warnings = null, bool $rockRidge = true): \Generator
    {
        if ($this->blockSize <= 0) {
            return;
        }

        if (! $directory instanceof IsoEntry) {
            $skip = 0;
            foreach ($this->directoryEntries($isoFile, '', $this->rootDirectory->location, $this->rootDirectory->dataLength, $rockRidge, true, $skip, $warnings) as [$entry]) {
                yield $entry;
            }

            return;
        }

        if (! $directory->isDirectory) {
            return;
        }

        // the SP entry lives in the root directory only
        $skip = 0;
        if ($rockRidge) {
            foreach ($this->directoryEntries($isoFile, '', $this->rootDirectory->location, $this->rootDirectory->dataLength, true, true, $skip) as $ignored) {
                break;
            }
        }

        // a directory reached through a Rock Ridge child link has no length of its own (size 0)
        $length = $directory->size > 0 ? $directory->size : null;
        foreach ($this->directoryEntries($isoFile, $directory->path, $directory->location, $length, $rockRidge, false, $skip, $warnings) as [$entry]) {
            yield $entry;
        }
    }

    /**
     * The absolute byte ranges (offset, length) holding the data of a file entry
     *
     * @return list<array{int, int}>
     */
    public function getEntryRanges(IsoFile $isoFile, IsoEntry $entry): array
    {
        return array_map(fn (array $extent): array => [$extent[0] * $this->blockSize, $extent[1]], $entry->getExtents());
    }

    /**
     * The entries of a single directory
     *
     * @param int|null $length size of the directory, null to read it from the directory itself
     * @param bool $isRoot the directory is the root one: its first record tells how many bytes to skip in every system use area
     * @param int $skip system use skip length (SP entry), updated when $isRoot
     * @param WalkWarnings|null $warnings receives the problems met while reading the directory
     *
     * @return \Generator<int, array{IsoEntry, int|null, int|null}> the entry, and for a directory its location and length
     */
    private function directoryEntries(IsoFile $isoFile, string $base, int $location, ?int $length, bool $rockRidge, bool $isRoot, int &$skip, ?WalkWarnings $warnings = null): \Generator
    {
        $supplementary = $this->jolietLevel > 0;
        $records = FileDirectory::loadExtentsSt($isoFile, $this->blockSize, $location, $supplementary, $this->jolietLevel, $length, $warnings, $base);
        if ($records === false) {
            return;
        }

        $pending = null;
        foreach ($records as $record) {
            // the SP entry of the first record of the root directory tells how many bytes to skip in every system use area
            if ($isRoot && $record->isThis()) {
                $skip = RockRidge::detectSkip($record->systemUse) ?? 0;
            }

            if ($record->isThis() || $record->isParent()) {
                continue;
            }

            // associated files (e.g. resource forks) share the name of their data file
            if ($record->isAssociated()) {
                continue;
            }

            $rr = ($supplementary || ! $rockRidge) ? null : RockRidge::parse($record->systemUse, $isoFile, $this->blockSize, $skip);

            // the real directory is listed through its "child link" placeholder
            if ($rr instanceof RockRidgeInfo && $rr->relocated) {
                continue;
            }

            $rrName = $rr instanceof RockRidgeInfo ? $rr->name : null;
            $name = ($rrName !== null && $rrName !== '') ? $rrName : $record->fileId;
            $path = $base . '/' . $name;

            // a multi-extent file is stored as several records (all but the last flagged), report it once
            if (! $record->isDirectory() && ($record->isMultiExtent() || $pending !== null)) {
                if ($pending !== null && $pending->name !== $name) {
                    yield [$pending, null, null];
                    $pending = null;
                }

                $pending = $this->appendExtent($pending, $path, $record, $name, $rr);

                if (! $record->isMultiExtent()) {
                    yield [$pending, null, null];
                    $pending = null;
                }

                continue;
            }

            $link = $rr?->childLocation;
            $isDirectory = $record->isDirectory() || $link !== null;
            $entryLocation = $link ?? $record->location;
            // the data of a file starts after its extended attribute record
            $dataLocation = $isDirectory ? $entryLocation : $this->dataLocation($record);

            $entry = new IsoEntry($path, $name, $isDirectory, $link === null ? $record->dataLength : 0, $dataLocation, $record->recordingDate, $record->isHidden(), [], $rr, null, $rr?->uid, $rr?->gid, $rr?->mode);

            yield [$entry, $isDirectory ? $entryLocation : null, $link === null ? $record->dataLength : null];
        }

        if ($pending !== null) {
            yield [$pending, null, null];
        }
    }

    /**
     * Add the extent of a record to the multi-extent entry being built (a new entry when there is none yet)
     */
    protected function appendExtent(?IsoEntry $pending, string $path, FileDirectory $record, ?string $name = null, ?RockRidgeInfo $rr = null): IsoEntry
    {
        $location = $this->dataLocation($record);

        if (! $pending instanceof IsoEntry) {
            return new IsoEntry($path, $name ?? $record->fileId, false, $record->dataLength, $location, $record->recordingDate, $record->isHidden(), [[$location, $record->dataLength]], $rr, null, $rr?->uid, $rr?->gid, $rr?->mode);
        }

        $extents = $pending->extents;
        $extents[] = [$location, $record->dataLength];

        return new IsoEntry($pending->path, $pending->name, false, $pending->size + $record->dataLength, $pending->location, $pending->recordingDate, $pending->isHidden, $extents, $pending->rockRidge ?? $rr, null, $pending->uid ?? $rr?->uid, $pending->gid ?? $rr?->gid, $pending->mode ?? $rr?->mode);
    }

    /**
     * Block where the data of a file record starts: the extended attribute record comes first
     */
    private function dataLocation(FileDirectory $record): int
    {
        return $record->location + $record->extendedAttrRecordLength;
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
        $supplementary = $this->jolietLevel > 0;
        while (($ptRec = PathTableRecord::read($bytes, $offset, $dirNum, $supplementary, $littleEndian)) instanceof PathTableRecord) {
            $pathTable[$dirNum] = $ptRec;
            $dirNum++;
        }

        return $pathTable;
    }
}
