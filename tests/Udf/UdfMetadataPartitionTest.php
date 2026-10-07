<?php

declare(strict_types=1);

namespace PhpIso\Test\Udf;

use Iterator;
use PhpIso\Exception;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\Udf\UdfFileSystem;
use PhpIso\Udf\UdfPartition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UDF 2.50 metadata partition maps, partition lengths and unsupported partition map types
 */
final class UdfMetadataPartitionTest extends TestCase
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

    private function udf(IsoFile $isoFile): UdfFileSystem
    {
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(UdfFileSystem::class, $udf);

        return $udf;
    }

    /**
     * @return array<string, string> path => content of the files
     */
    private function contents(IsoFile $isoFile): array
    {
        $udf = $this->udf($isoFile);
        $result = [];
        foreach ($udf->walk($isoFile) as $entry) {
            $result[$entry->path] = $entry->isDirectory ? '' : $udf->readFile($isoFile, $entry);
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tree(): array
    {
        return [
            'readme.txt' => 'hello metadata',
            'big.bin' => str_repeat('0123456789abcdef', 700),
            'dir' => ['a.txt' => 'a', 'sub' => ['deep.txt' => 'deep content']],
            'many' => array_combine(array_map(static fn (int $i): string => sprintf('file%03d.txt', $i), range(1, 60)), array_fill(0, 60, 'x')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function expected(): array
    {
        $expected = [];
        foreach (self::flatten(self::tree()) as $path => $content) {
            $expected[$path] = $content;
        }
        ksort($expected);

        return $expected;
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $base = ''): array
    {
        $result = [];
        foreach ($tree as $name => $content) {
            $path = $base . '/' . $name;
            if (is_array($content)) {
                $result[$path] = '';
                $result += self::flatten($content, $path);
            } elseif (is_string($content)) {
                $result[$path] = $content;
            }
        }

        return $result;
    }

    /**
     * @return Iterator<string, array{array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int}}>
     */
    public static function layouts(): Iterator
    {
        yield 'short descriptors' => [[]];
        yield 'long descriptors' => [['adType' => 1]];
        yield 'extended file entries' => [['extended' => true]];
        yield 'inline data' => [['inline' => 600]];
        yield 'allocation extents' => [['fragment' => true, 'maxAds' => 3]];
        yield 'allocation extents with long descriptors' => [['fragment' => true, 'maxAds' => 2, 'adType' => 1]];
    }

    /**
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int} $options
     */
    #[DataProvider('layouts')]
    public function testMetadataPartitionIsReadThroughTheMetadataFile(array $options): void
    {
        $isoFile = $this->open(UdfBuilder::build(self::tree(), $options + ['metadata' => true]));

        $this->assertSame(self::expected(), $this->contents($isoFile));
    }

    public function testFileSetDescriptorInTheMetadataPartitionIsFound(): void
    {
        $isoFile = $this->open(UdfBuilder::build(['a.txt' => 'a'], ['metadata' => true]));

        $this->assertSame('TESTUDF', $this->udf($isoFile)->volumeId);
        $this->assertSame(['/a.txt' => 'a'], $this->contents($isoFile));
    }

    public function testMetadataFileThatIsNotAFileIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a'], ['metadata' => true]);
        // the metadata file location points to the (empty) block 0 of the physical partition
        $builder->patch(34, 440 + 6 + 40, pack('V', 0))->patch(50, 440 + 6 + 40, pack('V', 0));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('metadata file');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testMetadataFileOutsideOfThePartitionIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a'], ['metadata' => true]);
        $builder->patch(34, 440 + 6 + 40, pack('V', 0xFFFFFFFF))->patch(50, 440 + 6 + 40, pack('V', 0xFFFFFFFF));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('metadata file');

        $this->open($builder)->getUdfFileSystem();
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function unsupportedMaps(): Iterator
    {
        yield 'sparable' => ['*UDF Sparable Partition'];
        yield 'virtual' => ['*UDF Virtual Partition'];
    }

    #[DataProvider('unsupportedMaps')]
    public function testSparableAndVirtualMapsNameTheirType(string $identifier): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $map = chr(2) . chr(64) . "\0\0" . "\0" . str_pad($identifier, 23, "\0") . str_repeat("\0", 8) . pack('v', 1) . pack('v', 1) . str_repeat("\0", 24);
        $builder->patch(34, 440, $map)->patch(50, 440, $map);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported UDF partition map (' . $identifier . ')');

        $this->open($builder)->getUdfFileSystem();
    }

    public function testPartitionTranslatesBlocksThroughItsRuns(): void
    {
        $physical = UdfPartition::physical(100, 50);
        $metadata = UdfPartition::metadata([[10 * 2048, 2 * 2048], [-1, 2048], [40 * 2048, 2048]], $physical);

        $this->assertSame(4, $metadata->blocks);
        $this->assertSame(10 * 2048, $metadata->offset(0));
        $this->assertSame(11 * 2048, $metadata->offset(1));
        $this->assertNull($metadata->offset(2), 'sparse block');
        $this->assertSame(40 * 2048, $metadata->offset(3));
        $this->assertNull($metadata->offset(4), 'beyond the partition');
        $this->assertSame([[11 * 2048, 2048], [-1, 2048], [40 * 2048, 52]], $metadata->ranges(1, 2 * 2048 + 52));
        $this->assertNull($metadata->ranges(1, 4 * 2048), 'leaves the partition');
        $this->assertSame($physical, $metadata->dataPartition());
        $this->assertSame(149 * 2048, $physical->offset(49));
        $this->assertNull($physical->offset(50));
    }
}
