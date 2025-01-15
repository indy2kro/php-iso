<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Carbon\Carbon;
use Iterator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpIso\IsoFile;
use PhpIso\Exception;
use PhpIso\Descriptor\Type;
use PhpIso\Descriptor\Terminator;
use PhpIso\Descriptor\PrimaryVolume;
use PhpIso\PathTableRecord;
use PhpIso\FileDirectory;

class IsoFileTest extends TestCase
{
    public function testConstructorFileDoesNotExist(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/does-not-exist.iso';

        $this->expectException(Exception::class);
        new IsoFile($testFile);
    }

    public function testConstructorInvalidFile(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/invalid.iso';

        $this->expectException(Exception::class);
        new IsoFile($testFile);
    }

    #[DataProvider('isoFilesDataProvider')]
    public function testConstructorExistingFile(string $testFile, int $descriptorCount): void
    {
        $isoFile = new IsoFile($testFile);
        $this->assertInstanceOf(IsoFile::class, $isoFile);
        $this->assertCount($descriptorCount, $isoFile->descriptors);
    }


    public function testDescriptorsTestIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/test.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(2, $isoFile->descriptors);

        $this->assertArrayHasKey(Type::TERMINATOR_DESC, $isoFile->descriptors);

        /** @var Terminator $terminatorDescriptor */
        $terminatorDescriptor = $isoFile->descriptors[Type::TERMINATOR_DESC];

        $this->assertInstanceOf(Terminator::class, $terminatorDescriptor);

        $this->assertSame('Terminator descriptor', $terminatorDescriptor->name);
        $this->assertSame('CD001', $terminatorDescriptor->stdId);
        $this->assertSame(1, $terminatorDescriptor->version);

        $this->assertArrayHasKey(Type::PRIMARY_VOLUME_DESC, $isoFile->descriptors);

        /** @var PrimaryVolume $primaryVolumeDescriptor */
        $primaryVolumeDescriptor = $isoFile->descriptors[Type::PRIMARY_VOLUME_DESC];

        $this->assertInstanceOf(PrimaryVolume::class, $primaryVolumeDescriptor);

