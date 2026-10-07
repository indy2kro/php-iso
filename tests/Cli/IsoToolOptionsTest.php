<?php

declare(strict_types=1);

namespace PhpIso\Test\Cli;

use PhpIso\Cli\IsoTool;
use PhpIso\Test\Support\Streams;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\RockRidgeBuilder;
use PHPUnit\Framework\TestCase;

final class IsoToolOptionsTest extends TestCase
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

    /**
     * An ISO 9660 image with a Rock Ridge name "long-name.txt" for the file LONG.TXT
     */
    private function rockRidgeImage(): string
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [
                IsoBuilder::record("\0", 18, 2048, 2),
                IsoBuilder::record("\1", 18, 2048, 2),
                IsoBuilder::record('LONG.TXT', 19, 5, 0, RockRidgeBuilder::nm('long-name.txt')),
            ])
            ->setSector(19, 'hello');
        $path = $builder->save();
        $this->files[] = $path;

        return $path;
    }

    public function testListUsesRockRidgeNamesByDefault(): void
    {
        [$code, $output] = $this->runTool(['-l', '-f', $this->rockRidgeImage()]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('/long-name.txt', $output);
    }

    public function testNoRockRidgeListsThePlainNames(): void
    {
        [$code, $output] = $this->runTool(['-l', '--no-rock-ridge', '-f', $this->rockRidgeImage()]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('/LONG.TXT', $output);
        $this->assertStringNotContainsString('long-name', $output);
    }

    public function testNoRockRidgeApplyToFind(): void
    {
        [, $output] = $this->runTool(['--find=long*', '--no-rock-ridge', '-f', $this->rockRidgeImage()]);

        $this->assertStringContainsString('/LONG.TXT', $output);
    }

    public function testNoRockRidgeApplyToCat(): void
    {
        [$code, $output] = $this->runTool(['-c', '/LONG.TXT', '--no-rock-ridge', '-f', $this->rockRidgeImage()]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame('hello', $output);
    }

    public function testNoRockRidgeApplyToExtract(): void
    {
        $dir = sys_get_temp_dir() . '/isotool-rr-' . uniqid();

        [$code] = $this->runTool(['-x', $dir, '--no-rock-ridge', '-f', $this->rockRidgeImage()]);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertSame('hello', file_get_contents($dir . '/LONG.TXT'));

        unlink($dir . '/LONG.TXT');
        rmdir($dir);
    }

    public function testVolumePrimaryListsThePrimaryTree(): void
    {
        [$code, $output] = $this->runTool(['-l', '--volume=primary', '-f', self::FIXTURES . '1mb.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('1mb.png', strtolower($output));
    }

    public function testVolumeJolietListsTheJolietTree(): void
    {
        [$code, $output] = $this->runTool(['-l', '--volume', 'joliet', '-f', self::FIXTURES . '1mb.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('1mb.png', $output);
    }

    public function testVolumeUdfListsTheUdfTree(): void
    {
        [$code, $output] = $this->runTool(['-l', '--volume=udf', '-f', self::FIXTURES . 'iso9660_udf.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('/readme.txt', $output);
    }

    public function testUnknownVolumeIsUsageError(): void
    {
        [$code] = $this->runTool(['-l', '--volume=bogus', '-f', self::FIXTURES . '1mb.iso']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testMissingVolumeIsAnError(): void
    {
        [$code] = $this->runTool(['-l', '--volume=udf', '-f', self::FIXTURES . '1mb.iso']);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
    }

    public function testVolumeWithoutValueIsUsageError(): void
    {
        [$code] = $this->runTool(['-l', '-f', self::FIXTURES . '1mb.iso', '--volume']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testInfoDoesNotListFilesByDefault(): void
    {
        [$code, $output] = $this->runTool(['-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Volume ID', $output);
        $this->assertStringNotContainsString('Files:', $output);
        $this->assertStringNotContainsString('/DIR1/', $output);
    }

    public function testInfoListsFilesWithTheFilesOption(): void
    {
        [, $output] = $this->runTool(['--files', '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertStringContainsString('Files:', $output);
        $this->assertStringContainsString('/DIR1/', $output);
    }

    /**
     * @return \Iterator<string, array<int, array<int, string>>>
     */
    public static function conflictingActions(): \Iterator
    {
        yield 'extract and cat' => [['-x', 'out', '-c', '/A']];
        yield 'cat and find' => [['-c', '/A', '--find=*']];
        yield 'list and json' => [['-l', '-j']];
        yield 'bundled list and json' => [['-lj']];
        yield 'find and list' => [['--find=*', '--list']];
        yield 'extract and json' => [['-x', 'out', '--json']];
    }

    /**
     * @param array<int, string> $args
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingActions')]
    public function testConflictingActionsAreUsageErrors(array $args): void
    {
        [$code, $output] = $this->runTool([...$args, '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
        $this->assertSame('', $output);
    }

    public function testBundledFlagsAreExpanded(): void
    {
        [$code, $output] = $this->runTool(['-lh']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testBundledFlagsWithAnUnknownLetterAreRejected(): void
    {
        [$code] = $this->runTool(['-lz', '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testValuedShortOptionKeepsItsAttachedValue(): void
    {
        [$code, $output] = $this->runTool(['-c/TEST1.TXT', '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);
        $this->assertNotSame('', $output);
    }

    public function testMissingFileIsAUsageError(): void
    {
        [$code] = $this->runTool(['-l']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testEmptyFileValueIsAnInvalidFile(): void
    {
        [$code] = $this->runTool(['-l', '--file=']);

        $this->assertSame(IsoTool::EXIT_INVALID_FILE, $code);
    }

    public function testNdjsonPrintsOneJsonObjectPerLine(): void
    {
        [$code, $output] = $this->runTool(['--list', '--ndjson', '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_OK, $code);

        $lines = explode(PHP_EOL, trim($output));
        $this->assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('path', $decoded);
        }

        $this->assertStringContainsString('"path":"/DIR1"', $output);
    }

    public function testNdjsonWithoutListIsUsageError(): void
    {
        [$code] = $this->runTool(['--ndjson', '-f', self::FIXTURES . 'subdir.iso']);

        $this->assertSame(IsoTool::EXIT_USAGE, $code);
    }

    public function testHelpDocumentsTheNewOptions(): void
    {
        [, $output] = $this->runTool(['-h']);

        foreach (['--volume', '--no-rock-ridge', '--files', '--ndjson', '--extract-boot'] as $option) {
            $this->assertStringContainsString($option, $output);
        }
    }
}
