<?php

declare(strict_types=1);

namespace PhpIso\Test\Descriptor;

use Iterator;
use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\BootEntry;
use PhpIso\Descriptor\Partition;
use PhpIso\Descriptor\PrimaryVolume;
use PhpIso\Descriptor\Type;
use PhpIso\Exception;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptorsTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function open(IsoBuilder $builder): IsoFile
    {
        $path = $builder->save();
        $this->files[] = $path;

        return new IsoFile($path);
    }

    private function bootImage(string $catalog, string $systemId = 'EL TORITO SPECIFICATION'): IsoFile
    {
        return $this->open(
            (new IsoBuilder())
                ->addVolumeDescriptor(0)
                ->addBootRecord(1, 20, $systemId)
                ->addTerminator(2)
                ->setSector(20, $catalog)
        );
    }

    private function catalog(IsoFile $isoFile): BootCatalog
    {
        $boot = $isoFile->getBootRecord();
        $this->assertInstanceOf(Boot::class, $boot);

        $catalog = $boot->loadCatalog($isoFile);
        $this->assertInstanceOf(BootCatalog::class, $catalog);

        return $catalog;
    }

    public function testPartitionDescriptorIsRead(): void
    {
        $isoFile = $this->open((new IsoBuilder())->addVolumeDescriptor(0)->addPartition(1, 'SYS', 'PART1', 100, 200)->addTerminator(2));

        $partition = $isoFile->descriptors[Type::PARTITION_VOLUME_DESC];

        $this->assertInstanceOf(Partition::class, $partition);
        $this->assertSame('PART1', trim($partition->volPartitionID));
        $this->assertSame(100, $partition->volPartitionLocation);
        $this->assertSame(200, $partition->volPartitionSize);
    }

    public function testTruncatedVolumeDescriptorIsRejected(): void
    {
        $bytes = [1, 2, 3];
        $offset = 1;

        $this->expectException(Exception::class);

        new PrimaryVolume('CD001', 1, $bytes, $offset);
    }

    public function testTruncatedBootRecordIsRejected(): void
    {
        $bytes = [1, 2, 3];
        $offset = 1;

        $this->expectException(Exception::class);

        new Boot('CD001', 1, $bytes, $offset);
    }

    public function testVolumeWithoutRootDirectoryRecordIsRejected(): void
    {
        // a primary volume descriptor whose root directory record is empty
        $sector = (new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1)->build();
        /** @var array<int, int>|false $bytes */
        $bytes = unpack('C*', substr($sector, 16 * 2048, 2048));
        $this->assertIsArray($bytes);
        for ($i = 157; $i <= 190; $i++) {
            $bytes[$i] = 0;
        }
        $offset = 8;

        $this->expectException(Exception::class);

        new PrimaryVolume('CD001', 1, $bytes, $offset);
    }
    public function testNonElToritoBootRecordHasNoCatalog(): void
    {
        $isoFile = $this->bootImage(IsoBuilder::bootCatalog(), 'SOMETHING ELSE');
        $boot = $isoFile->getBootRecord();
        $this->assertInstanceOf(Boot::class, $boot);

        $this->assertFalse($boot->isElTorito());
        $this->assertNotInstanceOf(BootCatalog::class, $boot->loadCatalog($isoFile));
    }

    public function testBootCatalogWithSections(): void
    {
        $catalog = $this->catalog($this->bootImage(IsoBuilder::bootCatalog(0, 2, 25, [IsoBuilder::bootSection(0xEF, 2)])));

        $this->assertCount(3, $catalog->entries);
        $this->assertSame('EFI', $catalog->entries[1]->getPlatformName());
        $this->assertSame(40, $catalog->entries[2]->loadRba);
    }

    public function testBootCatalogWithSeveralSectionHeaders(): void
    {
        $sections = [IsoBuilder::bootSection(0, 1, false), IsoBuilder::bootSection(0xEF, 1)];

        $catalog = $this->catalog($this->bootImage(IsoBuilder::bootCatalog(0, 2, 25, $sections)));

        $this->assertCount(3, $catalog->entries);
    }

    public function testBootCatalogChecksum(): void
    {
        $this->assertTrue($this->catalog($this->bootImage(IsoBuilder::bootCatalog()))->validChecksum);
        $this->assertFalse($this->catalog($this->bootImage(IsoBuilder::bootCatalog(0, 2, 25, [], false)))->validChecksum);
    }

    public function testBootCatalogManufacturer(): void
    {
        $this->assertSame('TEST', $this->catalog($this->bootImage(IsoBuilder::bootCatalog()))->manufacturer);
    }

    public function testBootCatalogWithoutValidationEntryIsRejected(): void
    {
        $isoFile = $this->bootImage(str_repeat("\1", 64));
        $boot = $isoFile->getBootRecord();
        $this->assertInstanceOf(Boot::class, $boot);

        $this->expectException(Exception::class);

        $boot->loadCatalog($isoFile);
    }

    public function testBootCatalogBeyondTheEndOfTheFileIsRejected(): void
    {
        $this->expectException(Exception::class);

        BootCatalog::load($this->bootImage(IsoBuilder::bootCatalog()), 5000);
    }

    /**
     * @return Iterator<string, array{int, string}>
     */
    public static function mediaNames(): Iterator
    {
        yield 'no emulation' => [BootEntry::MEDIA_NO_EMULATION, 'No emulation'];
        yield '1.2 floppy' => [BootEntry::MEDIA_FLOPPY_1_2, '1.2 MB floppy'];
        yield '1.44 floppy' => [BootEntry::MEDIA_FLOPPY_1_44, '1.44 MB floppy'];
        yield '2.88 floppy' => [BootEntry::MEDIA_FLOPPY_2_88, '2.88 MB floppy'];
        yield 'hard disk' => [BootEntry::MEDIA_HARD_DISK, 'Hard disk'];
        yield 'unknown' => [9, 'Unknown (9)'];
    }

    #[DataProvider('mediaNames')]
    public function testMediaName(int $media, string $expected): void
    {
        $this->assertSame($expected, (new BootEntry(true, $media, 0, 0, 1, 1, 0))->getMediaName());
    }

    /**
     * @return Iterator<string, array{int, string}>
     */
    public static function platformNames(): Iterator
    {
        yield 'x86' => [0, 'x86'];
        yield 'powerpc' => [1, 'PowerPC'];
        yield 'mac' => [2, 'Mac'];
        yield 'efi' => [0xEF, 'EFI'];
        yield 'unknown' => [7, 'Unknown (7)'];
    }

    #[DataProvider('platformNames')]
    public function testPlatformName(int $platform, string $expected): void
    {
        $this->assertSame($expected, (new BootEntry(true, 0, 0, 0, 1, 1, $platform))->getPlatformName());
    }
}
