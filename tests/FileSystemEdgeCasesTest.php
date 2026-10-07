<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Iterator;
use PhpIso\Exception;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoTree;
use PhpIso\Test\Support\UdfBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour shared by the ISO 9660 and the UDF file systems on directories, files and unwritable outputs
 */
final class FileSystemEdgeCasesTest extends TestCase
{
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

    /**
     * @return Iterator<string, array{bool}>
     */
    public static function fileSystems(): Iterator
    {
        yield 'iso9660' => [false];
        yield 'udf' => [true];
    }

    /**
     * @return array{IsoFile, FileSystem}
     */
    private function open(bool $udf, bool $sparse = false): array
    {
        $tree = ['DIR' => ['A.TXT' => 'hello']];
        if ($sparse) {
            $tree['SPARSE.BIN'] = str_repeat('h', 2048) . str_repeat("\0", 4096) . 'tail';
        }

        $builder = $udf ? UdfBuilder::build($tree, $sparse ? ['sparse' => true, 'fragment' => true] : []) : IsoTree::build($tree);
        $path = $builder->save();
        $this->cleanup[] = $path;
        $isoFile = new IsoFile($path);
        $fileSystem = $udf ? $isoFile->getUdfFileSystem() : $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(FileSystem::class, $fileSystem);

        return [$isoFile, $fileSystem];
    }

    private function entry(IsoFile $isoFile, FileSystem $fileSystem, string $path): IsoEntry
    {
        $entry = $fileSystem->find($isoFile, $path);
        $this->assertInstanceOf(IsoEntry::class, $entry);

        return $entry;
    }

    #[DataProvider('fileSystems')]
    public function testDirectoryContentCannotBeCopied(bool $udf): void
    {
        [$isoFile, $fileSystem] = $this->open($udf);
        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot read the content of a directory');

        $fileSystem->copyEntryTo($isoFile, $this->entry($isoFile, $fileSystem, '/DIR'), $output);
    }

    #[DataProvider('fileSystems')]
    public function testListingAFileGivesNothing(bool $udf): void
    {
        [$isoFile, $fileSystem] = $this->open($udf);

        $this->assertSame([], iterator_to_array($fileSystem->listDirectory($isoFile, $this->entry($isoFile, $fileSystem, '/DIR/A.TXT')), false));
    }

    public function testSparseExtentsAreCopiedAsZeros(): void
    {
        [$isoFile, $udf] = $this->open(true, true);
        $entry = $this->entry($isoFile, $udf, '/SPARSE.BIN');
        $this->assertContains(-1, array_column($entry->getExtents(), 0));

        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);
        $udf->copyEntryTo($isoFile, $entry, $output);
        rewind($output);

        $this->assertSame(str_repeat('h', 2048) . str_repeat("\0", 4096) . 'tail', stream_get_contents($output));
    }

    public function testSparseExtentsToAReadOnlyStreamFail(): void
    {
        [$isoFile, $udf] = $this->open(true, true);
        $entry = $this->entry($isoFile, $udf, '/SPARSE.BIN');
        $readOnly = fopen('php://memory', 'rb');
        $this->assertIsResource($readOnly);

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(Exception::class);
            $udf->copyEntryTo($isoFile, $entry, $readOnly);
        } finally {
            restore_error_handler();
            fclose($readOnly);
        }
    }
}
