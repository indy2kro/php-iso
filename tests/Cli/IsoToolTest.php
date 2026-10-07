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

    public function testMissingFileIsUsageError(): void
    {
        [$code] = $this->runTool(['-l']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
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

    public function testCatPrintsFileContent(): void
    {
        [$code, $output] = $this->runTool(['-f', self::FIXTURE, '--cat=/dir1/test2.txt']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame(6, strlen($output));
    }

    public function testCatOfUnknownFileFails(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURE, '--cat=/nope.txt']);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testFindListsMatchingFiles(): void
    {
        [$code, $output] = $this->runTool(['-f', self::FIXTURE, '--find=test?.txt']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('/DIR1/DIR2/TEST3.TXT', $output);
    }

    public function testUnknownOptionIsUsageError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURE, '--bogus']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testCatWithoutValueIsUsageError(): void
    {
        [$code] = $this->runTool(['-f', self::FIXTURE, '--cat']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testJsonOutputIsValidJson(): void
    {
        [$code, $output] = $this->runTool(['--json', '-f', self::FIXTURE]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertIsArray(json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
}
