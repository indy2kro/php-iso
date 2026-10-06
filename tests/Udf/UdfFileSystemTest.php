<?php

declare(strict_types=1);

namespace PhpIso\Test\Udf;

use Iterator;
use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\IsoTree;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\Udf\UdfFileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UdfFileSystemTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            $this->remove($path);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
                $this->remove($path . DIRECTORY_SEPARATOR . $name);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function openBuilder(IsoBuilder $builder): IsoFile
    {
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool, mapType?: int} $options
     */
    private function open(array $tree, array $options = []): IsoFile
    {
        return $this->openBuilder(UdfBuilder::build($tree, $options));
    }

    private function udf(IsoFile $isoFile): UdfFileSystem
    {
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(UdfFileSystem::class, $udf);

        return $udf;
    }

    /**
     * @return Iterator<string, array{array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool}}>
     */
    public static function layouts(): Iterator
    {
        yield 'short descriptors' => [[]];
        yield 'long descriptors' => [['adType' => 1]];
        yield 'extended file entries' => [['extended' => true]];
        yield 'inline data' => [['inline' => 600]];
        yield 'inline data with long descriptors' => [['inline' => 600, 'adType' => 1, 'extended' => true]];
        yield 'one extent per block' => [['fragment' => true]];
        yield 'allocation extents' => [['fragment' => true, 'maxAds' => 3]];
        yield 'allocation extents with long descriptors' => [['fragment' => true, 'maxAds' => 2, 'adType' => 1]];
        yield 'sparse blocks' => [['sparse' => true, 'fragment' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sampleTree(): array
    {
        return [
            'readme.txt' => 'hello udf',
            'empty.bin' => '',
            'big.bin' => str_repeat('0123456789abcdef', 700),
            'holes.bin' => 'start' . str_repeat("\0", 2048 - 5) . str_repeat("\0", 2048) . 'end',
            'dir' => ['a.txt' => 'a', 'sub' => ['deep.txt' => 'deep content'], 'émoji-日本語.txt' => 'unicode'],
            'many' => array_combine(array_map(static fn (int $i): string => sprintf('file%03d.txt', $i), range(1, 120)), array_fill(0, 120, 'x')),
        ];
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, string|null> path => content (null for directories)
     */
    private static function expected(array $tree, string $base = ''): array
    {
        $result = [];
        foreach ($tree as $name => $content) {
            $path = $base . '/' . $name;
            if (is_array($content)) {
                $result[$path] = null;
                $result += self::expected($content, $path);
            } elseif (is_string($content)) {
                $result[$path] = $content;
            }
        }

        return $result;
    }

    /**
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool} $options
     */
    #[DataProvider('layouts')]
    public function testWalkAndContentMatchTheTree(array $options): void
    {
        $tree = self::sampleTree();
        $isoFile = $this->open($tree, $options);
        $udf = $this->udf($isoFile);

        $seen = [];
        foreach ($udf->walk($isoFile) as $entry) {
            $seen[$entry->path] = $entry->isDirectory ? null : $udf->readFile($isoFile, $entry);
        }

        $this->assertEquals(self::expected($tree), $seen);
    }

    /**
     * @param array{adType?: int, extended?: bool, inline?: int, fragment?: bool, maxAds?: int, sparse?: bool} $options
     */
    #[DataProvider('layouts')]
    public function testEntriesReportSizeAndDate(array $options): void
    {
        $isoFile = $this->open(['a.txt' => 'abcde'], $options);
        $udf = $this->udf($isoFile);

        $entry = $udf->find($isoFile, '/a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame(5, $entry->size);
        $this->assertSame('2024-05-06 07:08:09', $entry->recordingDate?->toDateTimeString());
        $this->assertSame(3600, $entry->recordingDate->getOffset());
    }

    public function testVolumeIdentifierIsRead(): void
    {
        $this->assertSame('TESTUDF', $this->udf($this->open([]))->volumeId);
    }

    public function testEmptyImageHasNoEntries(): void
    {
        $isoFile = $this->open([]);

        $this->assertSame([], iterator_to_array($this->udf($isoFile)->walk($isoFile), false));
    }

    public function testUdfOnlyImageIsBrowsableThroughGetFileSystem(): void
    {
        $isoFile = $this->open(['a.txt' => 'a']);

        $this->assertNull($isoFile->getPreferredVolume());
        $this->assertInstanceOf(UdfFileSystem::class, $isoFile->getFileSystem());
    }

    public function testFindIsCaseInsensitive(): void
    {
        $isoFile = $this->open(['Docs' => ['ReadMe.TXT' => 'x']]);

        $this->assertInstanceOf(IsoEntry::class, $this->udf($isoFile)->find($isoFile, 'docs\\readme.txt'));
    }

    public function testSearchByPattern(): void
    {
        $isoFile = $this->open(['a.txt' => '1', 'b.log' => '2', 'd' => ['c.TXT' => '3']]);

        $paths = array_map(static fn (IsoEntry $entry): string => $entry->path, iterator_to_array($this->udf($isoFile)->search($isoFile, '*.txt'), false));

        $this->assertEqualsCanonicalizing(['/a.txt', '/d/c.TXT'], $paths);
    }

    public function testExtractorWritesTheTree(): void
    {
        $tree = ['dir' => ['a.txt' => 'alpha'], 'b.txt' => 'beta'];
        $isoFile = $this->open($tree, ['fragment' => true]);
        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-udf-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;

        $count = (new Extractor())->extract($isoFile, $this->udf($isoFile), $destination);

        $this->assertSame(2, $count);
        $this->assertSame('alpha', file_get_contents($destination . DIRECTORY_SEPARATOR . 'dir' . DIRECTORY_SEPARATOR . 'a.txt'));
    }

    public function testDirectoriesCannotBeRead(): void
    {
        $isoFile = $this->open(['dir' => []]);
        $udf = $this->udf($isoFile);
        $entry = $udf->find($isoFile, '/dir');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->expectException(Exception::class);

        $udf->readFile($isoFile, $entry);
    }

    public function testImageWithoutAnchorHasNoUdf(): void
    {
        $isoFile = $this->openBuilder(IsoTree::build(['A.TXT' => 'a']));

        $this->assertNotInstanceOf(UdfFileSystem::class, $isoFile->getUdfFileSystem());
    }

    public function testUnsupportedPartitionMapIsReported(): void
    {
        $isoFile = $this->open(['a' => 'a'], ['mapType' => 2]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported UDF partition map');

        $isoFile->getUdfFileSystem();
    }

    public function testFileSystemInterfaceIsImplementedByBothKinds(): void
    {
        $udf = $this->open(['a' => 'a']);
        $iso = $this->openBuilder(IsoTree::build(['A.TXT' => 'a']));

        $this->assertInstanceOf(FileSystem::class, $udf->getFileSystem());
        $this->assertInstanceOf(FileSystem::class, $iso->getFileSystem());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function bridgeFixtures(): Iterator
    {
        yield 'udf bridge' => ['iso9660_udf.iso'];
        yield 'udf bridge with hfs' => ['iso9660_udf_hfs.iso'];
        yield 'imgburn udf bridge' => ['iso9660_udf_imgburn.iso'];
    }

    #[DataProvider('bridgeFixtures')]
    public function testUdfMatchesTheIso9660TreeOfBridgeImages(string $fixture): void
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/' . $fixture);
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $udf = $this->udf($isoFile);

        $summary = static function (FileSystem $fileSystem) use ($isoFile): array {
            $result = [];
            foreach ($fileSystem->walk($isoFile) as $entry) {
                $result[$entry->path] = $entry->isDirectory ? null : md5($fileSystem->readFile($isoFile, $entry));
            }
            ksort($result);

            return $result;
        };

        $this->assertNotSame([], $summary($udf));
        $this->assertSame($summary($volume), $summary($udf));
    }

    public function testPureUdfFixtureWithAnEmptyRoot(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__, 2) . '/fixtures/udf.iso');
        $udf = $this->udf($isoFile);

        $this->assertSame('LinuxUDF', $udf->volumeId);
        $this->assertSame([], iterator_to_array($udf->walk($isoFile), false));
    }
}
