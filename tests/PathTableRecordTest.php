<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\PathTableRecord;
use PhpIso\Exception;
use PhpIso\Test\Support\Records;

final class PathTableRecordTest extends TestCase
{
    public function testDirectoryNumberIsTheOneGiven(): void
    {
        $this->assertSame(5, Records::pathRecord('DIR', 1, 5)->dirNum);
    }

    public function testReadWithZeroDirIdLen(): void
    {
        $bytes = [0];
        $offset = 1;

        $this->assertNotInstanceOf(PathTableRecord::class, PathTableRecord::read($bytes, $offset, 1));
    }

    public function testReadParsesTheFields(): void
    {
        $record = Records::pathRecord('SUB', 4, 2, 77);

        $this->assertSame('SUB', $record->dirIdentifier);
        $this->assertSame(4, $record->parentDirNum);
        $this->assertSame(77, $record->location);
        $this->assertSame(3, $record->dirIdLen);
    }

    public function testGetFullPath(): void
    {
        $record1 = Records::pathRecord('root', 1, 1);
        $record2 = Records::pathRecord('subdir', 1, 2);
        $record3 = Records::pathRecord('subsubdir', 2, 3);

        $pathTable = [
            1 => $record1,
            2 => $record2,
            3 => $record3,
        ];

        $this->assertSame('/root/', $record1->getFullPath($pathTable));
        $this->assertSame('/subdir/', $record2->getFullPath($pathTable));
        $this->assertSame('/subdir/subsubdir/', $record3->getFullPath($pathTable));
    }

    public function testGetFullPathWithMaxDepth(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Maximum depth of 1000 reached');

        // a directory that is its own parent
        $record = Records::pathRecord('loop', 2, 2);

        $record->getFullPath([2 => $record]);
    }

    public function testGetFullPathWithMissingParent(): void
    {
        $this->expectException(Exception::class);

        $record = Records::pathRecord('orphan', 9, 2);

        $record->getFullPath([2 => $record]);
    }
}
