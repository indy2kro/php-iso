<?php

declare(strict_types=1);

namespace PhpIso\Test\Cli;

use PhpIso\Cli\IsoTool;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\UdfBuilder;
use PHPUnit\Framework\TestCase;

final class IsoToolEdgeCasesTest extends TestCase
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

    private function save(IsoBuilder $builder): string
    {
        $path = $builder->save();
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param array<int, string>|null $args
     *
     * @return array{int, string}
     */
    private function runTool(?array $args): array
    {
        ob_start();
        $code = (new IsoTool())->run($args);

        return [$code, (string) ob_get_clean()];
    }

    public function testInfoSurvivesAnUnsupportedUdfFileSystem(): void
    {
        $path = $this->save(UdfBuilder::build(['a' => 'a'], ['mapType' => 2]));

        [$code, $output] = $this->runTool(['-f', $path]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Number of descriptors', $output);
    }

    public function testJsonSurvivesAnUnsupportedUdfFileSystem(): void
    {
        $path = $this->save(UdfBuilder::build(['a' => 'a'], ['mapType' => 2]));

        [$code, $output] = $this->runTool(['-j', '-f', $path]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertJson($output);
    }

    public function testInfoSurvivesABrokenBootCatalog(): void
    {
        $path = $this->save(
            (new IsoBuilder())
                ->addVolumeDescriptor(0)
                ->addBootRecord(1, 20)
                ->addTerminator(2)
                ->setSector(20, str_repeat("\1", 64))
        );

        [$code, $output] = $this->runTool(['-f', $path]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Boot Catalog Location: 20', $output);
        $this->assertStringNotContainsString('Boot Entry', $output);
    }

    public function testJsonSurvivesABrokenBootCatalog(): void
    {
        $path = $this->save(
            (new IsoBuilder())
                ->addVolumeDescriptor(0)
                ->addBootRecord(1, 20)
                ->addTerminator(2)
                ->setSector(20, str_repeat("\1", 64))
        );

        [$code, $output] = $this->runTool(['-j', '-f', $path]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringNotContainsString('"bootCatalog":', $output);
    }

    public function testProcessArgumentsAreUsedByDefault(): void
    {
        $original = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['isotool', '-h'];

        try {
            [$code, $output] = $this->runTool(null);
        } finally {
            $_SERVER['argv'] = $original;
        }

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testPositionalArgumentIsAUsageError(): void
    {
        [$code] = $this->runTool(['image.iso']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }
}
