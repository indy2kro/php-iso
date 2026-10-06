<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Cli\IsoTool;
use PhpIso\Exception;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoTree;
use PhpIso\Test\Support\UdfBuilder;
use PHPUnit\Framework\TestCase;

final class IsoFileStreamTest extends TestCase
{
    /**
     * @return resource
     */
    private function stream(string $content): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * @return array<int, string>
     */
    private function temporaryFiles(): array
    {
        return glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pis*') ?: [];
    }

    public function testImageIsReadFromAStream(): void
    {
        $isoFile = IsoFile::fromStream($this->stream(IsoTree::build(['A.TXT' => 'hello'])->build()));

        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        $entry = $volume->find($isoFile, '/A.TXT');
        $this->assertInstanceOf(\PhpIso\IsoEntry::class, $entry);
        $this->assertSame('hello', $volume->readFile($isoFile, $entry));
    }

    public function testUdfImageIsReadFromAStream(): void
    {
        $isoFile = IsoFile::fromStream($this->stream(UdfBuilder::build(['a.txt' => 'udf'])->build()));

        $fileSystem = $isoFile->getFileSystem();
        $this->assertInstanceOf(\PhpIso\FileSystem::class, $fileSystem);
        $entry = $fileSystem->find($isoFile, '/a.txt');
        $this->assertInstanceOf(\PhpIso\IsoEntry::class, $entry);
        $this->assertSame('udf', $fileSystem->readFile($isoFile, $entry));
    }

    public function testTemporaryFileIsRemovedWhenTheObjectIsDestroyed(): void
    {
        $before = $this->temporaryFiles();

        $isoFile = IsoFile::fromStream($this->stream(IsoTree::build(['A.TXT' => 'a'])->build()));
        $this->assertCount(count($before) + 1, $this->temporaryFiles());

        unset($isoFile);

        $this->assertCount(count($before), $this->temporaryFiles());
    }

    public function testStreamBiggerThanTheLimitIsRejected(): void
    {
        $before = $this->temporaryFiles();

        try {
            IsoFile::fromStream($this->stream(str_repeat('x', 100)), 50);
            $this->fail('The stream must be rejected');
        } catch (Exception $exception) {
            $this->assertStringContainsString('bigger than 50 bytes', $exception->getMessage());
        }

        $this->assertCount(count($before), $this->temporaryFiles());
    }

    public function testInvalidStreamContentIsRejectedWithoutLeavingFiles(): void
    {
        $before = $this->temporaryFiles();

        try {
            IsoFile::fromStream($this->stream('not an iso'));
            $this->fail('The content must be rejected');
        } catch (Exception) {
            $this->assertCount(count($before), $this->temporaryFiles());
        }
    }

    public function testCliReadsTheImageFromStandardInput(): void
    {
        ob_start();
        $code = (new IsoTool($this->stream(IsoTree::build(['A.TXT' => 'hello'])->build())))->run(['-l', '-f', '-']);
        $output = (string) ob_get_clean();

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString("/A.TXT\t5", $output);
    }

    public function testCliCatFromStandardInput(): void
    {
        ob_start();
        $code = (new IsoTool($this->stream(IsoTree::build(['A.TXT' => 'hello'])->build())))->run(['--cat=/A.TXT', '-f', '-']);
        $output = (string) ob_get_clean();

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame('hello', $output);
    }
}
