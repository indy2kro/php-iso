<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\BrowsesEntries;
use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use Carbon\CarbonImmutable;
use PhpIso\RockRidgeInfo;
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

    /**
     * @param list<IsoEntry> $entries
     */
    private function fakeFileSystem(array $entries, ?string $failOn = null): FileSystem
    {
        return new readonly class ($entries, $failOn) implements FileSystem {
            use BrowsesEntries;

            /** @param list<IsoEntry> $entries */
            public function __construct(private array $entries, private ?string $failOn)
            {
            }

            public function walk(IsoFile $isoFile, int $maxDepth = 64): \Generator
            {
                yield from $this->entries;
            }

            public function listDirectory(IsoFile $isoFile, ?IsoEntry $directory = null): \Generator
            {
                yield from [];
            }

            public function getEntryRanges(IsoFile $isoFile, IsoEntry $entry): array
            {
                return $entry->getExtents();
            }

            public function copyEntryTo(IsoFile $isoFile, IsoEntry $entry, mixed $output): void
            {
                fwrite($output, 'partial');

                if ($entry->path === $this->failOn) {
                    throw new Exception('read failed');
                }
            }
        };
    }

    private function entry(string $path, bool $isDirectory = false, ?CarbonImmutable $date = null, ?RockRidgeInfo $rr = null): IsoEntry
    {
        return new IsoEntry($path, basename($path), $isDirectory, 7, 0, $date, false, [], $rr);
    }

    private function isoFile(): IsoFile
    {
        return new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso');
    }

    public function testExtractKeepsModificationTimes(): void
    {
        $date = CarbonImmutable::createFromTimestampUTC(981173106);
        $fs = $this->fakeFileSystem([
            $this->entry('/dir', true, $date),
            $this->entry('/dir/a.txt', false, $date),
        ]);

        (new Extractor())->extract($this->isoFile(), $fs, $this->destination);

        $this->assertSame($date->getTimestamp(), filemtime($this->destination . '/dir/a.txt'));
        $this->assertSame($date->getTimestamp(), filemtime($this->destination . '/dir'));
    }

    public function testExtractAppliesRockRidgeModeOnlyWhenAsked(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions are not available on Windows');
        }

        $entries = [$this->entry('/a.txt', false, null, new RockRidgeInfo(mode: 0100640))];

        (new Extractor(preserveMode: true))->extract($this->isoFile(), $this->fakeFileSystem($entries), $this->destination);

        $this->assertSame(0640, fileperms($this->destination . '/a.txt') & 0777);
    }

    public function testExtractContinuesPastUnsafeNamesAndFailedWrites(): void
    {
        $fs = $this->fakeFileSystem([
            $this->entry('/CON'),
            $this->entry('/bad.txt'),
            $this->entry('/good.txt'),
        ], '/bad.txt');

        $extractor = new Extractor(continueOnError: true);
        $count = $extractor->extract($this->isoFile(), $fs, $this->destination);

        $this->assertSame(1, $count);
        $this->assertSame(['/CON', '/bad.txt'], array_keys($extractor->getErrors()));
        $this->assertSame('read failed', $extractor->getErrors()['/bad.txt']);
        $this->assertFileDoesNotExist($this->destination . '/bad.txt');
        $this->assertFileExists($this->destination . '/good.txt');
    }

    public function testFailedCopyDeletesPartialFileAndRethrows(): void
    {
        $fs = $this->fakeFileSystem([$this->entry('/bad.txt')], '/bad.txt');

        try {
            (new Extractor())->extract($this->isoFile(), $fs, $this->destination);
            $this->fail('Exception expected');
        } catch (Exception) {
            $this->assertFileDoesNotExist($this->destination . '/bad.txt');
        }
    }
}
