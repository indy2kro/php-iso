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
 * Corrupt or crafted UDF structures must end in an exception or a smaller tree, never in a hang or a crash
 */
final class UdfHostileTest extends TestCase
{
    private const int PARTITION_START = 260;

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

    private function readSector(IsoFile $isoFile, int $sector): string
    {
        $isoFile->seek($sector * 2048);

        return (string) $isoFile->read(2048);
    }

    public function testDirectoryContainingItselfTerminates(): void
    {
        $isoFile = $this->open(UdfBuilder::build(['d' => ['x' => '1']], ['loop' => true]));

        $paths = $this->paths($isoFile);

        $this->assertContains('/d/x', $paths);
        $this->assertLessThan(10, count($paths));
    }

    public function testEntryWithACorruptTagIsSkipped(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a', 'b.txt' => 'b']);
        // block 2 holds the file entry of the first file
        $builder->setSector(self::PARTITION_START + 2, str_repeat("\xff", 64));

        $this->assertSame(['/b.txt'], $this->paths($this->open($builder)));
    }

    public function testAllocationExtentLoopIsSkipped(): void
    {
        $builder = UdfBuilder::build(['big.bin' => str_repeat('x', 5 * 2048)], ['fragment' => true, 'maxAds' => 2]);
        $isoFile = $this->open($builder);

        // find the allocation extent descriptor (tag 258) and make it continue into itself
        $loopBlock = 0;
        foreach (range(2, 40) as $block) {
            if (str_starts_with($this->readSector($isoFile, self::PARTITION_START + $block), "\x02\x01")) {
                $loopBlock = $block;
                break;
            }
        }
        $this->assertGreaterThan(0, $loopBlock);

        $descriptor = pack('V', 2048 | (3 << 30)) . pack('V', $loopBlock);
        $builder->setSector(self::PARTITION_START + $loopBlock, pack('vvCCvvvV', 258, 3, 0, 0, 1, 0, 0, self::PARTITION_START + $loopBlock) . pack('V', 0) . pack('V', 8) . $descriptor);

        // the broken file is skipped, the walk does not hang
        $this->assertSame([], $this->paths($this->open($builder)));
    }

    public function testExtentBeyondTheEndOfTheImageCannotBeRead(): void
    {
        $isoFile = $this->open(UdfBuilder::build(['a.txt' => 'abc']));
        $udf = $this->udf($isoFile);
        $broken = new IsoEntry('/a.txt', 'a.txt', false, 3, 0, null, false, [[PHP_INT_MAX - 10, 3]]);

        $this->expectException(Exception::class);

        $udf->readFile($isoFile, $broken);
    }

    public function testMissingVolumeDescriptorSequenceIsReported(): void
    {
        $builder = UdfBuilder::build(['a' => 'a']);
        foreach ([32, 33, 34, 35, 48, 49, 50, 51] as $sector) {
            $builder->setSector($sector, '');
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Incomplete UDF volume descriptor sequence');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testUnsupportedBlockSizeIsReported(): void
    {
        $builder = UdfBuilder::build(['a' => 'a']);
        $logical = str_pad(UdfBuilder::tag(6, 34), 212, "\0") . pack('V', 4096);
        $builder->setSector(34, $logical)->setSector(50, $logical);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported UDF logical block size');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testRootWithoutFileSetDescriptorIsReported(): void
    {
        $builder = UdfBuilder::build(['a' => 'a']);
        $builder->setSector(self::PARTITION_START, str_repeat("\xff", 64));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('file set descriptor not found');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testTruncatedImageHasNoUdf(): void
    {
        $path = (new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1)->save();
        $this->files[] = $path;

        $this->assertNotInstanceOf(UdfFileSystem::class, (new IsoFile($path))->getUdfFileSystem());
    }
}
