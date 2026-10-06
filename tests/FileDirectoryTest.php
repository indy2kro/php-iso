<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\Exception;
use PhpIso\FileDirectory;
use PhpIso\IsoFile;
use PhpIso\Test\Support\Records;

final class FileDirectoryTest extends TestCase
{
    public function testReadWithInvalidBuffer(): void
    {
        $buffer = [0]; // dirRecLength is 0
        $offset = 1;

        $this->assertNotInstanceOf(FileDirectory::class, FileDirectory::read($buffer, $offset));
    }

    public function testReadWithoutData(): void
    {
        $buffer = [];
        $offset = 1;

        $this->assertNotInstanceOf(FileDirectory::class, FileDirectory::read($buffer, $offset));
    }

    public function testReadRecordShorterThanItsFixedPartIsRejected(): void
    {
        $buffer = [1 => 10, 2 => 0, 3 => 0];
        $offset = 1;

        $this->expectException(Exception::class);

        FileDirectory::read($buffer, $offset);
    }

    public function testReadMovesTheOffsetAfterTheRecord(): void
    {
        $buffer = Records::bytes(\PhpIso\Test\Support\IsoBuilder::record('A.TXT', 7, 9, 0));
        $length = count($buffer);
        $offset = 1;

        $record = FileDirectory::read($buffer, $offset);

        $this->assertInstanceOf(FileDirectory::class, $record);
        $this->assertSame($length + 1, $offset);
        $this->assertSame(7, $record->location);
        $this->assertSame(9, $record->dataLength);
        $this->assertSame('A.TXT', $record->fileId);
    }

    public function testVersionSuffixIsStripped(): void
    {
        $this->assertSame('A.TXT', Records::directory('A.TXT;1')->fileId);
    }

    public function testJolietLevelIsKept(): void
    {
        $this->assertSame(3, Records::directory('A', 0, 0, 0, 3)->jolietLevel);
    }

    public function testIsHidden(): void
    {
        $this->assertTrue(Records::directory('A', FileDirectory::FILE_MODE_HIDDEN)->isHidden());
        $this->assertFalse(Records::directory('A', 0)->isHidden());
    }

    public function testIsDirectory(): void
    {
        $this->assertTrue(Records::directory('A', FileDirectory::FILE_MODE_DIRECTORY)->isDirectory());
    }

    public function testIsAssociated(): void
    {
        $this->assertTrue(Records::directory('A', FileDirectory::FILE_MODE_ASSOCIATED)->isAssociated());
    }

    public function testIsRecord(): void
    {
        $this->assertTrue(Records::directory('A', FileDirectory::FILE_MODE_RECORD)->isRecord());
    }

    public function testIsProtected(): void
    {
        $this->assertTrue(Records::directory('A', FileDirectory::FILE_MODE_PROTECTED)->isProtected());
    }

    public function testIsMultiExtent(): void
    {
        $this->assertTrue(Records::directory('A', FileDirectory::FILE_MODE_MULTI_EXTENT)->isMultiExtent());
    }

    public function testIsThis(): void
    {
        $record = Records::directory("\0");

        $this->assertSame('.', $record->fileId);
        $this->assertTrue($record->isThis());
        $this->assertFalse($record->isParent());
    }

    public function testIsParent(): void
    {
        $record = Records::directory("\1");

        $this->assertSame('..', $record->fileId);
        $this->assertTrue($record->isParent());
        $this->assertFalse($record->isThis());
    }

    public function testNamedRecordIsNeitherThisNorParent(): void
    {
        $record = Records::directory('NAME');

        $this->assertFalse($record->isThis());
        $this->assertFalse($record->isParent());
    }

    public function testLoadExtentsSt(): void
    {
        $isoFileMock = $this->createStub(IsoFile::class);
        $isoFileMock->method('seek')->willReturn(0);
        $isoFileMock->method('read')->willReturn(pack('C*', ...array_fill(0, 4096, 0)));

        $result = FileDirectory::loadExtentsSt($isoFileMock, 2048, 0);

        $this->assertIsArray($result);
    }
}
