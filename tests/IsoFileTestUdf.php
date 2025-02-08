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

class IsoFileTestUdf extends TestCase
{
    #[DataProvider('isoFilesDataProvider')]
    public function testConstructorExistingUdfFile(string $testFile, int $descriptorCount): void
    {
        $isoFile = new IsoFile($testFile);
        $this->assertInstanceOf(IsoFile::class, $isoFile);
        $this->assertCount($descriptorCount, $isoFile->descriptors);
    }

    public function testDescriptorsTestIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/test.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(5, $isoFile->descriptors);

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
        $this->assertInstanceOf(Carbon::class, $primaryVolumeDescriptor->creationDate);
        $this->assertSame(Carbon::create(2025, 1, 12, 15, 0, 53, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->creationDate->toDateTimeString());
        $this->assertInstanceOf(Carbon::class, $primaryVolumeDescriptor->modificationDate);
        $this->assertSame(Carbon::create(2025, 1, 12, 15, 0, 53, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->modificationDate->toDateTimeString());
        $this->assertNotInstanceOf(Carbon::class, $primaryVolumeDescriptor->expirationDate);
        $this->assertNotInstanceOf(Carbon::class, $primaryVolumeDescriptor->effectiveDate);

        // check root directory
        $rootDirectory = $primaryVolumeDescriptor->rootDirectory;
        $this->assertSame('.', $rootDirectory->fileId);
        $this->assertTrue($rootDirectory->isDirectory());
        $this->assertFalse($rootDirectory->isHidden());
        $this->assertFalse($rootDirectory->isAssociated());
        $this->assertFalse($rootDirectory->isRecord());
        $this->assertFalse($rootDirectory->isProtected());
        $this->assertFalse($rootDirectory->isMultiExtent());
        $this->assertTrue($rootDirectory->isThis());
        $this->assertFalse($rootDirectory->isParent());
        $this->assertInstanceOf(Carbon::class, $rootDirectory->recordingDate);
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
                'CLASSES/',
                'COMPOSER.JSO',
                'EXAMPLES/',
            ],
            DIRECTORY_SEPARATOR . 'CLASSES' . DIRECTORY_SEPARATOR => [
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
            DIRECTORY_SEPARATOR . 'EXAMPLES' . DIRECTORY_SEPARATOR => [
                './',
                '../',
                'BOOTCATA.PHP',
                'ISO_BASE.PHP',
                'ISO_FILE.PHP',
            ],
        ];

        $this->assertSame($pathsExpected, $paths);

// TODO - add assert for UDF volumes
    }

