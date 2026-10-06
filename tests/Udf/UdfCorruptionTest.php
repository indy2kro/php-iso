<?php

declare(strict_types=1);

namespace PhpIso\Test\Udf;

use PhpIso\Exception;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\Udf\UdfFileSystem;
use PHPUnit\Framework\TestCase;

/**
 * Byte level corruptions of otherwise valid UDF images
 */
final class UdfCorruptionTest extends TestCase
{
    private const int PARTITION_START = 260;

    /**
     * Sector holding the file entry of the first file (block 2) and of the root directory (block 1)
     */
    private const FIRST_FILE = self::PARTITION_START + 2;
    private const ROOT = self::PARTITION_START + 1;

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

    private function udf(IsoFile $isoFile): UdfFileSystem
    {
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(UdfFileSystem::class, $udf);

        return $udf;
    }

    /**
     * @return array<int, string>
     */
    private function paths(IsoFile $isoFile): array
    {
        return array_map(static fn (IsoEntry $entry): string => $entry->path, iterator_to_array($this->udf($isoFile)->walk($isoFile), false));
    }

    public function testReserveSequenceIsUsedWhenTheMainOneIsOutsideOfTheImage(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(256, 20, pack('V', 999999));

        $this->assertSame(['/a.txt'], $this->paths($this->open($builder)));
    }

    public function testMissingPartitionIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(33, 22, pack('v', 9))->patch(49, 22, pack('v', 9));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('partition 1 not found');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testInvalidFileSetPartitionReferenceIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(34, 256, pack('v', 5))->patch(50, 256, pack('v', 5));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid partition reference');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testRootWithAnUnsupportedAllocationTypeHasNoEntries(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::ROOT, 34, pack('v', 2));

        $this->assertSame([], $this->paths($this->open($builder)));
    }

    public function testRootThatIsNotADirectoryHasNoEntries(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::ROOT, 27, chr(5));

        $this->assertSame([], $this->paths($this->open($builder)));
    }

    public function testFileWithAnUnsupportedAllocationTypeIsSkipped(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a', 'b.txt' => 'b']);
        $builder->patch(self::FIRST_FILE, 34, pack('v', 2));

        $this->assertSame(['/b.txt'], $this->paths($this->open($builder)));
    }

    public function testFileWithAnOversizedDescriptorAreaIsSkipped(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a', 'b.txt' => 'b']);
        $builder->patch(self::FIRST_FILE, 172, pack('V', 5000));

        $this->assertSame(['/b.txt'], $this->paths($this->open($builder)));
    }

    public function testEntriesPointingNowhereAreSkipped(): void
    {
        $isoFile = $this->open(UdfBuilder::build(['a.txt' => 'a'], ['ghosts' => true]));

        $this->assertSame(['/a.txt'], $this->paths($isoFile));
    }

    public function testTruncatedEntryListStopsTheDirectory(): void
    {
        $isoFile = $this->open(UdfBuilder::build(['a.txt' => 'a'], ['overrun' => true]));

        $this->assertSame(['/a.txt'], $this->paths($isoFile));
    }

    public function testDirectoryWithAnHugeSizeIsIgnored(): void
    {
        $builder = UdfBuilder::build(['d' => ['x.txt' => 'x'], 'a.txt' => 'a']);
        // the first child (block 2) is the directory "d"
        $builder->patch(self::FIRST_FILE, 56, pack('P', 1 << 40));

        $paths = $this->paths($this->open($builder));

        $this->assertContains('/d', $paths);
        $this->assertNotContains('/d/x.txt', $paths);
    }

    public function testDirectoryWhoseDataIsOutsideOfTheImageIsIgnored(): void
    {
        $builder = UdfBuilder::build(['d' => ['x.txt' => 'x'], 'a.txt' => 'a']);
        // the allocation descriptor of the directory "d" (short_ad at offset 176): position beyond the image
        $builder->patch(self::FIRST_FILE, 180, pack('V', 900000));

        $paths = $this->paths($this->open($builder));

        $this->assertContains('/a.txt', $paths);
        $this->assertNotContains('/d/x.txt', $paths);
    }

    public function testDescriptorListEndsAtAZeroLengthDescriptor(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'abc']);
        // announce room for three descriptors, only the first one is real
        $builder->patch(self::FIRST_FILE, 172, pack('V', 24));
        $isoFile = $this->open($builder);
        $udf = $this->udf($isoFile);

        $entry = $udf->find($isoFile, '/a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame('abc', $udf->readFile($isoFile, $entry));
    }

    public function testExtentsLongerThanTheFileAreClamped(): void
    {
        $builder = UdfBuilder::build(['a.txt' => str_repeat('x', 3 * 2048)], ['fragment' => true]);
        $builder->patch(self::FIRST_FILE, 56, pack('P', 2048));
        $isoFile = $this->open($builder);
        $udf = $this->udf($isoFile);

        $entry = $udf->find($isoFile, '/a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame(2048, strlen($udf->readFile($isoFile, $entry)));
    }

    public function testBrokenAllocationExtentTagSkipsTheFile(): void
    {
        $builder = UdfBuilder::build(['big.bin' => str_repeat('x', 5 * 2048)], ['fragment' => true, 'maxAds' => 2]);
        $isoFile = $this->open($builder);

        foreach (range(2, 40) as $block) {
            $isoFile->seek((self::PARTITION_START + $block) * 2048);
            if (str_starts_with((string) $isoFile->read(2), "\x02\x01")) {
                $builder->patch(self::PARTITION_START + $block, 0, pack('v', 0));
                break;
            }
        }

        $this->assertSame([], $this->paths($this->open($builder)));
    }

    public function testVolumeIdentifierWithABrokenLengthIsEmpty(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(32, 55, chr(0));

        $this->assertSame('', $this->udf($this->open($builder))->volumeId);
    }

    public function testNegativeTimeZoneIsUnderstood(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::FIRST_FILE, 84, pack('v', (1 << 12) | ((-120) & 0x0FFF)));
        $isoFile = $this->open($builder);
        $udf = $this->udf($isoFile);

        $entry = $udf->find($isoFile, '/a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame(-7200, $entry->recordingDate?->getOffset());
    }

    public function testUnspecifiedTimeZoneMeansUtc(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::FIRST_FILE, 84, pack('v', (1 << 12) | 0x801));
        $isoFile = $this->open($builder);
        $udf = $this->udf($isoFile);

        $entry = $udf->find($isoFile, '/a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame(0, $entry->recordingDate?->getOffset());
    }

    public function testInvalidDateIsIgnored(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::FIRST_FILE, 86, pack('v', 2024) . chr(13) . chr(40));
        $isoFile = $this->open($builder);
        $udf = $this->udf($isoFile);

        $entry = $udf->find($isoFile, '/a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertNotInstanceOf(\Carbon\Carbon::class, $entry->recordingDate);
    }

    public function testSparseExtentsAreReadAsZeros(): void
    {
        $content = 'head' . str_repeat("\0", 2048 - 4) . str_repeat("\0", 20000) . 'tail';
        $isoFile = $this->open(UdfBuilder::build(['sparse.bin' => $content], ['sparse' => true, 'fragment' => true]));
        $udf = $this->udf($isoFile);
        $entry = $udf->find($isoFile, '/sparse.bin');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->assertSame($content, $udf->readFile($isoFile, $entry));
    }
}
