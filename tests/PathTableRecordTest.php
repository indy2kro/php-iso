<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\PathTableRecord;
use PhpIso\Util\Buffer;
use PhpIso\Exception;

class PathTableRecordTest extends TestCase
{
    public function testSetDirectoryNumber(): void
    {
        $record = new PathTableRecord();
        $record->setDirectoryNumber(5);
        $this->assertSame(5, $record->dirNum);
    }

    public function testInitWithZeroDirIdLen(): void
    {
        $bytes = [0];
        $offset = 0;
        $record = new PathTableRecord();

        $result = $record->init($bytes, $offset);
        $this->assertFalse($result);
    }

    public function testGetFullPath(): void
    {
        $record1 = new PathTableRecord();
        $record1->dirIdentifier = 'root';
        $record1->parentDirNum = 1;

        $record2 = new PathTableRecord();
        $record2->dirIdentifier = 'subdir';
        $record2->parentDirNum = 1;

        $record3 = new PathTableRecord();
        $record3->dirIdentifier = 'subsubdir';
        $record3->parentDirNum = 2;

        $pathTable = [
            1 => $record1,
            2 => $record2,
            3 => $record3,
        ];

        $this->assertSame(DIRECTORY_SEPARATOR . 'root' . DIRECTORY_SEPARATOR, $record1->getFullPath($pathTable));
        $this->assertSame(DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR, $record2->getFullPath($pathTable));
        $this->assertSame(DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR . 'subsubdir' . DIRECTORY_SEPARATOR, $record3->getFullPath($pathTable));
    }

    public function testGetFullPathWithMaxDepth(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Maximum depth of 1000 reached');

        $record = new PathTableRecord();
        $record->dirIdentifier = 'loop';
        $record->parentDirNum = 2;

        $pathTable = [
            2 => $record,
        ];

        $record->getFullPath($pathTable);
    }
}
