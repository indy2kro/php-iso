<?php

declare(strict_types=1);

namespace PhpIso\Cli;

use PhpIso\Descriptor;
use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\PrimaryVolume;
use PhpIso\Descriptor\SupplementaryVolume;
use PhpIso\Descriptor\Type;
use PhpIso\Descriptor\Volume;
use PhpIso\Exception;
use PhpIso\FileDirectory;
use PhpIso\IsoFile;
use PhpIso\PathTableRecord;
use Throwable;

class IsoTool
{
    public function run(): void
    {
        $options = $this->parseCliArgs();

        if ($options === []) {
            $this->displayHelp();
            exit(1);
        }

        $fileValue = $options['file'] ?? $options['f'];

        $file = '';

        if (is_array($fileValue)) {
            $file = current($fileValue);
        } elseif (is_string($fileValue)) {
            $file = $fileValue;
        }

        if (! is_string($file) || $file === '') {
            $this->displayError('Invalid value for file received');
            exit(2);
        }

        $extractPath = '';
        if (isset($options['extract']) && is_string($options['extract'])) {
            $extractPath = $options['extract'];
        } elseif (isset($options['x']) && is_string($options['x'])) {
            $extractPath = $options['x'];
        }

        echo 'Input ISO file: ' . $file . PHP_EOL;

        try {
            $this->checkIsoFile($file);

            if ($extractPath !== '') {
                $this->extractAction($file, $extractPath);
            } else {
                $this->infoAction($file);
            }
        } catch (Throwable $ex) {
            $this->displayError($ex->getMessage());
            exit(3);
        }
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
                $this->infoBoot($descriptor);
            }

            echo PHP_EOL;
        }
    }

    protected function extractAction(string $file, string $extractPath): void
    {
        if (! is_dir($extractPath)) {
            $mkdirResult = mkdir($extractPath, 0777, true);

            if ($mkdirResult === false) {
                throw new Exception('Failed to create extract output directory: ' . $extractPath);
            }
        }

        $isoFile = new IsoFile($file);

        echo 'Extract ISO file to: ' . $extractPath . PHP_EOL;

        if (isset($isoFile->descriptors[Type::SUPPLEMENTARY_VOLUME_DESC]) && $isoFile->descriptors[Type::SUPPLEMENTARY_VOLUME_DESC] instanceof SupplementaryVolume) {
            $this->extractFiles($isoFile->descriptors[Type::SUPPLEMENTARY_VOLUME_DESC], $isoFile, $extractPath);
        } elseif (isset($isoFile->descriptors[Type::PRIMARY_VOLUME_DESC]) && $isoFile->descriptors[Type::PRIMARY_VOLUME_DESC] instanceof PrimaryVolume) {
            $this->extractFiles($isoFile->descriptors[Type::PRIMARY_VOLUME_DESC], $isoFile, $extractPath);
        }

        echo 'Extract finished!' . PHP_EOL;
    }

    protected function extractFiles(Volume $volumeDescriptor, IsoFile $isoFile, string $destinationDir): void
    {
        $pathTable = $volumeDescriptor->loadTable($isoFile);

        if ($pathTable === null) {
            return;
        }

        $destinationDir = rtrim($destinationDir, DIRECTORY_SEPARATOR);

        /** @var PathTableRecord $pathRecord */
        foreach ($pathTable as $pathRecord) {
            // check extents
            $extents = $pathRecord->loadExtents($isoFile, $volumeDescriptor->blockSize, ($volumeDescriptor->getType() === Type::SUPPLEMENTARY_VOLUME_DESC), $volumeDescriptor->jolietLevel);

            if ($extents !== false) {
                /** @var FileDirectory $extentRecord */
                foreach ($extents as $extentRecord) {
                    $path = $extentRecord->fileId;

                    if (! $extentRecord->isThis() && ! $extentRecord->isParent()) {
                        $fullPath = $destinationDir . $pathRecord->getFullPath($pathTable) . $path;
                        if ($extentRecord->isDirectory()) {
                            $fullPath .= DIRECTORY_SEPARATOR;
                        }

                        if (! $extentRecord->isDirectory()) {
                            $location = $extentRecord->location;
                            $dataLength = $extentRecord->dataLength;
                            echo $fullPath . ' (location: ' . $location . ') (length: ' . $dataLength . ')'  . PHP_EOL;

                            $dirPath = dirname($fullPath);
                            if (! is_dir($dirPath)) {
                                if (mkdir($dirPath, 0777, true) === false) {
                                    throw new Exception('Failed to create directory: ' . $dirPath);
                                }
                            }

                            $pathRecord->extractFile($isoFile, $volumeDescriptor->blockSize, $location, $dataLength, $fullPath);
                        } else {
                            if (! is_dir($fullPath)) {
                                if (mkdir($fullPath, 0777, true) === false) {
                                    throw new Exception('Failed to create directory: ' . $fullPath);
                                }
                            }
                        }
                    }
                }
            }
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

    protected function displayFiles(Volume $volumeDescriptor, IsoFile $isoFile): void
    {
        $pathTable = $volumeDescriptor->loadTable($isoFile);

        if ($pathTable === null) {
            return;
        }

        echo '   - Files:' . PHP_EOL;

        /** @var PathTableRecord $pathRecord */
        foreach ($pathTable as $pathRecord) {
            // check extents
            $extents = $pathRecord->loadExtents($isoFile, $volumeDescriptor->blockSize, ($volumeDescriptor->getType() === Type::SUPPLEMENTARY_VOLUME_DESC), $volumeDescriptor->jolietLevel);

            if ($extents !== false) {
                /** @var FileDirectory $extentRecord */
                foreach ($extents as $extentRecord) {
                    $path = $extentRecord->fileId;

                    if (! $extentRecord->isThis() && ! $extentRecord->isParent()) {
                        $fullPath = $pathRecord->getFullPath($pathTable) . $path;
                        if ($extentRecord->isDirectory()) {
                            $fullPath .= DIRECTORY_SEPARATOR;
                        }

                        if (! $extentRecord->isDirectory()) {
                            $location = $extentRecord->location;
                            $dataLength = $extentRecord->dataLength;
                            echo $fullPath . ' (location: ' . $location . ') (length: ' . $dataLength . ')'  . PHP_EOL;
                        } else {
                            echo $fullPath . PHP_EOL;
                        }
                    }
                }
            }
        }
    }

    protected function infoBoot(Boot $bootDescriptor): void
    {
        echo '   - Boot System ID: ' . $bootDescriptor->bootSysId . PHP_EOL;
        echo '   - Boot ID: ' . $bootDescriptor->bootId . PHP_EOL;
        echo '   - Boot Catalog Location: ' . $bootDescriptor->bootCatalogLocation . PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseCliArgs(): array
    {
        $shortopts = 'f:x::';
        $longopts = [
            'file:',
            'extract::',
        ];
        $options = getopt($shortopts, $longopts, $restIndex);

        if ($options === false) {
            return [];
        }

        return $options;
    }

    protected function displayError(string $error): void
    {
        echo 'ERROR: ' . $error . PHP_EOL;
    }

    protected function displayHelp(): void
    {
        $help = '
Description:
  Tool to process ISO files

Usage:
  isotool [options] --file=<path>

Options:
  -f, --file                     Path for the ISO file (mandatory)
  -x, --extract=<extract_path>   Extract files in the given location
';
        echo $help;
    }
}
