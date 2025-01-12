<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Iterator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpIso\IsoFile;
use PhpIso\Exception;

class IsoFileTest extends TestCase
{
    public function testConstructorFileDoesNotExist(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/does-not-exist.iso';

        $this->expectException(Exception::class);
        new IsoFile($testFile);
    }

    public function testConstructorInvalidFile(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/invalid.iso';

        $this->expectException(Exception::class);
        new IsoFile($testFile);
    }

    #[DataProvider('isoFilesDataProvider')]
    public function testConstructorExistingFile(string $testFile): void
    {
        $isoFile = new IsoFile($testFile);
        $this->assertInstanceOf(IsoFile::class, $isoFile);
    }

    public static function isoFilesDataProvider(): Iterator
    {
        yield [dirname(__FILE__, 2) . '/fixtures/1mb.iso'];
        yield [dirname(__FILE__, 2) . '/fixtures/test.iso'];
    }
}