    public function testDescriptorsUdfIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/udf.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(3, $isoFile->descriptors);

// TODO - add assert for UDF volumes
        $this->assertArrayHasKey(Type::UDF_BEA_VOLUME_DESC, $isoFile->descriptors);
        $this->assertArrayHasKey(Type::UDF_NSR3_VOLUME_DESC, $isoFile->descriptors);
        $this->assertArrayHasKey(Type::UDF_TEA_VOLUME_DESC, $isoFile->descriptors);
    }

    public function testDescriptorsIso9660UdfIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/iso9660_udf.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(6, $isoFile->descriptors);

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
        $this->assertSame('UDF Bridge', $primaryVolumeDescriptor->volumeId);
        $this->assertSame(460, $primaryVolumeDescriptor->volumeSpaceSize);
        $this->assertSame('GENISOIMAGE ISO 9660/HFS FILESYSTEM CREATOR (C) 1993 E.YOUNGDALE (C) 1997-2006 J.PEARSON/J.SCHILLING (C) 2006-2007 CDRKIT TEAM', $primaryVolumeDescriptor->appId);
        $this->assertInstanceOf(Carbon::class, $primaryVolumeDescriptor->creationDate);
        $this->assertSame(Carbon::create(2022, 4, 7, 20, 31, 13, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->creationDate->toDateTimeString());
        $this->assertInstanceOf(Carbon::class, $primaryVolumeDescriptor->modificationDate);
        $this->assertSame(Carbon::create(2022, 4, 7, 20, 31, 13, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->modificationDate->toDateTimeString());
        $this->assertNotInstanceOf(Carbon::class, $primaryVolumeDescriptor->expirationDate);
        $this->assertInstanceOf(Carbon::class, $primaryVolumeDescriptor->effectiveDate);
        $this->assertSame(Carbon::create(2022, 4, 7, 20, 31, 13, 'Europe/Paris')?->toDateTimeString(), $primaryVolumeDescriptor->effectiveDate->toDateTimeString());

        /** @var SupplementaryVolume $supplementaryVolumeDescriptor */
        $supplementaryVolumeDescriptor = $isoFile->descriptors[Type::SUPPLEMENTARY_VOLUME_DESC];

        $this->assertInstanceOf(SupplementaryVolume::class, $supplementaryVolumeDescriptor);

        $this->assertSame('Supplementary volume descriptor', $supplementaryVolumeDescriptor->name);
        $this->assertSame('CD001', $supplementaryVolumeDescriptor->stdId);
        $this->assertSame(1, $supplementaryVolumeDescriptor->version);
        $this->assertSame(1, $supplementaryVolumeDescriptor->fileStructureVersion);
// TODO - fix encoding
//        $this->assertSame('LINUX', $supplementaryVolumeDescriptor->systemId);
        $this->assertSame('UDF Bridge', $supplementaryVolumeDescriptor->volumeId);
        $this->assertSame(460, $supplementaryVolumeDescriptor->volumeSpaceSize);
// TODO - fix encoding
//        $this->assertSame('GENISOIMAGE ISO 9660_HFS FILESYSTEM CREATOR (C) 1993 E.YOUNGDALE', $supplementaryVolumeDescriptor->appId);
        $this->assertInstanceOf(Carbon::class, $supplementaryVolumeDescriptor->creationDate);
        $this->assertSame(Carbon::create(2022, 4, 7, 20, 31, 13, 'Europe/Paris')?->toDateTimeString(), $supplementaryVolumeDescriptor->creationDate->toDateTimeString());
        $this->assertInstanceOf(Carbon::class, $supplementaryVolumeDescriptor->modificationDate);
        $this->assertSame(Carbon::create(2022, 4, 7, 20, 31, 13, 'Europe/Paris')?->toDateTimeString(), $supplementaryVolumeDescriptor->modificationDate->toDateTimeString());
        $this->assertNotInstanceOf(Carbon::class, $supplementaryVolumeDescriptor->expirationDate);
        $this->assertInstanceOf(Carbon::class, $supplementaryVolumeDescriptor->effectiveDate);
        $this->assertSame(Carbon::create(2022, 4, 7, 20, 31, 13, 'Europe/Paris')?->toDateTimeString(), $supplementaryVolumeDescriptor->effectiveDate->toDateTimeString());

// TODO - add assert for UDF volumes
        $this->assertArrayHasKey(Type::UDF_BEA_VOLUME_DESC, $isoFile->descriptors);
        $this->assertArrayHasKey(Type::UDF_NSR2_VOLUME_DESC, $isoFile->descriptors);
        $this->assertArrayHasKey(Type::UDF_TEA_VOLUME_DESC, $isoFile->descriptors);
    }

    public function testDescriptorsIso9660UdfImgburnIso(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/iso9660_udf_imgburn.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertCount(6, $isoFile->descriptors);

// TODO - add assert for UDF volumes
        $this->assertArrayHasKey(Type::UDF_BEA_VOLUME_DESC, $isoFile->descriptors);
        $this->assertArrayHasKey(Type::UDF_NSR2_VOLUME_DESC, $isoFile->descriptors);
        $this->assertArrayHasKey(Type::UDF_TEA_VOLUME_DESC, $isoFile->descriptors);
    }

    public static function isoFilesDataProvider(): Iterator
    {
        yield [dirname(__FILE__, 2) . '/fixtures/test.iso', 5];
        yield [dirname(__FILE__, 2) . '/fixtures/iso9660_udf.iso', 6];
        yield [dirname(__FILE__, 2) . '/fixtures/iso9660_udf_hfs.iso', 6];
        yield [dirname(__FILE__, 2) . '/fixtures/udf.iso', 3];
        yield [dirname(__FILE__, 2) . '/fixtures/iso9660_udf_imgburn.iso', 6];
    }
}
