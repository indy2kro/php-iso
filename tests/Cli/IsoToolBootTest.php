<?php

declare(strict_types=1);

namespace PhpIso\Test\Cli;

use PhpIso\Cli\IsoTool;
use PhpIso\Test\Support\Streams;
use PhpIso\Test\Support\IsoBuilder;
use PHPUnit\Framework\TestCase;

final class IsoToolBootTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/';

    /**
     * @var array<int, string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
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
        $code = (new IsoTool(errorOutput: Streams::memory()))->run($args);

        return [$code, (string) ob_get_clean()];
    }

    private function tempFile(): string
    {
        $path = sys_get_temp_dir() . '/isotool-boot-' . uniqid();
        $this->files[] = $path;

        return $path;
    }

    public function testExtractBootWritesTheDefaultImage(): void
    {
        $target = $this->tempFile();

        [$code, $output] = $this->runTool(['--extract-boot=' . $target, '-f', self::FIXTURES . 'DOS4.01_bootdisk.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('1474560 bytes', $output);
        $this->assertSame(1474560, filesize($target));

        $iso = fopen(self::FIXTURES . 'DOS4.01_bootdisk.iso', 'rb');
        $this->assertIsResource($iso);
        fseek($iso, 25 * 2048);
        $this->assertSame(fread($iso, 4096), file_get_contents($target, false, null, 0, 4096));
        fclose($iso);
    }

    public function testExtractBootOfNoEmulationImageUsesTheSectorCount(): void
    {
        $iso = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addBootRecord(1, 20)
            ->addTerminator(2)
            ->setSector(20, IsoBuilder::bootCatalog(0, 0, 30))
            ->setSector(30, str_repeat('Z', 512));
        $path = $iso->save();
        $this->files[] = $path;
        $target = $this->tempFile();

        [$code] = $this->runTool(['--extract-boot', $target, '-f', $path]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame(str_repeat('Z', 512), file_get_contents($target));
    }

    public function testExtractBootWithoutBootRecordIsAnError(): void
    {
        [$code] = $this->runTool(['--extract-boot=' . $this->tempFile(), '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testExtractBootWithoutValueIsUsageError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURES . 'DOS4.01_bootdisk.iso', '--extract-boot']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }
}
