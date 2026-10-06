<?php

declare(strict_types=1);

namespace PhpIso\Cli;

use PhpIso\Descriptor;
use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\SupplementaryVolume;
use PhpIso\Descriptor\Volume;
use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use Throwable;

class IsoTool
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 1;
    public const EXIT_INVALID_FILE = 2;
    public const EXIT_ERROR = 3;

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

        $file = $this->firstString($options['file'] ?? $options['f'] ?? null);

        if ($file === '') {
            $this->displayError('Invalid value for file received');
            return self::EXIT_INVALID_FILE;
        }

        $extractPath = $this->firstString($options['extract'] ?? $options['x'] ?? null);
        $catPath = $this->firstString($options['cat'] ?? $options['c'] ?? null);
        $find = $this->firstString($options['find'] ?? null);
        $json = isset($options['json']) || isset($options['j']);
        $list = isset($options['list']) || isset($options['l']);

        // options taking a value: long name => [value, short name]
        $valued = ['extract' => [$extractPath, 'x'], 'cat' => [$catPath, 'c'], 'find' => [$find, 'find']];
        foreach ($valued as $name => [$value, $short]) {
            if ((isset($options[$name]) || isset($options[$short])) && $value === '') {
                $this->displayError('The ' . $name . ' option requires a value');
                return self::EXIT_USAGE;
            }
        }

        try {
            $this->checkIsoFile($file);

            if ($extractPath !== '') {
                $this->extractAction($file, $extractPath);
            } elseif ($catPath !== '') {
                $this->catAction($file, $catPath);
            } elseif ($find !== '') {
                $this->findAction($file, $find);
            } elseif ($json) {
                $this->jsonAction($file);
            } elseif ($list) {
                $this->listAction($file);
            } else {
                echo 'Input ISO file: ' . $file . PHP_EOL;
                $this->infoAction($file);
            }
        } catch (Throwable $ex) {
            $this->displayError($ex->getMessage());
            return self::EXIT_ERROR;
        }

        return self::EXIT_OK;
    }

    protected function checkIsoFile(string $file): void
    {
        if (! file_exists($file)) {
            throw new Exception('ISO file does not exist.');
        }

        if (! is_file($file)) {
            throw new Exception('Path is not a valid file.');
        }
    }

    protected function infoAction(string $file): void
    {
        $isoFile = new IsoFile($file);

        echo PHP_EOL;

        echo 'Number of descriptors: ' . count($isoFile->descriptors) . PHP_EOL;

        /** @var Descriptor $descriptor */
        foreach ($isoFile->descriptors as $descriptor) {
            echo '  - ' . $descriptor->name . PHP_EOL;

            if ($descriptor instanceof Volume) {
                $this->infoVolume($descriptor);
                $this->displayFiles($descriptor, $isoFile);
            } elseif ($descriptor instanceof Boot) {
                $this->infoBoot($descriptor, $isoFile);
            }

            echo PHP_EOL;
        }
    }

    protected function listAction(string $file): void
    {
        $isoFile = new IsoFile($file);
        $volume = $this->requireVolume($isoFile);

        foreach ($volume->walk($isoFile) as $entry) {
            if ($entry->isDirectory) {
                echo $entry->path . '/' . PHP_EOL;
            } else {
                echo $entry->path . "\t" . $entry->size . PHP_EOL;
            }
        }
    }

    protected function catAction(string $file, string $path): void
    {
        $isoFile = new IsoFile($file);
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
        $isoFile = new IsoFile($file);
        $volume = $this->requireVolume($isoFile);

        foreach ($volume->search($isoFile, $pattern) as $entry) {
            echo $entry->path . ($entry->isDirectory ? '/' : "\t" . $entry->size) . PHP_EOL;
        }
    }

    protected function jsonAction(string $file): void
    {
        $isoFile = new IsoFile($file);

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

        echo json_encode(['file' => $file, 'descriptors' => $descriptors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    protected function extractAction(string $file, string $extractPath): void
    {
        $isoFile = new IsoFile($file);
        $volume = $this->requireVolume($isoFile);

        echo 'Input ISO file: ' . $file . PHP_EOL;
        echo 'Extract ISO file to: ' . $extractPath . PHP_EOL;

        $count = (new Extractor())->extract($isoFile, $volume, $extractPath, static function (IsoEntry $entry): void {
            echo $entry->path . ' (location: ' . $entry->location . ') (length: ' . $entry->size . ')' . PHP_EOL;
        });

        echo 'Extract finished! (' . $count . ' files)' . PHP_EOL;
    }

    protected function requireVolume(IsoFile $isoFile): Volume
    {
        return $isoFile->getPreferredVolume() ?? throw new Exception('No supported volume descriptor found in the ISO file.');
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

    protected function displayFiles(Volume $volumeDescriptor, IsoFile $isoFile): void
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

        $valued = ['f', 'x', 'c', 'file', 'extract', 'cat', 'find'];
        $flags = ['l', 'j', 'h', 'list', 'json', 'help'];

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
            } else {
                throw new Exception('Unexpected argument: ' . $arg);
            }

            if (in_array($name, $valued, true)) {
                // a value is the next argument, unless that one is another option
                if ($value === null) {
                    $next = $argv[$i + 1] ?? '';
                    $value = str_starts_with($next, '-') ? '' : $next;
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
        if (is_array($value)) {
            $value = current($value);
        }

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
  -f, --file=<path>              Path for the ISO file (mandatory)
  -l, --list                     Print only the list of files (path and size)
  -j, --json                     Print all the information as JSON
  -x, --extract=<extract_path>   Extract files in the given location
  -c, --cat=<path>               Write the content of a file of the ISO to the standard output
      --find=<pattern>           List the files matching a pattern (e.g. "*.txt", case insensitive)
  -h, --help                     Show this help

Exit codes:
  0  success
  1  usage error
  2  invalid file argument
  3  the ISO could not be read or extracted
';
        echo $help;
    }
}
