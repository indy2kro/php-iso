<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Cli\IsoTool;
use PhpIso\Exception;
use PhpIso\FileSystem;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\WalkWarnings;
use PHPUnit\Framework\TestCase;

final class WalkWarningsTest extends TestCase
{
    private const int UDF_ROOT = 261;
    private const int UDF_FIRST_FILE = 262;

    /**
     * @var array<int, string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function open(IsoBuilder $builder): IsoFile
    {
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * @param array<int, string> $sectors
     */
    private function iso(array $sectors): IsoBuilder
    {
        $builder = (new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1);
        foreach ($sectors as $number => $content) {
            $builder->setSector($number, $content);
        }

        return $builder;
    }

    private static function dir(int $location, string ...$records): string
    {
        return IsoBuilder::record("\0", $location, 2048, 2) . IsoBuilder::record("\1", 18, 2048, 2) . implode('', $records);
    }

    private function fileSystem(IsoFile $isoFile): FileSystem
    {
        $fileSystem = $isoFile->getFileSystem();
        $this->assertInstanceOf(FileSystem::class, $fileSystem);

        return $fileSystem;
    }

    /**
     * @return list<string> the paths listed, the warnings are filled in $warnings
     */
    private function walked(FileSystem $fileSystem, IsoFile $isoFile, WalkWarnings $warnings, int $maxDepth = 64): array
    {
        $paths = [];
        foreach ($fileSystem->walk($isoFile, $maxDepth, $warnings) as $entry) {
            $paths[] = $entry->path;
        }

        return $paths;
    }

    public function testCompleteListingHasNoWarnings(): void
    {
        $isoFile = $this->open($this->iso([18 => self::dir(18, IsoBuilder::record('A.TXT;1', 19, 1))]));
        $warnings = new WalkWarnings();

        $this->assertSame(['/A.TXT'], $this->walked($this->fileSystem($isoFile), $isoFile, $warnings));
        $this->assertTrue($warnings->isEmpty());
        $this->assertSame([], $warnings->all());
    }

    public function testDepthLimitIsReported(): void
    {
        $isoFile = $this->open($this->iso([
            18 => self::dir(18, IsoBuilder::record('D', 19, 2048, 2)),
            19 => self::dir(19, IsoBuilder::record('E', 20, 2048, 2)),
            20 => self::dir(20, IsoBuilder::record('F.TXT;1', 21, 1)),
        ]));
        $warnings = new WalkWarnings();

        $paths = $this->walked($this->fileSystem($isoFile), $isoFile, $warnings, 1);

        $this->assertSame(['/D', '/D/E'], $paths);
        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('depth limit (1) reached, not listing /D/E', $warnings->all()[0]);
    }

    public function testTooLargeDirectoryIsReported(): void
    {
        $isoFile = $this->open($this->iso([18 => self::dir(18, IsoBuilder::record('BIG', 19, 100 * 1024 * 1024, 2))]));
        $warnings = new WalkWarnings();

        $this->walked($this->fileSystem($isoFile), $isoFile, $warnings);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('directory too large to be read: /BIG (location 19)', $warnings->all()[0]);
    }

    public function testUnreadableDirectoryIsReported(): void
    {
        $isoFile = $this->open($this->iso([18 => self::dir(18, IsoBuilder::record('GONE', 90000, 2048, 2))]));
        $warnings = new WalkWarnings();

        $this->assertSame(['/GONE'], $this->walked($this->fileSystem($isoFile), $isoFile, $warnings));
        $this->assertSame(['directory cannot be read: /GONE (location 90000)'], $warnings->all());
    }

    public function testTruncatedDirectoryIsReported(): void
    {
        // the directory claims two sectors but the image ends after the first one
        $isoFile = $this->open($this->iso([
            18 => self::dir(18, IsoBuilder::record('SHORT', 19, 4096, 2)),
            19 => self::dir(19),
        ]));
        $warnings = new WalkWarnings();

        $this->walked($this->fileSystem($isoFile), $isoFile, $warnings);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('directory is truncated (2048 of 4096 bytes): /SHORT (location 19)', $warnings->all()[0]);
    }

