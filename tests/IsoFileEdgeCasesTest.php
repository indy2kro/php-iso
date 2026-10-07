<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\Reader;
use PhpIso\Exception;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\IsoTree;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * IsoFile and Reader failure paths: closed handles, unreadable streams, short reads, descriptors after the terminator
 */
final class IsoFileEdgeCasesTest extends TestCase
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

    private function temp(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pie');
        $this->assertNotFalse($path);
        file_put_contents($path, $content);
        $this->cleanup[] = $path;

        return $path;
    }

    private function image(): IsoFile
    {
        return new IsoFile($this->temp(IsoTree::build(['A.TXT' => 'hello'])->build()));
    }

    /**
     * Run a callable with PHP notices swallowed (writing to a read-only stream is reported by PHP itself)
     */
    private function quietly(callable $callable): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $callable();
        } finally {
            restore_error_handler();
        }
    }

    public function testClosedHandleCannotSeekOrRead(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();

        $this->assertSame(-1, $isoFile->seek(0));
        $this->assertFalse($isoFile->read(10));
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();
        $isoFile->closeFile();

        $this->assertSame(-1, $isoFile->seek(0));
    }

    public function testReopenedFileWorksAgain(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();
        $isoFile->openFile();

        $this->assertSame(0, $isoFile->seek(0));
        $this->assertSame(10, strlen((string) $isoFile->read(10)));
    }

    public function testReadRejectsEmptyAndHugeLengths(): void
    {
        $isoFile = $this->image();

        $this->assertFalse($isoFile->read(0));
        $this->assertFalse($isoFile->read(IsoFile::MAX_READ_LENGTH + 1));
    }

    public function testCopyRangeOnAClosedHandleFails(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();
        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to seek');

        $isoFile->copyRange(0, 10, $output);
    }

    public function testExtractRangeOnAClosedHandleFails(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to seek');

        $isoFile->extractRange(0, 10, $this->temp());
    }

    public function testCopyRangeToAReadOnlyStreamFails(): void
    {
        $isoFile = $this->image();
        $readOnly = fopen($this->temp('x'), 'rb');
        $this->assertIsResource($readOnly);

        $this->quietly(function () use ($isoFile, $readOnly): void {
            try {
                $isoFile->copyRange(0, 10, $readOnly);
                $this->fail('A write failure must be reported');
            } catch (Exception $exception) {
                $this->assertSame('Failed to write the data', $exception->getMessage());
            } finally {
                fclose($readOnly);
            }
        });
    }

    public function testFromStreamReportsAStreamThatCannotBeRead(): void
    {
        $writeOnly = fopen($this->temp(), 'wb');
        $this->assertIsResource($writeOnly);

        $this->quietly(function () use ($writeOnly): void {
            try {
                IsoFile::fromStream($writeOnly);
                $this->fail('An unreadable stream must be reported');
            } catch (Exception $exception) {
                $this->assertSame('Failed to read the input stream', $exception->getMessage());
            } finally {
                fclose($writeOnly);
            }
        });
    }

    public function testFromStreamRefusesATooBigStream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, str_repeat('x', 100));
        rewind($stream);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('bigger than 50 bytes');

        IsoFile::fromStream($stream, 50);
    }

    public function testFromStreamRemovesItsTemporaryFileWhenTheImageIsInvalid(): void
    {
        $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pis*') ?: [];
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'not an iso');
        rewind($stream);

        try {
            IsoFile::fromStream($stream);
            $this->fail('An invalid image must be rejected');
        } catch (Exception) {
            $this->assertSame($before, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pis*') ?: []);
        }
    }

    public function testDescriptorsCannotBeReadFromAClosedHandle(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();

        $this->assertSame([[], []], (new ReflectionMethod($isoFile, 'readDescriptors'))->invoke($isoFile));
    }

    public function testReaderStopsWhenTheFileCannotBeRead(): void
    {
        $isoFile = $this->image();
        $isoFile->closeFile();

        $this->assertNotInstanceOf(\PhpIso\Descriptor::class, (new Reader($isoFile))->read());
    }

    public function testImageWhoseReaderYieldsNothingIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('descriptor terminator');

        new class ($this->temp(IsoTree::build(['A.TXT' => 'hello'])->build())) extends IsoFile {
            public function read(int $length): string|false
            {
                return false;
            }
        };
    }

    public function testDescriptorsAfterTheTerminatorAreIgnored(): void
    {
        $path = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->addVolumeDescriptor(2, 2)
            ->save();
        $this->cleanup[] = $path;

        $isoFile = new IsoFile($path);

        $this->assertInstanceOf(\PhpIso\Descriptor\PrimaryVolume::class, $isoFile->getPrimaryVolume());
        $this->assertNull($isoFile->getSupplementaryVolume());
    }
}
