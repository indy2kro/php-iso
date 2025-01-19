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
use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\SupplementaryVolume;
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

    public function testInvalidSeekRead(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/test.iso';
        $isoFile = new IsoFile($testFile);
        $isoFile->closeFile();
        $this->assertSame(-1, $isoFile->seek(10));
        $this->assertFalse($isoFile->read(10));
        $this->assertFalse($isoFile->read(-2));
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
        $this->assertNotNull($rootDirectory->recordingDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 9, 41, 9, 'Europe/Paris')?->toDateTimeString(), $rootDirectory->recordingDate->toDateTimeString());

        // check path table
        $this->assertTrue($primaryVolumeDescriptor->isMPathTable());
        $this->assertTrue($primaryVolumeDescriptor->isLPathTable());

        $pathTable = $primaryVolumeDescriptor->loadTable($isoFile);
        $this->assertNotNull($pathTable);

        $paths = [];

        /** @var PathTableRecord $pathRecord */
        foreach ($pathTable as $pathRecord) {
            $currentPath = $pathRecord->getFullPath($pathTable);

            $paths[$currentPath] = [];

            // check extents
            $extents = $pathRecord->loadExtents($isoFile, $primaryVolumeDescriptor->blockSize);

            if ($extents !== false) {
                /** @var FileDirectory $extentRecord */
                foreach ($extents as $extentRecord) {
                    $path = $extentRecord->fileId;
                    if ($extentRecord->isDirectory()) {
                        $path .= '/';
                    }
                    $paths[$currentPath][] = $path;
                }
            }
        }

        $pathsExpected = [
            DIRECTORY_SEPARATOR => [
                './',
                '../',
                'CONTRIBU.MD',
                'LICENSE.',
                'SUB_DIR/',
                'TEST_FIL.TXT',
            ],
            DIRECTORY_SEPARATOR . 'SUB_DIR' . DIRECTORY_SEPARATOR => [
                './',
                '../',
                'TEST_000.TXT',
                'TEST_FIL.TXT',
            ],
        ];
        $this->assertSame($pathsExpected, $paths);
    }

    public function testDescriptorsSubdirIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/subdir.iso';
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
        $this->assertSame(181, $primaryVolumeDescriptor->volumeSpaceSize);
        $this->assertSame('GENISOIMAGE ISO 9660/HFS FILESYSTEM CREATOR (C) 1993 E.YOUNGDALE (C) 1997-2006 J.PEARSON/J.SCHILLING (C) 2006-2007 CDRKIT TEAM', $primaryVolumeDescriptor->appId);
        $this->assertNotNull($primaryVolumeDescriptor->creationDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 16, 7, 41, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->creationDate->toDateTimeString());
        $this->assertNotNull($primaryVolumeDescriptor->modificationDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 16, 7, 41, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->modificationDate->toDateTimeString());
        $this->assertNull($primaryVolumeDescriptor->expirationDate);
        $this->assertNotNull($primaryVolumeDescriptor->effectiveDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 16, 7, 41, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->effectiveDate->toDateTimeString());

        // check root directory
        $rootDirectory = $primaryVolumeDescriptor->rootDirectory;
        $this->assertSame('.', $rootDirectory->fileId);
        $this->assertTrue($rootDirectory->isDirectory());
        $this->assertNotNull($rootDirectory->recordingDate);
        $this->assertSame(Carbon::create(2025, 1, 15, 16, 7, 30, 'Europe/Paris')?->toDateTimeString(), $rootDirectory->recordingDate->toDateTimeString());

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

            if ($extents !== false) {
                /** @var FileDirectory $extentRecord */
                foreach ($extents as $extentRecord) {
                    $path = $extentRecord->fileId;
                    if ($extentRecord->isDirectory()) {
                        $path .= '/';
                    }
                    $paths[$currentPath][] = $path;
                }
            }
        }

        $pathsExpected = [
            DIRECTORY_SEPARATOR => [
                './',
                '../',
                'DIR1/',
                'TEST1.TXT',
            ],
            DIRECTORY_SEPARATOR . 'DIR1' . DIRECTORY_SEPARATOR => [
                './',
                '../',
                'DIR2/',
                'TEST2.TXT',
            ],
            DIRECTORY_SEPARATOR . 'DIR1' . DIRECTORY_SEPARATOR . 'DIR2' . DIRECTORY_SEPARATOR => [
                './',
                '../',
                'DIR3/',
                'TEST3.TXT',
            ],
            DIRECTORY_SEPARATOR . 'DIR1' . DIRECTORY_SEPARATOR . 'DIR2' . DIRECTORY_SEPARATOR . 'DIR3' . DIRECTORY_SEPARATOR => [
                './',
                '../',
                'TEST4.TXT',
            ],
        ];
        $this->assertSame($pathsExpected, $paths);
    }

    public function testDescriptorsDosIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/DOS4.01_bootdisk.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(4, $isoFile->descriptors);

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
        $this->assertSame(0, $primaryVolumeDescriptor->fileStructureVersion);
        $this->assertSame('', $primaryVolumeDescriptor->systemId);
        $this->assertSame('DOS4.01', $primaryVolumeDescriptor->volumeId);
        $this->assertSame(848, $primaryVolumeDescriptor->volumeSpaceSize);
        $this->assertSame('', $primaryVolumeDescriptor->appId);
        $this->assertNull($primaryVolumeDescriptor->creationDate);
        $this->assertNull($primaryVolumeDescriptor->modificationDate);
        $this->assertNull($primaryVolumeDescriptor->expirationDate);
        $this->assertNull($primaryVolumeDescriptor->effectiveDate);

        /** @var Boot $bootDescriptor */
        $bootDescriptor = $isoFile->descriptors[Type::BOOT_RECORD_DESC];

        $this->assertInstanceOf(Boot::class, $bootDescriptor);

        $this->assertSame('Boot volume descriptor', $bootDescriptor->name);
        $this->assertSame('CD001', $bootDescriptor->stdId);
        $this->assertSame(1, $bootDescriptor->version);

        /** @var SupplementaryVolume $supplementaryVolumeDescriptor */
        $supplementaryVolumeDescriptor = $isoFile->descriptors[Type::SUPPLEMENTARY_VOLUME_DESC];

        $this->assertInstanceOf(SupplementaryVolume::class, $supplementaryVolumeDescriptor);

        $this->assertSame('Supplementary volume descriptor', $supplementaryVolumeDescriptor->name);
        $this->assertSame('CD001', $supplementaryVolumeDescriptor->stdId);
        $this->assertSame(1, $supplementaryVolumeDescriptor->version);
        $this->assertSame(0, $supplementaryVolumeDescriptor->fileStructureVersion);
        $this->assertSame('', $supplementaryVolumeDescriptor->systemId);
        $this->assertSame('DOS4.01', $supplementaryVolumeDescriptor->volumeId);
        $this->assertSame(848, $supplementaryVolumeDescriptor->volumeSpaceSize);
        $this->assertSame('', $supplementaryVolumeDescriptor->appId);
        $this->assertSame(3, $supplementaryVolumeDescriptor->jolietLevel);
        $this->assertNull($supplementaryVolumeDescriptor->creationDate);
        $this->assertNull($supplementaryVolumeDescriptor->modificationDate);
        $this->assertNull($supplementaryVolumeDescriptor->expirationDate);
        $this->assertNull($supplementaryVolumeDescriptor->effectiveDate);
    }

    public function testDescriptorsIso9660HfsPartIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/iso9660_hfs_part.iso';
        $isoFile = new IsoFile($testFile);

        $this->assertCount(3, $isoFile->descriptors);
    }

    public static function isoFilesDataProvider(): Iterator
    {
        yield [dirname(__FILE__, 2) . '/fixtures/1mb.iso', 3];
        yield [dirname(__FILE__, 2) . '/fixtures/subdir.iso', 2];
        yield [dirname(__FILE__, 2) . '/fixtures/test-dir.iso', 2];
        yield [dirname(__FILE__, 2) . '/fixtures/DOS4.01_bootdisk.iso', 4];
        yield [dirname(__FILE__, 2) . '/fixtures/iso9660_hfs_part.iso', 3];
    }
}
