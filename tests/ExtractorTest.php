<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PHPUnit\Framework\TestCase;

final class ExtractorTest extends TestCase
{
    private string $destination;

    protected function setUp(): void
    {
        $this->destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->destination . '.bin')) {
            unlink($this->destination . '.bin');
        }

        if (! is_dir($this->destination)) {
            return;
        }

        $this->removeDirectory($this->destination);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testExtractWritesNestedFiles(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso');
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        $count = (new Extractor())->extract($isoFile, $volume, $this->destination);

        $this->assertSame(4, $count);
        $this->assertFileExists($this->destination . '/DIR1/DIR2/DIR3/TEST4.TXT');
        $this->assertSame(6, filesize($this->destination . '/DIR1/TEST2.TXT'));
    }

    public function testExtractCallsCallbackForEachFile(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso');
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        $seen = [];
        (new Extractor())->extract($isoFile, $volume, $this->destination, static function (IsoEntry $entry) use (&$seen): void {
            $seen[] = $entry->path;
        });

        $this->assertContains('/TEST1.TXT', $seen);
    }

    public function testExtractRangeOutsideOfFileThrows(): void
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso');

        $this->expectException(Exception::class);

        $isoFile->extractRange(0, PHP_INT_MAX - 10, $this->destination . '.bin');
    }
}
