<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\FileDirectory;
use PhpIso\IsoFile;

final class FileDirectoryTest extends TestCase
{
    public function testInitWithInvalidBuffer(): void
    {
        $buffer = [0]; // dirRecLength is 0
        $offset = 0;

        $fileDirectory = new FileDirectory();
        $result = $fileDirectory->init($buffer, $offset);

        $this->assertFalse($result);
    }

    public function testIsHidden(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->flags = FileDirectory::FILE_MODE_HIDDEN;

        $this->assertTrue($fileDirectory->isHidden());
    }

    public function testIsDirectory(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->flags = FileDirectory::FILE_MODE_DIRECTORY;

        $this->assertTrue($fileDirectory->isDirectory());
    }

    public function testIsAssociated(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->flags = FileDirectory::FILE_MODE_ASSOCIATED;

        $this->assertTrue($fileDirectory->isAssociated());
    }

    public function testIsRecord(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->flags = FileDirectory::FILE_MODE_RECORD;

        $this->assertTrue($fileDirectory->isRecord());
    }

    public function testIsProtected(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->flags = FileDirectory::FILE_MODE_PROTECTED;

        $this->assertTrue($fileDirectory->isProtected());
    }

    public function testIsMultiExtent(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->flags = FileDirectory::FILE_MODE_MULTI_EXTENT;

        $this->assertTrue($fileDirectory->isMultiExtent());
    }

    public function testIsThis(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->fileId = '.';
        $fileDirectory->fileIdLength = 1;

        $this->assertTrue($fileDirectory->isThis());
    }

    public function testIsParent(): void
    {
        $fileDirectory = new FileDirectory();
        $fileDirectory->fileId = '..';
        $fileDirectory->fileIdLength = 1;

        $this->assertTrue($fileDirectory->isParent());
    }

    public function testLoadExtentsSt(): void
    {
        $isoFileMock = $this->createMock(IsoFile::class);
        $isoFileMock->method('seek')->willReturn(0);
        $isoFileMock->method('read')->willReturn(pack('C*', ...array_fill(0, 4096, 0)));

        $result = FileDirectory::loadExtentsSt($isoFileMock, 2048, 0);

        $this->assertIsArray($result);
    }
}
