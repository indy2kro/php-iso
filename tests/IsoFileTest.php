<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\IsoFile;

class IsoFileTest extends TestCase
{
    public function testConstructor(): void
    {
        $isoFile = new IsoFile();
        $this->assertInstanceOf(IsoFile::class, $isoFile);
    }
}
