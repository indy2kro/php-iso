<?php

declare(strict_types=1);

namespace PhpIso\Test\Descriptor;

use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\BootEntry;
use PhpIso\Exception;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PHPUnit\Framework\TestCase;

final class BootCatalogTest extends TestCase
{
    private function load(): BootCatalog
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/DOS4.01_bootdisk.iso');
        $boot = $isoFile->getBootRecord();
        $this->assertInstanceOf(\PhpIso\Descriptor\Boot::class, $boot);

        $catalog = $boot->loadCatalog($isoFile);
        $this->assertInstanceOf(\PhpIso\Descriptor\BootCatalog::class, $catalog);

        return $catalog;
    }

    public function testCatalogHasValidChecksum(): void
    {
        $this->assertTrue($this->load()->validChecksum);
    }

    public function testDefaultEntryIsBootable144Floppy(): void
    {
        $entry = $this->load()->getDefaultEntry();

        $this->assertInstanceOf(\PhpIso\Descriptor\BootEntry::class, $entry);
        $this->assertTrue($entry->bootable);
        $this->assertSame(BootEntry::MEDIA_FLOPPY_1_44, $entry->mediaType);
        $this->assertSame('1.44 MB floppy', $entry->getMediaName());
    }

    public function testDefaultEntryPointsToBootImage(): void
    {
        $entry = $this->load()->getDefaultEntry();

        $this->assertInstanceOf(\PhpIso\Descriptor\BootEntry::class, $entry);
        $this->assertSame(25, $entry->loadRba);
        $this->assertSame('x86', $entry->getPlatformName());
    }

    public function testLoadInvalidLocationThrows(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/DOS4.01_bootdisk.iso');

        $this->expectException(Exception::class);

        BootCatalog::load($isoFile, 0);
    }

    public function testIsoWithoutBootRecordHasNone(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/subdir.iso');

        $this->assertNull($isoFile->getBootRecord());
    }

    public function testImageSizeOfFloppyEmulation(): void
    {
        $this->assertSame(1228800, (new BootEntry(true, BootEntry::MEDIA_FLOPPY_1_2, 0, 0, 1, 0, 0))->getImageSize());
        $this->assertSame(1474560, (new BootEntry(true, BootEntry::MEDIA_FLOPPY_1_44, 0, 0, 1, 0, 0))->getImageSize());
        $this->assertSame(2949120, (new BootEntry(true, BootEntry::MEDIA_FLOPPY_2_88, 0, 0, 1, 0, 0))->getImageSize());
    }

    public function testImageSizeOfNoEmulationUsesVirtualSectors(): void
    {
        $entry = new BootEntry(true, BootEntry::MEDIA_NO_EMULATION, 0, 0, 4, 0, 0);

        $this->assertSame(BootEntry::MEDIA_NO_EMULATION, $entry->getEmulationType());
        $this->assertSame(2048, $entry->getImageSize());
    }

    public function testImageSizeOfHardDiskFallsBackToTheSectorCount(): void
    {
        $this->assertSame(1024, (new BootEntry(true, BootEntry::MEDIA_HARD_DISK, 0, 0, 2, 0, 0))->getImageSize());
    }

    public function testExtractImageToAStream(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/DOS4.01_bootdisk.iso');
        $catalog = $this->load();
        $entry = $catalog->getDefaultEntry();
        $this->assertInstanceOf(BootEntry::class, $entry);

        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        $catalog->extractImage($isoFile, $entry, $stream);

        $this->assertSame(1474560, ftell($stream));
        fclose($stream);
    }

    public function testExtractImageOutsideOfTheIsoThrows(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/DOS4.01_bootdisk.iso');
        $entry = new BootEntry(true, BootEntry::MEDIA_NO_EMULATION, 0, 0, 1, 99999999, 0);
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);

        $this->expectException(Exception::class);

        $this->load()->extractImage($isoFile, $entry, $stream);
    }

    public function testExtractHardDiskImageUsesThePartitionTable(): void
    {
        $mbr = str_repeat("\0", 446) . "\0\0\0\0" . chr(0x83) . "\0\0\0" . pack('V', 1) . pack('V', 3) . str_repeat("\0", 48) . "\x55\xAA";
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addBootRecord(1, 20)
            ->addTerminator(2)
            ->setSector(20, IsoBuilder::bootCatalog(0, BootEntry::MEDIA_HARD_DISK, 30))
            ->setSector(30, $mbr . str_repeat('D', 2048 - 512))
            ->setSector(31, str_repeat('E', 2048));
        $path = $builder->save();

        try {
            $isoFile = new IsoFile($path);
            $boot = $isoFile->getBootRecord();
            $this->assertInstanceOf(\PhpIso\Descriptor\Boot::class, $boot);
            $catalog = $boot->loadCatalog($isoFile);
            $this->assertInstanceOf(BootCatalog::class, $catalog);
            $entry = $catalog->getDefaultEntry();
            $this->assertInstanceOf(BootEntry::class, $entry);

            $target = $path . '.img';
            $catalog->extractImage($isoFile, $entry, $target);

            // the partition ends at sector 1 + 3
            $this->assertSame(4 * 512, filesize($target));
            unlink($target);
        } finally {
            unlink($path);
        }
    }

    public function testCatalogSpanningSeveralSectorsIsRead(): void
    {
        // default entry + one section with 70 entries: more than the 64 entries of a sector
        $catalog = IsoBuilder::bootCatalog(0, 2, 25, [IsoBuilder::bootSection(0xEF, 70)]);
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addBootRecord(1, 20)
            ->addTerminator(2)
            ->setSector(20, substr($catalog, 0, 2048))
            ->setSector(21, substr($catalog, 2048));
        $path = $builder->save();

        try {
            $loaded = BootCatalog::load(new IsoFile($path), 20);

            $this->assertCount(71, $loaded->entries);
            $this->assertSame(0xEF, $loaded->entries[70]->platformId);
        } finally {
            unlink($path);
        }
    }
}