        $this->assertSame('Primary volume descriptor', $primaryVolumeDescriptor->name);
        $this->assertSame('CD001', $primaryVolumeDescriptor->stdId);
        $this->assertSame(1, $primaryVolumeDescriptor->version);
        $this->assertSame(1, $primaryVolumeDescriptor->fileStructureVersion);
        $this->assertSame('', $primaryVolumeDescriptor->systemId);
        $this->assertSame('PHP_ISO_FILE', $primaryVolumeDescriptor->volumeId);
        $this->assertSame(599, $primaryVolumeDescriptor->volumeSpaceSize);
        $this->assertSame('IMGBURN V2.5.8.0 - THE ULTIMATE IMAGE BURNER!', $primaryVolumeDescriptor->appId);
        $this->assertNotNull($primaryVolumeDescriptor->creationDate);
        $this->assertSame(Carbon::create(2025, 1, 12, 15, 0, 53, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->creationDate->toDateTimeString());
        $this->assertNotNull($primaryVolumeDescriptor->modificationDate);
        $this->assertSame(Carbon::create(2025, 1, 12, 15, 0, 53, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->modificationDate->toDateTimeString());
        $this->assertNull($primaryVolumeDescriptor->expirationDate);
        $this->assertNull($primaryVolumeDescriptor->effectiveDate);

        // check root directory
        $rootDirectory = $primaryVolumeDescriptor->rootDirectory;
        $this->assertSame('.', $rootDirectory->fileId);
        $this->assertTrue($rootDirectory->isDirectory());
        $this->assertSame(Carbon::create(2025, 1, 12, 15, 0, 53, 'Europe/Paris')?->toDateTimeString(), $rootDirectory->recordingDate->toDateTimeString());

        // check path table
        $pathTable = $primaryVolumeDescriptor->loadTable($isoFile);
        $this->assertNotNull($pathTable);

        $paths = [];

        /** @var PathTableRecord $pathRecord */
        foreach ($pathTable as $pathRecord) {
            $currentPath = $pathRecord->getFullPath($pathTable);

            $paths[$currentPath] = [];

            // check extents
            $extents = $pathRecord->loadExtents($isoFile, $primaryVolumeDescriptor->blockSize);

            /** @var FileDirectory $extentRecord */
            foreach ($extents as $extentRecord) {
                $path = $extentRecord->fileId;
                if ($extentRecord->isDirectory()) {
                    $path .= '/';
                }
                $paths[$currentPath][] = $path;
            }
        }

        $pathsExpected = [
            '/' => [
                './',
                '../',
                'CLASSES/',
                'COMPOSER.JSO',
                'EXAMPLES/',
            ],
            '/CLASSES' => [
                './',
                '../',
                'BOOT_CA2.PHP',
                'BOOT_CAT.PHP',
                'BUFFER_C.PHP',
                'DESCRIP2.PHP',
                'DESCRIP3.PHP',
                'DESCRIP4.PHP',
                'DESCRIP5.PHP',
                'DESCRIPT.PHP',
                'FILE_DIR.PHP',
                'FILE_ISO.PHP',
                'ISO_DATE.PHP',
                'ISO_INCL.PHP',
                'PATH_TAB.PHP',
            ],
            '/EXAMPLES' => [
                './',
                '../',
                'BOOTCATA.PHP',
                'ISO_BASE.PHP',
                'ISO_FILE.PHP',
            ],
        ];

        $this->assertSame($pathsExpected, $paths);
    }

    public function testDescriptorsTestDirIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/test-dir.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(2, $isoFile->descriptors);

        $this->assertArrayHasKey(Type::TERMINATOR_DESC, $isoFile->descriptors);

        /** @var Terminator $terminatorDescriptor */
        $terminatorDescriptor = $isoFile->descriptors[Type::TERMINATOR_DESC];

        $this->assertInstanceOf(Terminator::class, $terminatorDescriptor);

        $this->assertSame('Terminator descriptor', $terminatorDescriptor->name);
        $this->assertSame('CD001', $terminatorDescriptor->stdId);
        $this->assertSame(1, $terminatorDescriptor->version);

        $this->assertArrayHasKey(Type::PRIMARY_VOLUME_DESC, $isoFile->descriptors);

        /** @var PrimaryVolume $primaryVolumeDescriptor */
        $primaryVolumeDescriptor = $isoFile->descriptors[Type::PRIMARY_VOLUME_DESC];

        $this->assertInstanceOf(PrimaryVolume::class, $primaryVolumeDescriptor);

        $this->assertSame('Primary volume descriptor', $primaryVolumeDescriptor->name);
        $this->assertSame('CD001', $primaryVolumeDescriptor->stdId);
        $this->assertSame(1, $primaryVolumeDescriptor->version);
        $this->assertSame(1, $primaryVolumeDescriptor->fileStructureVersion);
        $this->assertSame('LINUX', $primaryVolumeDescriptor->systemId);
        $this->assertSame('CDROM', $primaryVolumeDescriptor->volumeId);
        $this->assertSame(198, $primaryVolumeDescriptor->volumeSpaceSize);
        $this->assertSame('GENISOIMAGE ISO 9660/HFS FILESYSTEM CREATOR (C) 1993 E.YOUNGDALE (C) 1997-2006 J.PEARSON/J.SCHILLING (C) 2006-2007 CDRKIT TEAM', $primaryVolumeDescriptor->appId);
        $this->assertNotNull($primaryVolumeDescriptor->creationDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 9, 41, 36, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->creationDate->toDateTimeString());
        $this->assertNotNull($primaryVolumeDescriptor->modificationDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 9, 41, 36, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->modificationDate->toDateTimeString());
        $this->assertNull($primaryVolumeDescriptor->expirationDate);
        $this->assertNotNull($primaryVolumeDescriptor->effectiveDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 9, 41, 36, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->effectiveDate->toDateTimeString());

        // check root directory
        $rootDirectory = $primaryVolumeDescriptor->rootDirectory;
        $this->assertSame('.', $rootDirectory->fileId);
        $this->assertTrue($rootDirectory->isDirectory());
        $this->assertSame(Carbon::create(2025, 1, 15, 9, 41, 9, 'Europe/Paris')?->toDateTimeString(), $rootDirectory->recordingDate->toDateTimeString());

        // check path table
        $pathTable = $primaryVolumeDescriptor->loadTable($isoFile);
        $this->assertNotNull($pathTable);

        $paths = [];

        /** @var PathTableRecord $pathRecord */
        foreach ($pathTable as $pathRecord) {
            $currentPath = $pathRecord->getFullPath($pathTable);

            $paths[$currentPath] = [];

            // check extents
            $extents = $pathRecord->loadExtents($isoFile, $primaryVolumeDescriptor->blockSize);

            /** @var FileDirectory $extentRecord */
            foreach ($extents as $extentRecord) {
                $path = $extentRecord->fileId;
                if ($extentRecord->isDirectory()) {
                    $path .= '/';
                }
                $paths[$currentPath][] = $path;
            }
        }

        $pathsExpected = [
            '/' => [
                './',
                '../',
                'CONTRIBU.MD',
                'LICENSE.',
                'SUB_DIR/',
                'TEST_FIL.TXT',
            ],
            '/SUB_DIR' => [
                './',
                '../',
                'TEST_000.TXT',
                'TEST_FIL.TXT',
            ],
        ];
        $this->assertSame($pathsExpected, $paths);
    }

    public static function isoFilesDataProvider(): Iterator
    {
        yield [dirname(__FILE__, 2) . '/fixtures/1mb.iso', 3];
        yield [dirname(__FILE__, 2) . '/fixtures/test.iso', 2];
        yield [dirname(__FILE__, 2) . '/fixtures/test-dir.iso', 2];
    }
}
