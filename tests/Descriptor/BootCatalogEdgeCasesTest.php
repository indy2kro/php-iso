<?php

declare(strict_types=1);

namespace PhpIso\Test\Descriptor;

use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\BootEntry;
use PhpIso\Exception;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PHPUnit\Framework\TestCase;

/**
 * El Torito catalogs: extension entries, truncated catalogs and hard disk images
 */
final class BootCatalogEdgeCasesTest extends TestCase
{
    private const int CATALOG_SECTOR = 24;

    /**
     * @var array<int, string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function image(string $catalog, string $extraSector = ''): IsoFile
    {
        $builder = (new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1)->setSector(self::CATALOG_SECTOR, $catalog);
        if ($extraSector !== '') {
            $builder->setSector(30, $extraSector);
        }
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    public function testExtensionEntriesAreSkipped(): void
    {
        $extension = chr(0x44) . str_repeat("\0", 31);
        $section = IsoBuilder::bootSection(0xEF, 1);
        $catalog = IsoBuilder::bootCatalog() . $section . $extension . $extension;

        $loaded = BootCatalog::load($this->image($catalog), self::CATALOG_SECTOR);

        $this->assertCount(2, $loaded->entries);
        $this->assertSame(0xEF, $loaded->entries[1]->platformId);
    }

    public function testSectionClaimingMoreEntriesThanTheImageHoldsIsCut(): void
    {
        // the catalog is the last sector of the image and the section announces 200 entries
        $section = IsoBuilder::bootSection(0xEF, 1);
        $catalog = IsoBuilder::bootCatalog() . substr($section, 0, 2) . pack('v', 200) . substr($section, 4);

        $loaded = BootCatalog::load($this->image($catalog), self::CATALOG_SECTOR);

        $this->assertGreaterThanOrEqual(1, count($loaded->entries));
        $this->assertLessThan(200, count($loaded->entries));
    }

    public function testExtractingToAnUnwritablePathFails(): void
    {
        $catalog = IsoBuilder::bootCatalog(0, 0, 30);
        $isoFile = $this->image($catalog, 'boot');
        $loaded = BootCatalog::load($isoFile, self::CATALOG_SECTOR);
        $entry = $loaded->getDefaultEntry();
        $this->assertInstanceOf(BootEntry::class, $entry);

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to open file for writing');

            $loaded->extractImage($isoFile, $entry, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-missing-' . uniqid() . DIRECTORY_SEPARATOR . 'boot.img');
        } finally {
            restore_error_handler();
        }
    }

    public function testHardDiskImageWithoutAPartitionTableUsesTheCatalogSize(): void
    {
        $catalog = IsoBuilder::bootCatalog(0, BootEntry::MEDIA_HARD_DISK, 30);
        $isoFile = $this->image($catalog, 'boot');
        $loaded = BootCatalog::load($isoFile, self::CATALOG_SECTOR);
        $entry = $loaded->getDefaultEntry();
        $this->assertInstanceOf(BootEntry::class, $entry);

        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);
        $loaded->extractImage($isoFile, $entry, $output);
        rewind($output);

        // no MBR signature: the sector count of the catalog entry (1 sector of 512 bytes) is used
        $this->assertSame(512, strlen((string) stream_get_contents($output)));
    }

    public function testHardDiskImageWithAPartitionTableUsesItsSize(): void
    {
        // one partition of type 0x83 starting at sector 0 with 4 sectors of 512 bytes
        $mbr = str_repeat("\0", 446) . "\x00\x00\x00\x00" . "\x83" . "\x00\x00\x00" . pack('V', 0) . pack('V', 4) . str_repeat("\0", 48) . "\x55\xAA";
        $catalog = IsoBuilder::bootCatalog(0, BootEntry::MEDIA_HARD_DISK, 30);
        $isoFile = $this->image($catalog, $mbr);
        $loaded = BootCatalog::load($isoFile, self::CATALOG_SECTOR);
        $entry = $loaded->getDefaultEntry();
        $this->assertInstanceOf(BootEntry::class, $entry);

        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);
        $loaded->extractImage($isoFile, $entry, $output);
        rewind($output);

        $this->assertSame(2048, strlen((string) stream_get_contents($output)));
    }

    public function testHardDiskImageBeyondTheEndOfTheImageIsRejected(): void
    {
        $catalog = IsoBuilder::bootCatalog(0, BootEntry::MEDIA_HARD_DISK, 5000);
        $isoFile = $this->image($catalog);
        $loaded = BootCatalog::load($isoFile, self::CATALOG_SECTOR);
        $entry = $loaded->getDefaultEntry();
        $this->assertInstanceOf(BootEntry::class, $entry);

        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);

        $this->expectException(Exception::class);

        $loaded->extractImage($isoFile, $entry, $output);
    }
}
