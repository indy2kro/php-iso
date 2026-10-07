<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\BootCatalog;
use PhpIso\Descriptor\Volume;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\UdfBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The synthetic builders of tests/Support encode fields with the parser's own assumptions. Here the encoding is
 * compared with the bytes written by xorriso / genisoimage / WinISO in the real fixtures, so a mistake shared by
 * the builder and the parser (like the old Partition one) is caught.
 */
final class BuilderCrossCheckTest extends TestCase
{
    private const int SECTOR = 2048;

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

    private function sector(string $fixture, int $sector): string
    {
        $data = file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture, false, null, $sector * self::SECTOR, self::SECTOR);
        $this->assertIsString($data);

        return $data;
    }

    private function open(IsoBuilder $builder): IsoFile
    {
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    private function volume(?Volume $volume): Volume
    {
        $this->assertInstanceOf(Volume::class, $volume);

        return $volume;
    }

    /**
     * Rebuild the volume descriptor of a real image from its parsed numbers and compare the bytes and the parsed result
     */
    private function assertVolumeDescriptorMatches(string $fixture, int $index, Volume $real, int $type): void
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0, $type, $real->rootDirectory->location, $real->rootDirectory->dataLength, $real->pathTableSize, $real->lPathTablePos, $real->blockSize, $real->jolietLevel, $real->mPathTablePos)
            ->addTerminator(1);

        $built = $this->open($builder);
        $builtDescriptor = $built->descriptors[$type] ?? null;
        $this->assertInstanceOf(Volume::class, $builtDescriptor);

        // the numeric fields: set size .. optional M path table, then the root directory record without its date
        $expected = $this->sector($fixture, 16 + $index);
        $actual = (string) file_get_contents($this->cleanupPath($built), false, null, 16 * self::SECTOR, self::SECTOR);
        $this->assertSame(bin2hex(substr($expected, 120, 36)), bin2hex(substr($actual, 120, 36)), 'set size, block size and path tables');
        $this->assertSame(bin2hex(substr($expected, 156, 18)), bin2hex(substr($actual, 156, 18)), 'root record up to its date');
        $this->assertSame(bin2hex(substr($expected, 156 + 25, 9)), bin2hex(substr($actual, 156 + 25, 9)), 'root record after its date');
        $this->assertSame(bin2hex(substr($expected, 0, 6)), bin2hex(substr($actual, 0, 6)), 'type and identifier');

        $this->assertSame($real->volumeSetSize, $builtDescriptor->volumeSetSize);
        $this->assertSame($real->volumeSeqNum, $builtDescriptor->volumeSeqNum);
        $this->assertSame($real->blockSize, $builtDescriptor->blockSize);
        $this->assertSame($real->pathTableSize, $builtDescriptor->pathTableSize);
        $this->assertSame($real->lPathTablePos, $builtDescriptor->lPathTablePos);
        $this->assertSame($real->mPathTablePos, $builtDescriptor->mPathTablePos);
        $this->assertSame($real->rootDirectory->location, $builtDescriptor->rootDirectory->location);
        $this->assertSame($real->rootDirectory->dataLength, $builtDescriptor->rootDirectory->dataLength);
        $this->assertSame($real->rootDirectory->flags, $builtDescriptor->rootDirectory->flags);
        $this->assertSame($real->rootDirectory->fileId, $builtDescriptor->rootDirectory->fileId);
        $this->assertSame($real->jolietLevel, $builtDescriptor->jolietLevel);
    }

    private function cleanupPath(IsoFile $isoFile): string
    {
        // the temporary file of the last built image
        $path = end($this->cleanup);
        $this->assertIsString($path);

        return $path;
    }

    public function testPrimaryVolumeDescriptor(): void
    {
        $real = $this->volume($this->volumeOf('no_extension.iso', 1));

        $this->assertVolumeDescriptorMatches('no_extension.iso', 0, $real, 1);
    }

    public function testPrimaryVolumeDescriptorWithPathTables(): void
    {
        // a real image with non zero path table positions (both byte orders)
        $real = $this->volume($this->volumeOf('rockridge.iso', 1));
        $this->assertGreaterThan(0, $real->lPathTablePos);
        $this->assertGreaterThan(0, $real->mPathTablePos);

        $this->assertVolumeDescriptorMatches('rockridge.iso', 0, $real, 1);
    }

    public function testJolietSupplementaryVolumeDescriptor(): void
    {
        $real = $this->volume($this->volumeOf('joliet_cjk.iso', 2));
        $this->assertSame(3, $real->jolietLevel);

        $this->assertVolumeDescriptorMatches('joliet_cjk.iso', 1, $real, 2);

        // the escape sequence "%/E" (level 3) is at the same place in the real descriptor
        $this->assertSame('%/E', substr($this->sector('joliet_cjk.iso', 17), 88, 3));
    }

    public function testEnhancedVolumeDescriptor(): void
    {
        $real = $this->volume($this->volumeOf('iso1999.iso', 2));

        $this->assertVolumeDescriptorMatches('iso1999.iso', 1, $real, 2);
        // an EVD is a type 2 descriptor with version 2, the builder writes version 1 for every type
        $this->assertSame(2, ord($this->sector('iso1999.iso', 17)[6]));
    }

    public function testVolumeDescriptorTerminator(): void
    {
        $built = (new IsoBuilder())->addTerminator(0)->build();

        $this->assertSame(bin2hex($this->sector('no_extension.iso', 17)), bin2hex(substr($built, 16 * self::SECTOR, self::SECTOR)));
    }

    public function testBootRecordDescriptor(): void
    {
        $real = (new IsoFile(dirname(__DIR__) . '/fixtures/DOS4.01_bootdisk.iso'))->getBootRecord();
        $this->assertInstanceOf(Boot::class, $real);

        $built = (new IsoBuilder())->addBootRecord(0, $real->bootCatalogLocation)->build();
        $expected = $this->sector('DOS4.01_bootdisk.iso', 17);

        // identifier, version, boot system identifier, unused bytes and the catalog pointer
        $this->assertSame(bin2hex(substr($expected, 0, 0x4B)), bin2hex(substr($built, 16 * self::SECTOR, 0x4B)));
    }

    public function testBootCatalog(): void
    {
        $fixture = new IsoFile(dirname(__DIR__) . '/fixtures/DOS4.01_bootdisk.iso');
        $boot = $fixture->getBootRecord();
        $this->assertInstanceOf(Boot::class, $boot);
        $real = BootCatalog::load($fixture, $boot->bootCatalogLocation);
        $realEntry = $real->getDefaultEntry();
        $this->assertInstanceOf(\PhpIso\Descriptor\BootEntry::class, $realEntry);
        $this->assertTrue($real->validChecksum);

        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setSector(24, IsoBuilder::bootCatalog($real->platformId, $realEntry->mediaType, $realEntry->loadRba));
        $built = BootCatalog::load($this->open($builder), 24);
        $builtEntry = $built->getDefaultEntry();
        $this->assertInstanceOf(\PhpIso\Descriptor\BootEntry::class, $builtEntry);

        $this->assertSame($real->platformId, $built->platformId);
        $this->assertTrue($built->validChecksum);
        $this->assertSame($realEntry->bootable, $builtEntry->bootable);
        $this->assertSame($realEntry->mediaType, $builtEntry->mediaType);
        $this->assertSame($realEntry->loadSegment, $builtEntry->loadSegment);
        $this->assertSame($realEntry->systemType, $builtEntry->systemType);
        $this->assertSame($realEntry->sectorCount, $builtEntry->sectorCount);
        $this->assertSame($realEntry->loadRba, $builtEntry->loadRba);

        // bytes: validation entry header, key bytes and the whole default entry (the manufacturer and the checksum differ)
        $expected = $this->sector('DOS4.01_bootdisk.iso', $boot->bootCatalogLocation);
        $actual = IsoBuilder::bootCatalog($real->platformId, $realEntry->mediaType, $realEntry->loadRba);
        $this->assertSame(bin2hex(substr($expected, 0, 4)), bin2hex(substr($actual, 0, 4)));
        $this->assertSame(bin2hex(substr($expected, 30, 34)), bin2hex(substr($actual, 30, 34)));
        $this->assertSame(0, array_sum(unpack('v16', substr($actual, 0, 32)) ?: []) & 0xFFFF);
        $this->assertSame(0, array_sum(unpack('v16', substr($expected, 0, 32)) ?: []) & 0xFFFF);
    }

    public function testPartitionDescriptorUsesBothByteOrders(): void
    {
        // ECMA-119 8.6.7 / 8.6.8: the location and the size are 7.3.3 values (little endian, then big endian)
        $built = (new IsoBuilder())->addPartition(0, 'SYS', 'PART', 0x12, 0x01020304)->build();
        $descriptor = substr($built, 16 * self::SECTOR, 88);

        $this->assertSame('12000000' . '00000012', bin2hex(substr($descriptor, 72, 8)));
        $this->assertSame('04030201' . '01020304', bin2hex(substr($descriptor, 80, 8)));

        $isoFile = $this->open((new IsoBuilder())->addPartition(0, 'SYS', 'PART', 0x12, 0x01020304)->addTerminator(1));
        $this->assertInstanceOf(\PhpIso\Descriptor\Partition::class, $isoFile->descriptors[3]);
        $this->assertSame(0x12, $isoFile->descriptors[3]->volPartitionLocation);
        $this->assertSame(0x01020304, $isoFile->descriptors[3]->volPartitionSize);
    }

    public function testDirectoryRecords(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/no_extension.iso');
        $volume = $this->volume($isoFile->getPrimaryVolume());
        $directory = $this->sector('no_extension.iso', $volume->rootDirectory->location);

        // "." record, ".." record and then the files in order
        $dot = IsoBuilder::record("\0", $volume->rootDirectory->location, $volume->rootDirectory->dataLength, 2);
        $this->assertSame(bin2hex(self::withoutDate(substr($directory, 0, strlen($dot)))), bin2hex(self::withoutDate($dot)));

        $offset = 34;
        $parent = IsoBuilder::record("\1", $volume->rootDirectory->location, $volume->rootDirectory->dataLength, 2);
        $this->assertSame(bin2hex(self::withoutDate(substr($directory, $offset, strlen($parent)))), bin2hex(self::withoutDate($parent)));

        $offset += 34;
        $seen = 0;
        foreach ($volume->walk($isoFile) as $entry) {
            $length = ord($directory[$offset]);
            // the identifier as written by the tool (level 1 names keep the separator dot)
            $id = substr($directory, $offset + 33, ord($directory[$offset + 32]));
            $rebuilt = IsoBuilder::record($id, $entry->location, $entry->size, 0);

            $this->assertSame(bin2hex(self::withoutDate(substr($directory, $offset, $length))), bin2hex(self::withoutDate($rebuilt)), $entry->path);
            $offset += $length;
            $seen++;
        }

        $this->assertSame(3, $seen);
    }

    public function testUdfDescriptorTags(): void
    {
        $real = $this->sector('udf.iso', 256);
        $built = UdfBuilder::tag(2, 256);

        // identifier and location; a tag checksum (byte 4) is the sum of the other 15 bytes
        $this->assertSame(bin2hex(substr($real, 0, 2)), bin2hex(substr($built, 0, 2)));
        $this->assertSame(bin2hex(substr($real, 12, 4)), bin2hex(substr($built, 12, 4)));
        foreach ([$real, $built] as $tag) {
            $sum = 0;
            for ($i = 0; $i < 16; $i++) {
                $sum += $i === 4 ? 0 : ord($tag[$i]);
            }
            $this->assertSame($sum & 0xFF, ord($tag[4]));
        }
    }

    private static function withoutDate(string $record): string
    {
        // the recording date (7 bytes at offset 18) is the build time in a real image
        return substr($record, 0, 18) . substr($record, 25);
    }

    private function volumeOf(string $fixture, int $type): ?Volume
    {
        $descriptor = (new IsoFile(dirname(__DIR__) . '/fixtures/' . $fixture))->descriptors[$type] ?? null;

        return $descriptor instanceof Volume ? $descriptor : null;
    }
}