    public function testCorruptDirectoryRecordIsReportedWithItsOffset(): void
    {
        $records = IsoBuilder::record('A.TXT;1', 19, 1) . chr(10) . str_repeat("\xFF", 9);
        $isoFile = $this->open($this->iso([18 => self::dir(18, $records)]));
        $warnings = new WalkWarnings();

        $this->assertSame(['/A.TXT'], $this->walked($this->fileSystem($isoFile), $isoFile, $warnings));
        $this->assertCount(1, $warnings->all());
        $this->assertMatchesRegularExpression('/^corrupt directory record at offset \d+ in \/ \(location 18\)$/', $warnings->all()[0]);
    }

    public function testListDirectoryAndFindReportToo(): void
    {
        $isoFile = $this->open($this->iso([18 => self::dir(18, IsoBuilder::record('GONE', 90000, 2048, 2))]));
        $fileSystem = $this->fileSystem($isoFile);

        $warnings = new WalkWarnings();
        $gone = $fileSystem->find($isoFile, '/GONE', $warnings);
        $this->assertInstanceOf(\PhpIso\IsoEntry::class, $gone);
        $this->assertSame([], $warnings->all());

        $this->assertNull($fileSystem->find($isoFile, '/GONE/A.TXT', $warnings));
        $this->assertCount(1, $warnings->all());

        $listing = new WalkWarnings();
        $this->assertSame([], iterator_to_array($fileSystem->listDirectory($isoFile, $gone, $listing), false));
        $this->assertCount(1, $listing->all());

        $searching = new WalkWarnings();
        iterator_to_array($fileSystem->search($isoFile, '*.txt', $searching), false);
        $this->assertCount(1, $searching->all());
    }

    public function testStrictModeThrowsAtTheFirstProblem(): void
    {
        $isoFile = $this->open($this->iso([18 => self::dir(18, IsoBuilder::record('GONE', 90000, 2048, 2))]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Incomplete listing: directory cannot be read: /GONE');

        $this->walked($this->fileSystem($isoFile), $isoFile, new WalkWarnings(true));
    }

    public function testWithoutACollectorNothingChanges(): void
    {
        $isoFile = $this->open($this->iso([18 => self::dir(18, IsoBuilder::record('GONE', 90000, 2048, 2))]));

        $this->assertCount(1, iterator_to_array($this->fileSystem($isoFile)->walk($isoFile), false));
    }

    public function testResetClearsTheWarnings(): void
    {
        $warnings = new WalkWarnings();
        $warnings->add('x');
        $this->assertFalse($warnings->isEmpty());

        $warnings->reset();
        $this->assertTrue($warnings->isEmpty());
    }

    private function udfWarnings(IsoBuilder $builder, int $maxDepth = 64): WalkWarnings
    {
        $isoFile = $this->open($builder);
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(\PhpIso\Udf\UdfFileSystem::class, $udf);
        $warnings = new WalkWarnings();
        iterator_to_array($udf->walk($isoFile, $maxDepth, $warnings), false);

        return $warnings;
    }

    public function testUdfCompleteListingHasNoWarnings(): void
    {
        $this->assertTrue($this->udfWarnings(UdfBuilder::build(['a.txt' => 'a', 'd' => ['b.txt' => 'b']]))->isEmpty());
    }

    public function testUdfDepthLimitIsReported(): void
    {
        $warnings = $this->udfWarnings(UdfBuilder::build(['d' => ['e' => ['f.txt' => 'x']]]), 1);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('depth limit (1) reached, not listing /d/e', $warnings->all()[0]);
    }

    public function testUdfUnreadableRootIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::UDF_ROOT, 34, pack('v', 2));

        $warnings = $this->udfWarnings($builder);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('UDF root directory cannot be read (partition 0, block 1)', $warnings->all()[0]);
    }

    public function testUdfRootThatIsNotADirectoryIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::UDF_ROOT, 27, chr(5));

        $warnings = $this->udfWarnings($builder);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('UDF root directory not found', $warnings->all()[0]);
    }

    public function testUdfSkippedEntryIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a', 'b.txt' => 'b']);
        $builder->patch(self::UDF_FIRST_FILE, 34, pack('v', 2));

        $warnings = $this->udfWarnings($builder);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('UDF entry /a.txt skipped', $warnings->all()[0]);
    }

    public function testUdfEntryWithoutFileEntryIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a', 'b.txt' => 'b']);
        $builder->patch(self::UDF_FIRST_FILE, 0, pack('v', 0x7777));

        $warnings = $this->udfWarnings($builder);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('UDF entry /a.txt skipped, no file entry', $warnings->all()[0]);
    }

    public function testUdfTooLargeDirectoryIsReported(): void
    {
        $builder = UdfBuilder::build(['d' => ['a.txt' => 'a']]);
        $builder->patch(262, 56, pack('P', 100 * 1024 * 1024));

        $warnings = $this->udfWarnings($builder);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('UDF directory too large to be read: /d', $warnings->all()[0]);
    }

    public function testUdfTruncatedDirectoryEntryIsReported(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        // the root directory data ends in the middle of the first named entry
        $builder->patch(self::UDF_ROOT, 56, pack('P', 80));

        $warnings = $this->udfWarnings($builder);

        $this->assertCount(1, $warnings->all());
        $this->assertStringContainsString('UDF directory ends with a truncated entry', $warnings->all()[0]);
    }

    /**
     * @param array<int, string> $args
     *
     * @return array{int, string, list<string>} exit code, standard output, standard error lines
     */
    private function runTool(array $args): array
    {
        $tool = new class () extends IsoTool {
            /**
             * @var list<string>
             */
            public array $errors = [];

            protected function writeError(string $line): void
            {
                $this->errors[] = $line;
            }
        };

        ob_start();
        $code = $tool->run($args);

        return [$code, (string) ob_get_clean(), $tool->errors];
    }

    private function brokenImage(): string
    {
        $path = $this->iso([18 => self::dir(18, IsoBuilder::record('GONE', 90000, 2048, 2))])->save();
        $this->cleanup[] = $path;

        return $path;
    }

    public function testCliPrintsWarningsAndKeepsTheExitCode(): void
    {
        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'walkwarn' . uniqid();
        foreach ([['-l'], ['--find=*'], ['-x', $output]] as $action) {
            [$code, , $errors] = $this->runTool([...$action, '-f', $this->brokenImage()]);

            $this->assertSame(IsoTool::EXIT_OK, $code);
            $this->assertSame(['WARNING: directory cannot be read: /GONE (location 90000)'], $errors);
        }

        @rmdir($output . DIRECTORY_SEPARATOR . 'GONE');
        @rmdir($output);
    }

    public function testCliStrictModeFails(): void
    {
        [$code, , $errors] = $this->runTool(['-l', '--strict', '-f', $this->brokenImage()]);

        $this->assertSame(IsoTool::EXIT_ERROR, $code);
        $this->assertSame(['ERROR: Incomplete listing: directory cannot be read: /GONE (location 90000)'], $errors);
    }

    public function testUdfStrictModeThrows(): void
    {
        $builder = UdfBuilder::build(['a.txt' => 'a']);
        $builder->patch(self::UDF_ROOT, 27, chr(5));
        $isoFile = $this->open($builder);
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(\PhpIso\Udf\UdfFileSystem::class, $udf);

        $this->expectException(Exception::class);
        iterator_to_array($udf->walk($isoFile, 64, new WalkWarnings(true)), false);
    }
}
