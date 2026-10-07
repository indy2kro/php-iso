<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Iterator;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoTree;
use PhpIso\Test\Support\UdfBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * listDirectory() and the component by component find() (FEAT-02)
 */
final class ListDirectoryTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function paths(FileSystem $fs, IsoFile $isoFile, ?IsoEntry $directory = null): array
    {
        return array_map(static fn (IsoEntry $e): string => $e->path, iterator_to_array($fs->listDirectory($isoFile, $directory), false));
    }

    /**
     * Rebuild the whole tree with listDirectory() (depth first, like walk())
     *
     * @return list<array<string, mixed>>
     */
    private function rebuilt(FileSystem $fs, IsoFile $isoFile, ?IsoEntry $directory = null): array
    {
        $result = [];
        foreach ($fs->listDirectory($isoFile, $directory) as $entry) {
            $result[] = $entry->toArray();
            if ($entry->isDirectory) {
                array_push($result, ...$this->rebuilt($fs, $isoFile, $entry));
            }
        }

        return $result;
    }

    /**
     * @return Iterator<string, array{string, bool}>
     */
    public static function fixtures(): Iterator
    {
        foreach (['subdir.iso', 'test-dir.iso', 'test.iso', 'iso9660_udf.iso', 'udf.iso', 'DOS4.01_bootdisk.iso', 'rockridge.iso', 'joliet_cjk.iso'] as $name) {
            yield $name . ' primary' => [$name, false];
        }

        // only the images that have a UDF file system
        foreach (['test.iso', 'iso9660_udf.iso', 'udf.iso', 'iso9660_udf_hfs.iso'] as $name) {
            yield $name . ' udf' => [$name, true];
        }
    }

    #[DataProvider('fixtures')]
    public function testListingEveryDirectoryGivesTheWalkedEntries(string $name, bool $udf): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/' . $name);
        $fs = $udf ? $isoFile->getUdfFileSystem() : $isoFile->getFileSystem();
        $this->assertInstanceOf(FileSystem::class, $fs);

        $walked = [];
        foreach ($fs->walk($isoFile) as $entry) {
            $walked[] = $entry->toArray();
        }

        // walk() lists a directory before the ones below it, rebuilt() goes straight down: same entries, other order
        $rebuilt = $this->rebuilt($fs, $isoFile);
        $byPath = static fn (array $a, array $b): int => $a['path'] <=> $b['path'];
        usort($walked, $byPath);
        usort($rebuilt, $byPath);

        $this->assertSame($walked, $rebuilt);
    }

    public function testRootAndSubdirectoryAreListed(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso');
        $fs = $isoFile->getFileSystem();
        $this->assertInstanceOf(\PhpIso\FileSystem::class, $fs);

        $root = $this->paths($fs, $isoFile);
        $this->assertNotSame([], $root);
        foreach ($root as $path) {
            $this->assertSame(1, substr_count($path, '/'));
        }

        $directory = null;
        foreach ($fs->listDirectory($isoFile) as $entry) {
            if ($entry->isDirectory) {
                $directory = $entry;
                break;
            }
        }
        $this->assertInstanceOf(IsoEntry::class, $directory);
        foreach ($this->paths($fs, $isoFile, $directory) as $path) {
            $this->assertStringStartsWith($directory->path . '/', $path);
        }
    }

    public function testListingAFileGivesNothing(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso');
        $fs = $isoFile->getFileSystem();
        $this->assertInstanceOf(\PhpIso\FileSystem::class, $fs);
        foreach ($fs->walk($isoFile) as $entry) {
            if (! $entry->isDirectory) {
                $this->assertSame([], $this->paths($fs, $isoFile, $entry));

                return;
            }
        }
    }

    public function testFindDescendsComponentByComponent(): void
    {
        $path = IsoTree::build(['DIR' => ['SUB' => ['DEEP.TXT' => 'deep'], 'A.TXT' => 'a'], 'TOP.TXT' => 'top'])->save();
        try {
            $isoFile = new IsoFile($path);
            $fs = $isoFile->getFileSystem();
            $this->assertInstanceOf(\PhpIso\FileSystem::class, $fs);

            $this->assertSame('/DIR/SUB/DEEP.TXT', $fs->find($isoFile, '/DIR/SUB/DEEP.TXT')?->path);
            $this->assertSame('/DIR/SUB/DEEP.TXT', $fs->find($isoFile, 'dir\\sub//deep.txt')?->path);
            $this->assertSame('/TOP.TXT', $fs->find($isoFile, 'TOP.TXT')?->path);
            $this->assertTrue($fs->find($isoFile, '/DIR')?->isDirectory);
            $this->assertNull($fs->find($isoFile, '/DIR/NOPE.TXT'));
            $this->assertNull($fs->find($isoFile, '/TOP.TXT/X'));
            $this->assertNull($fs->find($isoFile, '/'));
        } finally {
            unlink($path);
        }
    }

    public function testFindPrefersTheExactCaseAmongSiblings(): void
    {
        $path = UdfBuilder::build(['Dir' => ['file.txt' => 'lower', 'FILE.TXT' => 'upper']])->save();
        try {
            $isoFile = new IsoFile($path);
            $udf = $isoFile->getUdfFileSystem();
            $this->assertInstanceOf(\PhpIso\Udf\UdfFileSystem::class, $udf);

            $this->assertSame('/Dir/FILE.TXT', $udf->find($isoFile, '/Dir/FILE.TXT')?->path);
            $this->assertSame('/Dir/file.txt', $udf->find($isoFile, '/dir/file.txt')?->path);
            $this->assertSame('/Dir/file.txt', $udf->find($isoFile, '/DIR/File.Txt')?->path);
        } finally {
            unlink($path);
        }
    }
}
