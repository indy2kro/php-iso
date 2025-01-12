<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
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

    public function testConstructorExistingFile(): void
    {
        $testFile = dirname(__FILE__, 2) . '/fixtures/test.iso';
        $isoFile = new IsoFile($testFile);
        $this->assertInstanceOf(IsoFile::class, $isoFile);
    }
}
