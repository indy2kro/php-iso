<?php

declare(strict_types=1);

namespace PhpIso\Test\Cli;

use PhpIso\Cli\IsoTool;
use PhpIso\Test\Support\Streams;
use PhpIso\Test\Support\UdfBuilder;
use PHPUnit\Framework\TestCase;

final class IsoToolUdfTest extends TestCase
{
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

    private function image(): string
    {
        $path = UdfBuilder::build(['a.txt' => 'alpha', 'dir' => ['b.txt' => 'beta']])->save();
        $this->files[] = $path;

        return $path;
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

    public function testInfoListsTheUdfFiles(): void
    {
        [$code, $output] = $this->runTool(['--files', '-f', $this->image()]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('UDF file system', $output);
        $this->assertStringContainsString('Volume ID: TESTUDF', $output);
        $this->assertStringContainsString('/dir/b.txt', $output);
    }

    public function testListUsesTheUdfFileSystem(): void
    {
        [$code, $output] = $this->runTool(['-l', '-f', $this->image()]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString("/a.txt\t5", $output);
    }

    public function testCatReadsFromUdf(): void
    {
        [$code, $output] = $this->runTool(['-f', $this->image(), '--cat=/dir/b.txt']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame('beta', $output);
    }

    public function testFindSearchesUdf(): void
    {
        [, $output] = $this->runTool(['-f', $this->image(), '--find=B.*']);

        $this->assertStringContainsString('/dir/b.txt', $output);
    }

    public function testJsonHasAUdfSection(): void
    {
        [, $output] = $this->runTool(['-j', '-f', $this->image()]);

        $this->assertJson($output);
        $this->assertStringContainsString('"udf"', $output);
        $this->assertStringContainsString('"volumeId": "TESTUDF"', $output);
    }

    public function testExtractWritesFromUdf(): void
    {
        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-cli-udf-' . bin2hex(random_bytes(4));

        [$code] = $this->runTool(['-f', $this->image(), '-x', $destination]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame('alpha', file_get_contents($destination . DIRECTORY_SEPARATOR . 'a.txt'));

        unlink($destination . DIRECTORY_SEPARATOR . 'a.txt');
        unlink($destination . DIRECTORY_SEPARATOR . 'dir' . DIRECTORY_SEPARATOR . 'b.txt');
        rmdir($destination . DIRECTORY_SEPARATOR . 'dir');
        rmdir($destination);
    }
}
