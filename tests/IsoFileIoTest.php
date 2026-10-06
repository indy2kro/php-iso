<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\FileDirectory;
use PhpIso\IsoFile;
use PhpIso\PathTableRecord;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\IsoTree;
use PhpIso\Test\Support\Records;
use PHPUnit\Framework\TestCase;

/**
 * Failure paths: truncated images, unreadable destinations, short reads
 */
final class IsoFileIoTest extends TestCase
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

    private function temp(string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-io-' . bin2hex(random_bytes(4)) . $suffix;
        $this->cleanup[] = $path;

        return $path;
    }

    private function sample(): IsoFile
    {
        $path = IsoTree::build(['A.TXT' => 'hello'])->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * Run a callable while PHP warnings are ignored (the code under test reports failures through exceptions)
     */
    private function quietly(callable $callable): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $callable();
        } finally {
            restore_error_handler();
        }
    }

    public function testExtractRangeWritesTheRequestedBytes(): void
    {
        $isoFile = $this->sample();
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $entry = $volume->find($isoFile, '/A.TXT');
        $this->assertInstanceOf(\PhpIso\IsoEntry::class, $entry);
        $target = $this->temp();

        $isoFile->extractRange($entry->location * 2048, 5, $target);

        $this->assertSame('hello', file_get_contents($target));
    }

    public function testExtractRangeToAnUnwritablePathThrows(): void
    {
        $isoFile = $this->sample();

        $this->expectException(Exception::class);

        $this->quietly(fn () => $isoFile->extractRange(0, 4, $this->temp() . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'file'));
    }

    public function testPathTableRecordExtractFileDelegatesToTheIso(): void
    {
        $isoFile = $this->sample();
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $entry = $volume->find($isoFile, '/A.TXT');
        $this->assertInstanceOf(\PhpIso\IsoEntry::class, $entry);
        $target = $this->temp();

        Records::pathRecord('X', 1)->extractFile($isoFile, 2048, $entry->location, 5, $target);

        $this->assertSame('hello', file_get_contents($target));
    }

    public function testCloseFileTwiceIsHarmless(): void
    {
        $isoFile = $this->sample();

        $isoFile->closeFile();
        $isoFile->closeFile();

        $this->assertSame(-1, $isoFile->seek(0));
    }

    public function testCopyRangeFailsWhenTheImageReturnsNoData(): void
    {
        $path = IsoTree::build(['A.TXT' => 'hello'])->save();
        $this->cleanup[] = $path;
        $isoFile = new class ($path) extends IsoFile {
            public bool $broken = false;

            public function read(int $length): string|false
            {
                return $this->broken ? '' : parent::read($length);
            }
        };
        $isoFile->broken = true;
        $output = fopen('php://memory', 'w+b');
        $this->assertNotFalse($output);

        $this->expectException(Exception::class);

        $isoFile->copyRange(0, 10, $output);
    }

    public function testCopyRangeFailsWhenSeekFails(): void
    {
        $path = IsoTree::build(['A.TXT' => 'hello'])->save();
        $this->cleanup[] = $path;
        $isoFile = new class ($path) extends IsoFile {
            public bool $broken = false;

            public function seek(int $offset, int $whence = SEEK_SET): int
            {
                return $this->broken ? -1 : parent::seek($offset, $whence);
            }
        };
        $isoFile->broken = true;
        $output = fopen('php://memory', 'w+b');
        $this->assertNotFalse($output);

        $this->expectException(Exception::class);

        $isoFile->copyRange(0, 10, $output);
    }

    public function testImageTruncatedInsideADescriptorIsRejected(): void
    {
        $path = $this->temp('.iso');
        file_put_contents($path, substr((new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1)->build(), 0, 17 * 2048 + 6));

        $this->expectException(Exception::class);

        new IsoFile($path);
    }

    public function testExtractingIntoAFileFails(): void
    {
        $isoFile = $this->sample();
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $destination = $this->temp();
        file_put_contents($destination, 'not a directory');

        $this->expectException(Exception::class);

        $this->quietly(fn (): int => (new Extractor())->extract($isoFile, $volume, $destination));
    }

    public function testExtractingOverADirectoryFails(): void
    {
        $isoFile = $this->sample();
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $destination = $this->temp();
        mkdir($destination . DIRECTORY_SEPARATOR . 'A.TXT', 0777, true);

        $this->expectException(Exception::class);

        $this->quietly(fn (): int => (new Extractor())->extract($isoFile, $volume, $destination));
    }

    public function testExtractingADirectoryOverAFileFails(): void
    {
        $path = IsoTree::build(['DIR' => ['B.TXT' => 'b']])->save();
        $this->cleanup[] = $path;
        $isoFile = new IsoFile($path);
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $destination = $this->temp();
        mkdir($destination);
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'DIR', 'a file');

        $this->expectException(Exception::class);

        $this->quietly(fn (): int => (new Extractor())->extract($isoFile, $volume, $destination));
    }

    public function testDirectoryRecordInitWithoutDataReturnsFalse(): void
    {
        $buffer = [];
        $offset = 1;

        $this->assertNotInstanceOf(FileDirectory::class, FileDirectory::read($buffer, $offset));
    }

    public function testPathTableRecordInitWithoutDataReturnsFalse(): void
    {
        $buffer = [];
        $offset = 1;

        $this->assertNotInstanceOf(PathTableRecord::class, PathTableRecord::read($buffer, $offset, 1));
    }

    public function testTruncatedPathTableRecordReturnsFalse(): void
    {
        $buffer = [10, 0, 0, 0]; // claims a 10 bytes identifier but the buffer ends
        $offset = 1;

        $this->assertNotInstanceOf(PathTableRecord::class, PathTableRecord::read($buffer, $offset, 1));
    }

    public function testDirectoryLoadingFailsWhenTheImageCannotBeRead(): void
    {
        $path = IsoTree::build(['A.TXT' => 'a'])->save();
        $this->cleanup[] = $path;
        $isoFile = new class ($path) extends IsoFile {
            public bool $broken = false;

            public function read(int $length): string|false
            {
                return $this->broken ? false : parent::read($length);
            }
        };
        $isoFile->broken = true;

        $this->assertFalse(FileDirectory::loadExtentsSt($isoFile, 2048, 18));
    }

    public function testDirectoryInstanceLoadsItsOwnExtents(): void
    {
        $isoFile = $this->sample();
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        $extents = $volume->rootDirectory->loadExtents($isoFile, $volume->blockSize);

        $this->assertIsArray($extents);
        $this->assertCount(3, $extents);
    }

    public function testDirectorySizeIsDiscoveredFromTheDotRecord(): void
    {
        $tree = ['DIR' => array_combine(array_map(static fn (int $i): string => sprintf('F%04d.TXT', $i), range(1, 220)), array_fill(0, 220, 'x'))];
        $path = IsoTree::build($tree)->save();
        $this->cleanup[] = $path;
        $isoFile = new IsoFile($path);
        $volume = $isoFile->getPrimaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $volume);
        $table = $volume->loadTable($isoFile);
        $this->assertNotNull($table);

        $extents = $table[2]->loadExtents($isoFile, $volume->blockSize);

        // "." and ".." plus the 220 files, even though they span several sectors
        $this->assertIsArray($extents);
        $this->assertCount(222, $extents);
    }

    public function testBoundedLengthFallsBackWhenTheSizeIsUnknown(): void
    {
        $isoFile = $this->createStub(IsoFile::class);
        $isoFile->method('seek')->willReturn(0);
        $isoFile->method('getSize')->willReturn(0);
        $isoFile->method('read')->willReturn(str_repeat("\0", 4096));

        $this->assertSame([], FileDirectory::loadExtentsSt($isoFile, 2048, 0, false, 0, 4096));
    }
}
