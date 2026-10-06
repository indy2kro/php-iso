<?php

declare(strict_types=1);

namespace PhpIso\Test\Descriptor;

use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\BootEntry;
use PhpIso\Exception;
use PhpIso\IsoFile;
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
}
