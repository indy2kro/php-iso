<?php

declare(strict_types=1);

namespace PhpIso\Cli;

use Generator;
use PhpIso\BrowsesEntries;
use PhpIso\Descriptor;
use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\SupplementaryVolume;
use PhpIso\Descriptor\Volume;
use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Udf\UdfFileSystem;
use Throwable;

class IsoTool
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 1;
    public const EXIT_INVALID_FILE = 2;
    public const EXIT_ERROR = 3;

    private const array VOLUMES = ['primary', 'joliet', 'udf'];

    private string $volumeName = '';

    private bool $rockRidge = true;

    /**
     * @param resource|null $input stream read when the file is "-" (defaults to the standard input)
     */
    public function __construct(private readonly mixed $input = null)
    {
    }

    /**
     * @param array<int, string>|null $argv defaults to the process arguments
     *
     * @return int the exit code
     */
    public function run(?array $argv = null): int
    {
        try {
            $options = $this->parseCliArgs($argv);
        } catch (Exception $ex) {
            $this->displayError($ex->getMessage());
            return self::EXIT_USAGE;
        }

        if (isset($options['h']) || isset($options['help'])) {
            $this->displayHelp();
            return self::EXIT_OK;
        }

        if ($options === []) {
            $this->displayHelp();
            return self::EXIT_USAGE;
        }

        if (! isset($options['file']) && ! isset($options['f'])) {
            $this->displayError('Missing --file option');
            return self::EXIT_USAGE;
        }

        $file = $this->firstString($options['file'] ?? $options['f'] ?? null);

        if ($file === '') {
            $this->displayError('Invalid value for file received');
            return self::EXIT_INVALID_FILE;
        }

        $extractPath = $this->firstString($options['extract'] ?? $options['x'] ?? null);
        $catPath = $this->firstString($options['cat'] ?? $options['c'] ?? null);
        $find = $this->firstString($options['find'] ?? null);
        $bootPath = $this->firstString($options['extract-boot'] ?? null);
        $volumeName = $this->firstString($options['volume'] ?? null);

        // options taking a value: long name => [value, short name]
        $valued = ['extract' => [$extractPath, 'x'], 'cat' => [$catPath, 'c'], 'find' => [$find, 'find'], 'extract-boot' => [$bootPath, 'extract-boot'], 'volume' => [$volumeName, 'volume']];
        foreach ($valued as $name => [$value, $short]) {
            if ((isset($options[$name]) || isset($options[$short])) && $value === '') {
                $this->displayError('The ' . $name . ' option requires a value');
                return self::EXIT_USAGE;
            }
        }

        $json = isset($options['json']) || isset($options['j']);
        $list = isset($options['list']) || isset($options['l']);
        $ndjson = isset($options['ndjson']);

        $actions = array_keys(array_filter([
            'extract' => $extractPath !== '',
            'cat' => $catPath !== '',
            'find' => $find !== '',
            'extract-boot' => $bootPath !== '',
            'list' => $list,
            'json' => $json,
        ]));
        if (count($actions) > 1) {
            $this->displayError('Only one action can be used at a time, got: --' . implode(', --', $actions));
            return self::EXIT_USAGE;
        }

        if ($ndjson && ! $list) {
            $this->displayError('The ndjson option requires --list');
            return self::EXIT_USAGE;
        }

        if ($volumeName !== '' && ! in_array($volumeName, self::VOLUMES, true)) {
            $this->displayError('Unknown volume "' . $volumeName . '", expected one of: ' . implode(', ', self::VOLUMES));
            return self::EXIT_USAGE;
        }

        $this->volumeName = $volumeName;
        $this->rockRidge = ! isset($options['no-rock-ridge']);

        try {
            $this->checkIsoFile($file);

            if ($extractPath !== '') {
                $this->extractAction($file, $extractPath);
            } elseif ($catPath !== '') {
                $this->catAction($file, $catPath);
            } elseif ($find !== '') {
                $this->findAction($file, $find);
            } elseif ($bootPath !== '') {
                $this->extractBootAction($file, $bootPath);
            } elseif ($json) {
                $this->jsonAction($file);
            } elseif ($list) {
                $this->listAction($file, $ndjson);
            } else {
                echo 'Input ISO file: ' . $file . PHP_EOL;
                $this->infoAction($file, isset($options['files']));
            }
        } catch (Throwable $ex) {
            $this->displayError($ex->getMessage());
            return self::EXIT_ERROR;
        }

        return self::EXIT_OK;
    }

    /**
     * "-" reads the image from the standard input
     */
    protected function openIso(string $file): IsoFile
    {
        return $file === '-' ? IsoFile::fromStream($this->input ?? STDIN) : new IsoFile($file);
    }

    protected function checkIsoFile(string $file): void
    {
        if ($file === '-') {
            return;
        }

        if (! file_exists($file)) {
            throw new Exception('ISO file does not exist.');
        }

        if (! is_file($file)) {
            throw new Exception('Path is not a valid file.');
        }
    }

    protected function infoAction(string $file, bool $files = false): void
    {
        $isoFile = $this->openIso($file);

        echo PHP_EOL;

        echo 'Number of descriptors: ' . count($isoFile->descriptors) . PHP_EOL;

        /** @var Descriptor $descriptor */
        foreach ($isoFile->descriptors as $descriptor) {
            echo '  - ' . $descriptor->name . PHP_EOL;

            if ($descriptor instanceof Volume) {
                $this->infoVolume($descriptor);
                if ($files) {
                    $this->displayFiles($descriptor, $isoFile);
                }
            } elseif ($descriptor instanceof Boot) {
                $this->infoBoot($descriptor, $isoFile);
            }

            echo PHP_EOL;
        }

        $udf = $this->udf($isoFile);
        if ($udf instanceof UdfFileSystem) {
            echo '  - UDF file system' . PHP_EOL;
            echo '   - Volume ID: ' . $udf->volumeId . PHP_EOL;
            if ($files) {
                $this->displayFiles($udf, $isoFile);
            }
            echo PHP_EOL;
        }
    }

    protected function listAction(string $file, bool $ndjson = false): void
    {
        $isoFile = $this->openIso($file);
        $volume = $this->requireVolume($isoFile);

        foreach ($volume->walk($isoFile) as $entry) {
            if ($ndjson) {
                echo json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            } elseif ($entry->isDirectory) {
                echo $entry->path . '/' . PHP_EOL;
            } else {
                echo $entry->path . "	" . $entry->size . ($entry->isSymlink() ? "	-> " . $entry->rockRidge?->symlink : "") . PHP_EOL;
            }
        }
    }

    protected function catAction(string $file, string $path): void
    {
        $isoFile = $this->openIso($file);
        $volume = $this->requireVolume($isoFile);

        $entry = $volume->find($isoFile, $path);
        if (! $entry instanceof IsoEntry) {
            throw new Exception('File not found in the ISO: ' . $path);
        }

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new Exception('Cannot open the standard output');
        }

        $volume->copyEntryTo($isoFile, $entry, $output);
        fclose($output);
    }

    protected function findAction(string $file, string $pattern): void
    {
        $isoFile = $this->openIso($file);
        $volume = $this->requireVolume($isoFile);

        foreach ($volume->search($isoFile, $pattern) as $entry) {
            echo $entry->path . ($entry->isDirectory ? '/' : "\t" . $entry->size) . PHP_EOL;
        }
    }

    protected function jsonAction(string $file): void
    {
        $isoFile = $this->openIso($file);

        $descriptors = [];
        foreach ($isoFile->descriptors as $descriptor) {
            $item = ['type' => $descriptor->getType(), 'name' => $descriptor->name];

            if ($descriptor instanceof Volume) {
                $item += $this->volumeToArray($descriptor);
                $item['files'] = array_map(
                    static fn (IsoEntry $entry): array => $entry->toArray(),
                    iterator_to_array($descriptor->walk($isoFile), false)
                );
            } elseif ($descriptor instanceof Boot) {
                $item += ['bootSystemId' => $descriptor->bootSysId, 'bootId' => $descriptor->bootId, 'bootCatalogLocation' => $descriptor->bootCatalogLocation];
                $catalog = $this->loadCatalog($descriptor, $isoFile);
                if ($catalog !== null) {
                    $item['bootCatalog'] = $this->catalogToArray($catalog);
                }
            }

            $descriptors[] = $item;
        }

        $result = ['file' => $file, 'descriptors' => $descriptors];

        $udf = $this->udf($isoFile);
        if ($udf instanceof UdfFileSystem) {
            $result['udf'] = [
                'volumeId' => $udf->volumeId,
                'files' => array_map(static fn (IsoEntry $entry): array => $entry->toArray(), iterator_to_array($udf->walk($isoFile), false)),
            ];
        }

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    protected function extractAction(string $file, string $extractPath): void
    {
        $isoFile = $this->openIso($file);
        $volume = $this->requireVolume($isoFile);

        echo 'Input ISO file: ' . $file . PHP_EOL;
        echo 'Extract ISO file to: ' . $extractPath . PHP_EOL;

        $count = (new Extractor())->extract($isoFile, $volume, $extractPath, static function (IsoEntry $entry): void {
            echo $entry->path . ' (location: ' . $entry->location . ') (length: ' . $entry->size . ')' . PHP_EOL;
        });

        echo 'Extract finished! (' . $count . ' files)' . PHP_EOL;
    }

    protected function extractBootAction(string $file, string $destination): void
    {
        $isoFile = $this->openIso($file);

        $boot = $isoFile->getBootRecord() ?? throw new Exception('No boot record found in the ISO file.');
        $catalog = $boot->loadCatalog($isoFile) ?? throw new Exception('The boot record is not an El Torito boot record.');
        $entry = $catalog->getDefaultEntry() ?? throw new Exception('The boot catalog has no boot entry.');

        $catalog->extractImage($isoFile, $entry, $destination);

        echo 'Boot image written to: ' . $destination . ' (' . $entry->getImageSize() . ' bytes)' . PHP_EOL;
    }

    protected function requireVolume(IsoFile $isoFile): FileSystem
    {
        $volume = match ($this->volumeName) {
            'primary' => $isoFile->getPrimaryVolume(),
            'joliet' => $isoFile->getSupplementaryVolume(),
            'udf' => $isoFile->getUdfFileSystem(),
            default => $isoFile->getFileSystem(),
        };

        if ($volume === null) {
            throw new Exception($this->volumeName === ''
                ? 'No supported file system found in the ISO file.'
                : 'The ' . $this->volumeName . ' volume was not found in the ISO file.');
        }

        if (! $this->rockRidge && $volume instanceof Volume) {
            return $this->withoutRockRidge($volume);
        }

        return $volume;
    }

    /**
     * A view of the volume ignoring the Rock Ridge extensions (plain ISO 9660 / Joliet names)
     */
    private function withoutRockRidge(Volume $volume): FileSystem
    {
        return new readonly class ($volume) implements FileSystem {
            use BrowsesEntries;

            public function __construct(private Volume $volume)
            {
            }

            public function walk(IsoFile $isoFile, int $maxDepth = 64): Generator
            {
                return $this->volume->walk($isoFile, $maxDepth, false);
            }

            public function copyEntryTo(IsoFile $isoFile, IsoEntry $entry, mixed $output): void
            {
                $this->volume->copyEntryTo($isoFile, $entry, $output);
            }
        };
    }

    /**
     * The UDF file system, an unsupported one is reported without hiding the rest of the information
     */
    protected function udf(IsoFile $isoFile): ?UdfFileSystem
    {
        try {
            return $isoFile->getUdfFileSystem();
        } catch (Exception $ex) {
            $this->displayError('UDF: ' . $ex->getMessage());

            return null;
        }
    }

    protected function infoVolume(Volume $volumeDescriptor): void
    {
        echo '   - System ID: ' . $volumeDescriptor->systemId . PHP_EOL;
        echo '   - Volume ID: ' . $volumeDescriptor->volumeId . PHP_EOL;
        echo '   - App ID: ' . $volumeDescriptor->appId . PHP_EOL;
        echo '   - File Structure Version: ' . $volumeDescriptor->fileStructureVersion . PHP_EOL;
        echo '   - Volume Space Size: ' . $volumeDescriptor->volumeSpaceSize . PHP_EOL;
        echo '   - Volume Set Size: ' . $volumeDescriptor->volumeSetSize . PHP_EOL;
        echo '   - Volume SeqNum: ' . $volumeDescriptor->volumeSeqNum . PHP_EOL;
        echo '   - Block size: ' . $volumeDescriptor->blockSize . PHP_EOL;
        echo '   - Volume Set ID: ' . $volumeDescriptor->volumeSetId . PHP_EOL;
        echo '   - Publisher ID: ' . $volumeDescriptor->publisherId . PHP_EOL;
        echo '   - Preparer ID: ' . $volumeDescriptor->preparerId . PHP_EOL;
        echo '   - Copyright File ID: ' . $volumeDescriptor->copyrightFileId . PHP_EOL;
        echo '   - Abstract File ID: ' . $volumeDescriptor->abstractFileId . PHP_EOL;
        echo '   - Bibliographic File ID: ' . $volumeDescriptor->bibliographicFileId . PHP_EOL;
        echo '   - Creation Date: ' . $volumeDescriptor->creationDate?->toDateTimeString() . PHP_EOL;
        echo '   - Modification Date: ' . $volumeDescriptor->modificationDate?->toDateTimeString() . PHP_EOL;
        echo '   - Expiration Date: ' . $volumeDescriptor->expirationDate?->toDateTimeString() . PHP_EOL;
        echo '   - Effective Date: ' . $volumeDescriptor->effectiveDate?->toDateTimeString() . PHP_EOL;

        if ($volumeDescriptor instanceof SupplementaryVolume && $volumeDescriptor->jolietLevel !== 0) {
            echo '   - Joliet Level: ' . $volumeDescriptor->jolietLevel . PHP_EOL;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function volumeToArray(Volume $volume): array
    {
        return [
            'systemId' => $volume->systemId,
            'volumeId' => $volume->volumeId,
            'appId' => $volume->appId,
            'publisherId' => $volume->publisherId,
            'preparerId' => $volume->preparerId,
            'volumeSpaceSize' => $volume->volumeSpaceSize,
            'blockSize' => $volume->blockSize,
            'jolietLevel' => $volume->jolietLevel,
            'creationDate' => $volume->creationDate?->toIso8601String(),
            'modificationDate' => $volume->modificationDate?->toIso8601String(),
            'expirationDate' => $volume->expirationDate?->toIso8601String(),
            'effectiveDate' => $volume->effectiveDate?->toIso8601String(),
        ];
    }

    protected function displayFiles(FileSystem $volumeDescriptor, IsoFile $isoFile): void
    {
        echo '   - Files:' . PHP_EOL;

        foreach ($volumeDescriptor->walk($isoFile) as $entry) {
            if ($entry->isDirectory) {
                echo $entry->path . '/' . PHP_EOL;
            } else {
                echo $entry->path . ' (location: ' . $entry->location . ') (length: ' . $entry->size . ')' . PHP_EOL;
            }
        }
    }

    protected function infoBoot(Boot $bootDescriptor, IsoFile $isoFile): void
    {
        echo '   - Boot System ID: ' . $bootDescriptor->bootSysId . PHP_EOL;
        echo '   - Boot ID: ' . $bootDescriptor->bootId . PHP_EOL;
        echo '   - Boot Catalog Location: ' . $bootDescriptor->bootCatalogLocation . PHP_EOL;

        $catalog = $this->loadCatalog($bootDescriptor, $isoFile);

        if ($catalog === null) {
            return;
        }

        echo '   - Boot Catalog Checksum: ' . ($catalog->validChecksum ? 'valid' : 'INVALID') . PHP_EOL;

        foreach ($catalog->entries as $index => $entry) {
            echo '   - Boot Entry ' . ($index + 1) . ': ' . ($entry->bootable ? 'bootable' : 'not bootable')
                . ', platform ' . $entry->getPlatformName()
                . ', media ' . $entry->getMediaName()
                . ', load segment 0x' . dechex($entry->loadSegment)
                . ', sectors ' . $entry->sectorCount
                . ', load RBA ' . $entry->loadRba . PHP_EOL;
        }
    }

    protected function loadCatalog(Boot $bootDescriptor, IsoFile $isoFile): ?BootCatalog
    {
        try {
            return $bootDescriptor->loadCatalog($isoFile);
        } catch (Exception) {
            // a broken catalog must not hide the rest of the information
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function catalogToArray(BootCatalog $catalog): array
    {
        return [
            'validChecksum' => $catalog->validChecksum,
            'manufacturer' => $catalog->manufacturer,
            'entries' => array_map(static fn (Descriptor\BootEntry $entry): array => [
                'bootable' => $entry->bootable,
                'platform' => $entry->getPlatformName(),
                'media' => $entry->getMediaName(),
                'loadSegment' => $entry->loadSegment,
                'sectorCount' => $entry->sectorCount,
                'loadRba' => $entry->loadRba,
            ], $catalog->entries),
        ];
    }

    /**
     * Parse the command line (own parser: getopt silently ignores options with a missing value)
     *
     * @param array<int, string>|null $argv defaults to the process arguments
     *
     * @return array<string, mixed>
     *
     * @throws Exception on unknown options
     */
    protected function parseCliArgs(?array $argv = null): array
    {
        if ($argv === null) {
            $raw = $_SERVER['argv'] ?? [];
            $argv = is_array($raw) ? array_values(array_filter(array_slice($raw, 1), is_string(...))) : [];
        }

        $valued = ['f', 'x', 'c', 'file', 'extract', 'cat', 'find', 'volume', 'extract-boot'];
        $flags = ['l', 'j', 'h', 'list', 'json', 'help', 'files', 'ndjson', 'no-rock-ridge'];

        $options = [];

        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                $value = null;
                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) > 1) {
                $name = $arg[1];
                $value = strlen($arg) > 2 ? substr($arg, 2) : null;

                // bundled flags (-lj): every letter is a flag
                if ($value !== null && in_array($name, $flags, true)) {
                    foreach (str_split(substr($arg, 1)) as $letter) {
                        if (! in_array($letter, $flags, true)) {
                            throw new Exception('Unknown option: -' . $letter . ' in ' . $arg);
                        }

                        $options[$letter] = false;
                    }

                    continue;
                }
            } else {
                throw new Exception('Unexpected argument: ' . $arg);
            }

            if (in_array($name, $valued, true)) {
                // a value is the next argument, unless that one is another option
                if ($value === null) {
                    $next = $argv[$i + 1] ?? '';
                    $value = (str_starts_with($next, '-') && $next !== '-') ? '' : $next;
                    if ($value !== '') {
                        $i++;
                    }
                }

                $options[$name] = $value;
            } elseif (in_array($name, $flags, true)) {
                $options[$name] = false;
            } else {
                throw new Exception('Unknown option: ' . $arg);
            }
        }

        return $options;
    }

    protected function firstString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
    protected function displayError(string $error): void
    {
        fwrite(STDERR, 'ERROR: ' . $error . PHP_EOL);
    }

    protected function displayHelp(): void
    {
        $help = '
Description:
  Tool to process ISO files

Usage:
  isotool [options] --file=<path>

Options:
  -f, --file=<path>              Path for the ISO file, "-" reads it from the standard input (mandatory)
  -l, --list                     Print only the list of files (path and size)
  -j, --json                     Print all the information as JSON
      --ndjson                   With --list: one JSON object per line and entry, streamed (newline delimited JSON)
  -x, --extract=<extract_path>   Extract files in the given location
  -c, --cat=<path>               Write the content of a file of the ISO to the standard output
      --find=<pattern>           List the files matching a pattern (e.g. "*.txt", case insensitive)
      --extract-boot=<path>      Write the El Torito default boot image to the given file
      --files                    Also list the files of every volume in the default information output
      --volume=<name>            File system used by --list, --cat, --find and --extract: primary, joliet or udf
                                 (default: Joliet, else primary, else UDF)
      --no-rock-ridge            Ignore the Rock Ridge extensions (use the plain ISO 9660 / Joliet names)
  -h, --help                     Show this help

Only one of --list, --json, --extract, --cat, --find and --extract-boot can be used at a time.
Flags can be bundled (e.g. -lj).

Exit codes:
  0  success
  1  usage error (unknown, conflicting or missing options, including a missing --file)
  2  invalid file argument
  3  the ISO could not be read or extracted
';
        echo $help;
    }
}
