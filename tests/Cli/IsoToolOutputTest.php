<?php

declare(strict_types=1);

namespace PhpIso\Test\Cli;

use PhpIso\Cli\IsoTool;
use PHPUnit\Framework\TestCase;

final class IsoToolOutputTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/';

    /**
     * @var array<int, string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            $this->remove($path);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
                $this->remove($path . DIRECTORY_SEPARATOR . $name);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param array<int, string> $args
     *
     * @return array{int, string}
     */
    private function runTool(array $args): array
    {
        ob_start();
        $code = (new IsoTool())->run($args);

        return [$code, (string) ob_get_clean()];
    }

    public function testInfoPrintsVolumeDetails(): void
    {
        [$code, $output] = $this->runTool(['-f', self::FIXTURES . '1mb.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Volume ID: 25_12_2024', $output);
        $this->assertStringContainsString('Joliet Level: 3', $output);
        $this->assertStringContainsString('/1mb.png (location: 30) (length: 1048576)', $output);
    }

    public function testInfoPrintsTheBootCatalog(): void
    {
        [$code, $output] = $this->runTool(['-f', self::FIXTURES . 'DOS4.01_bootdisk.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Boot System ID: EL TORITO SPECIFICATION', $output);
        $this->assertStringContainsString('Boot Catalog Checksum: valid', $output);
        $this->assertStringContainsString('media 1.44 MB floppy', $output);
    }

    public function testInfoPrintsDirectories(): void
    {
        [, $output] = $this->runTool(['-f', self::FIXTURES . 'subdir.iso']);

        $this->assertStringContainsString('/DIR1/', $output);
    }

    public function testJsonContainsTheBootCatalog(): void
    {
        [, $output] = $this->runTool(['-j', '-f', self::FIXTURES . 'DOS4.01_bootdisk.iso']);

        $this->assertJson($output);
        $this->assertStringContainsString('"platform": "x86"', $output);
        $this->assertStringContainsString('"validChecksum": true', $output);
    }

    public function testJsonContainsTheFiles(): void
    {
        [, $output] = $this->runTool(['-j', '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertJson($output);
        $this->assertStringContainsString('"path": "/DIR1/DIR2/DIR3/TEST4.TXT"', $output);
    }

    public function testExtractWritesTheFiles(): void
    {
        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-cli-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;

        [$code, $output] = $this->runTool(['-f', self::FIXTURES . 'subdir.iso', '-x', $destination]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Extract finished!', $output);
        $this->assertFileExists($destination . '/DIR1/DIR2/DIR3/TEST4.TXT');
    }

    public function testMissingIsoFileIsAnError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURES . 'does-not-exist.iso']);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testDirectoryInsteadOfFileIsAnError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURES]);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testInvalidIsoIsAnError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURES . 'invalid.iso']);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testListOfAnImageWithoutVolumeIsAnError(): void
    {
        [$code] = $this->runTool(['-l', '-f', self::FIXTURES . 'udf.iso']);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testExtractOfAnImageWithoutVolumeIsAnError(): void
    {
        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-cli-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;

        [$code] = $this->runTool(['-f', self::FIXTURES . 'udf.iso', '--extract=' . $destination]);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testShortAndLongOptionsAreEquivalent(): void
    {
        [, $short] = $this->runTool(['-l', '-f', self::FIXTURES . 'subdir.iso']);
        [, $long] = $this->runTool(['--list', '--file=' . self::FIXTURES . 'subdir.iso']);

        $this->assertSame($short, $long);
    }
}
