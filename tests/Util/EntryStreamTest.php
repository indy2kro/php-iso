<?php

declare(strict_types=1);

namespace PhpIso\Test\Util;

use PhpIso\Exception;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoTree;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\Util\EntryStream;
use PHPUnit\Framework\TestCase;

final class EntryStreamTest extends TestCase
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

    private function open(string $image): IsoFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pes');
        $this->assertNotFalse($path);
        file_put_contents($path, $image);
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * @return resource
     */
    private function stream(IsoFile $isoFile, string $path): mixed
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $entry = $volume->find($isoFile, $path);
        $this->assertInstanceOf(IsoEntry::class, $entry);

        return $volume->openStream($isoFile, $entry);
    }

    public function testPartialReadsAndEof(): void
    {
        $isoFile = $this->open(IsoTree::build(['A.TXT' => 'hello world'])->build());
        $stream = $this->stream($isoFile, '/A.TXT');

        $this->assertSame('hello', fread($stream, 5));
        $this->assertFalse(feof($stream));
        $this->assertSame(5, ftell($stream));
        $this->assertSame(' world', stream_get_contents($stream));
        $this->assertTrue(feof($stream));
        fclose($stream);
    }

    public function testSeekAndStat(): void
    {
        $isoFile = $this->open(IsoTree::build(['A.TXT' => '0123456789'])->build());
        $stream = $this->stream($isoFile, '/A.TXT');

        $stat = fstat($stream);
        $this->assertIsArray($stat);
        $this->assertSame(10, $stat['size']);

        $this->assertSame(0, fseek($stream, 7));
        $this->assertSame('789', fread($stream, 10));
        $this->assertSame(0, fseek($stream, -4, SEEK_END));
        $this->assertSame('6', fread($stream, 1));
        $this->assertSame(0, fseek($stream, 1, SEEK_CUR));
        $this->assertSame('8', fread($stream, 1));
        $this->assertSame(-1, fseek($stream, -1, SEEK_SET));
        $this->assertSame(0, fseek($stream, 0));
        $this->assertSame('01', fread($stream, 2));
        fclose($stream);
    }

    public function testMultipleExtentsAndSparseRangesAreJoined(): void
    {
        $isoFile = $this->open(UdfBuilder::build(['pad' => 'xxxxxxxx'])->build());

        $stream = EntryStream::open($isoFile, [[0, 0], [-1, 4], [0, 0]]);
        $this->assertSame("\0\0\0\0", stream_get_contents($stream));
        fclose($stream);

        // two ranges of the image around a sparse one
        $isoFile->seek(0);
        $first = (string) $isoFile->read(3);
        $isoFile->seek(10);
        $second = (string) $isoFile->read(2);

        $stream = EntryStream::open($isoFile, [[0, 3], [-1, 2], [10, 2]]);
        $this->assertSame($first . "\0\0" . $second, stream_get_contents($stream));
        $this->assertSame(0, fseek($stream, 2));
        $this->assertSame(substr($first, 2) . "\0", fread($stream, 2));
        fclose($stream);
    }

    public function testSparseUdfFileReadsAsZeros(): void
    {
        $content = 'head' . str_repeat('h', 2044) . str_repeat("\0", 2048) . 'tail';
        $isoFile = $this->open(UdfBuilder::build(['sparse.bin' => $content], ['sparse' => true, 'fragment' => true])->build());
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(\PhpIso\Udf\UdfFileSystem::class, $udf);
        $entry = $udf->find($isoFile, '/sparse.bin');
        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertContains(-1, array_column($entry->getExtents(), 0));

        $stream = $udf->openStream($isoFile, $entry);
        $this->assertSame(0, fseek($stream, 2044));
        $this->assertSame('hhhh' . "\0\0\0\0", fread($stream, 8));
        $this->assertSame(0, fseek($stream, -4, SEEK_END));
        $this->assertSame('tail', fread($stream, 100));
        fclose($stream);

        $this->assertSame($content, $udf->readFile($isoFile, $entry));
    }

    public function testRangesOutsideOfTheImageAreRejected(): void
    {
        $isoFile = $this->open(IsoTree::build(['A.TXT' => 'x'])->build());

        $this->expectException(Exception::class);

        EntryStream::open($isoFile, [[$isoFile->getSize() - 1, 10]]);
    }

    public function testDirectoryCannotBeOpened(): void
    {
        $isoFile = $this->open(IsoTree::build(['DIR' => ['A.TXT' => 'x']])->build());
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $entry = $volume->find($isoFile, '/DIR');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->expectException(Exception::class);

        $volume->openStream($isoFile, $entry);
    }

    public function testStreamWithoutItsContextCannotBeOpened(): void
    {
        // make sure the wrapper is registered
        $isoFile = $this->open(IsoTree::build(['A.TXT' => 'x'])->build());
        fclose(EntryStream::open($isoFile, [[0, 1]]));

        $this->assertFalse(@fopen(EntryStream::SCHEME . '://entry', 'rb'));
    }

    public function testStreamWithMalformedRangesCannotBeOpened(): void
    {
        $isoFile = $this->open(IsoTree::build(['A.TXT' => 'x'])->build());
        fclose(EntryStream::open($isoFile, [[0, 1]]));

        $context = stream_context_create([EntryStream::SCHEME => ['file' => $isoFile, 'ranges' => [['a', 'b']]]]);

        $this->assertFalse(@fopen(EntryStream::SCHEME . '://entry', 'rb', false, $context));
    }

    public function testReadingAClosedImageFails(): void
    {
        $isoFile = $this->open(IsoTree::build(['A.TXT' => 'hello'])->build());
        $stream = $this->stream($isoFile, '/A.TXT');
        $isoFile->closeFile();

        $this->assertSame('', (string) @fread($stream, 5));
        fclose($stream);
    }

    public function testReadStopsAtAFailureAndKeepsWhatWasRead(): void
    {
        $content = str_repeat('a', 2048) . str_repeat('b', 2048);
        $path = tempnam(sys_get_temp_dir(), 'pes');
        $this->assertNotFalse($path);
        file_put_contents($path, IsoTree::build(['A.BIN' => $content])->build());
        $this->cleanup[] = $path;

        // a read that works once, then fails
        $isoFile = new class ($path) extends IsoFile {
            public int $reads = 0;

            public int $allowed = 1000;

            public function read(int $length): string|false
            {
                return ++$this->reads > $this->allowed ? false : parent::read($length);
            }
        };

        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $entry = $volume->find($isoFile, '/A.BIN');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $stream = $volume->openStream($isoFile, $entry);
        $isoFile->reads = 0;
        $isoFile->allowed = 1;
        // PHP reads in blocks of 8192 bytes: the first call returns the 4096 bytes, the failure is reported next
        $first = fread($stream, 8192);
        $this->assertSame($content, $first);
        $this->assertSame('', (string) @fread($stream, 10));
        fclose($stream);
    }

    public function testSeekWithAnUnknownOriginIsRefused(): void
    {
        $this->assertFalse((new EntryStream())->stream_seek(0, 99));
    }

    public function testStreamOptionsAreNotSupported(): void
    {
        $isoFile = $this->open(IsoTree::build(['A.TXT' => 'hello'])->build());
        $stream = $this->stream($isoFile, '/A.TXT');

        $this->assertFalse(@stream_set_blocking($stream, true));
        fclose($stream);
    }
}
