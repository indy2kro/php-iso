<?php

declare(strict_types=1);

namespace PhpIso\Test\Cli;

use PhpIso\Cli\IsoTool;
use PHPUnit\Framework\TestCase;

final class IsoToolTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../fixtures/subdir.iso';

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

    public function testHelpExitsWithSuccess(): void
    {
        [$code, $output] = $this->runTool(['-h']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testNoArgumentsIsUsageError(): void
    {
        [$code] = $this->runTool([]);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testMissingFileIsInvalidFile(): void
    {
        [$code] = $this->runTool(['-l']);

        $this->assertSame(IsoTool::EXIT_INVALID_FILE, $code);
    }

    public function testExtractWithoutDestinationIsUsageError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURE, '-x']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testListPrintsFilesWithSizes(): void
    {
        [$code, $output] = $this->runTool(['-l', '--file=' . self::FIXTURE]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString("/TEST1.TXT\t6", $output);
    }

    public function testJsonOutputIsValidJson(): void
    {
        [$code, $output] = $this->runTool(['--json', '-f', self::FIXTURE]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertIsArray(json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
}
