<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Iterator;
use PhpIso\Descriptor\Volume;
use PhpIso\Extractor;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same behaviours checked against many generated images (shapes, sizes, names, volume types)
 */
final class SyntheticIsoVariationsTest extends TestCase
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

    /**
     * @param array<string, mixed> $tree
     */
    private function open(array $tree, bool $joliet = false, bool $lPathTable = true, bool $mPathTable = true): IsoFile
    {
        $path = IsoTree::build($tree, $joliet, $lPathTable, $mPathTable)->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * @return array<string, int|null>
     */
    private function walked(IsoFile $isoFile): array
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        $paths = [];
        foreach ($volume->walk($isoFile) as $entry) {
            $paths[$entry->path] = $entry->isDirectory ? null : $entry->size;
        }

        return $paths;
    }

    /**
     * @return Iterator<string, array{array<string, mixed>}>
     */
    public static function trees(): Iterator
    {
        yield 'empty image' => [[]];
        yield 'single file' => [['A.TXT' => 'a']];
        yield 'empty file' => [['EMPTY.BIN' => '']];
        yield 'flat files' => [['ONE.TXT' => 'one', 'TWO.TXT' => 'twotwo', 'THREE.TXT' => 'threethree']];
        yield 'odd and even name lengths' => [['A' => 'x', 'AB' => 'xx', 'ABC' => 'xxx', 'ABCD' => 'xxxx']];
        yield 'empty directory' => [['EMPTY' => []]];
        yield 'nested directories' => [['A' => ['B' => ['C' => ['D' => ['E' => ['DEEP.TXT' => 'deep']]]]]]];
        yield 'siblings with children' => [['X' => ['1.TXT' => '1'], 'Y' => ['2.TXT' => '22'], 'Z' => ['W' => ['3.TXT' => '333']]]];
        yield 'file of exactly one sector' => [['SECTOR.BIN' => str_repeat('s', 2048)]];
        yield 'file of one sector plus one byte' => [['SECTOR1.BIN' => str_repeat('s', 2049)]];
        yield 'multi sector file' => [['BIG.BIN' => str_repeat('0123456789abcdef', 1000)]];
        yield 'directory spanning many sectors' => [['DIR' => array_combine(array_map(static fn (int $i): string => sprintf('FILE%04d.TXT', $i), range(1, 220)), array_fill(0, 220, 'x'))]];
        yield 'many directories' => [array_combine(array_map(static fn (int $i): string => sprintf('D%03d', $i), range(1, 60)), array_fill(0, 60, ['F.TXT' => 'f']))];
    }

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider('trees')]
    public function testWalkReturnsEveryEntry(array $tree): void
    {
        $this->assertEquals(IsoTree::expectedPaths($tree), $this->walked($this->open($tree)));
    }

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider('trees')]
    public function testWalkWithJolietReturnsEveryEntry(array $tree): void
    {
        $isoFile = $this->open($tree, true);

        $this->assertInstanceOf(\PhpIso\Descriptor\SupplementaryVolume::class, $isoFile->getSupplementaryVolume());
        $this->assertEquals(IsoTree::expectedPaths($tree), $this->walked($isoFile));
    }

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider('trees')]
    public function testExtractRoundTripsContent(array $tree): void
    {
        $isoFile = $this->open($tree);
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-v-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;

        (new Extractor())->extract($isoFile, $volume, $destination);

        foreach (IsoTree::expectedPaths($tree) as $path => $size) {
            $target = $destination . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if ($size === null) {
                $this->assertDirectoryExists($target);
            } else {
                $this->assertSame($size, filesize($target), $path);
            }
        }
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function unicodeNames(): Iterator
    {
        yield 'latin accents' => ['café.txt'];
        yield 'cyrillic' => ['файл.txt'];
        yield 'japanese' => ['日本語.txt'];
        yield 'emoji outside the bmp' => ['smile-😀.txt'];
        yield 'spaces and dots' => ['a b.c.d.txt'];
        yield 'long name' => [str_repeat('n', 100) . '.txt'];
    }

    #[DataProvider('unicodeNames')]
    public function testJolietNamesAreDecoded(string $name): void
    {
        $paths = $this->walked($this->open([$name => 'content'], true));

        $this->assertArrayHasKey('/' . $name, $paths);
    }

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider('trees')]
    public function testPathTableListsEveryDirectory(array $tree): void
    {
        $isoFile = $this->open($tree);
        $volume = $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $volume);

        $table = $volume->loadTable($isoFile);
        $this->assertNotNull($table);

        $directories = array_filter(IsoTree::expectedPaths($tree), static fn (?int $size): bool => $size === null);
        $this->assertCount(count($directories) + 1, $table);
    }

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider('trees')]
    public function testLittleEndianPathTableIsUsedWhenMissingMTable(array $tree): void
    {
        $isoFile = $this->open($tree, false, true, false);
        $volume = $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $volume);

        $table = $volume->loadTable($isoFile);
        $this->assertNotNull($table);

        $directories = array_filter(IsoTree::expectedPaths($tree), static fn (?int $size): bool => $size === null);
        $this->assertCount(count($directories) + 1, $table);
    }

    public function testNoPathTableReturnsNull(): void
    {
        $isoFile = $this->open(['A.TXT' => 'a'], false, false, false);
        $volume = $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $volume);

        $this->assertNull($volume->loadTable($isoFile));
    }

    public function testFullPathIsBuiltFromParents(): void
    {
        $isoFile = $this->open(['A' => ['B' => ['C' => []]]]);
        $volume = $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $volume);
        $table = $volume->loadTable($isoFile);
        $this->assertNotNull($table);

        $this->assertSame(DIRECTORY_SEPARATOR . 'A' . DIRECTORY_SEPARATOR . 'B' . DIRECTORY_SEPARATOR . 'C' . DIRECTORY_SEPARATOR, $table[4]->getFullPath($table));
    }

    public function testEntriesExposeSizeAndLocation(): void
    {
        $isoFile = $this->open(['A.TXT' => 'abc']);
        $volume = $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $volume);

        $entries = array_values(array_filter(iterator_to_array($volume->walk($isoFile), false), static fn (IsoEntry $entry): bool => ! $entry->isDirectory));

        $this->assertSame(3, $entries[0]->size);
        $this->assertGreaterThan(19, $entries[0]->location);
    }
}
