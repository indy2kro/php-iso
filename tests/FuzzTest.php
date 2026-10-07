<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Iterator;
use PhpIso\Descriptor\Volume;
use PhpIso\Exception;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Parser fuzzing: mutated copies of the fixtures may be rejected with a PhpIso\Exception, but must never raise a
 * PHP warning, a TypeError, a ValueError, a DivisionByZeroError or any other Error, nor take long.
 *
 * Deterministic: the seed is fixed (FUZZ_SEED to change it) and the case number is part of it, so a failure message
 * gives everything needed to replay it. FUZZ_ITERATIONS raises the number of mutated copies per fixture.
 */
final class FuzzTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 40;
    private const int DEFAULT_SEED = 20261007;
    private const int MAX_FIXTURE_SIZE = 2 * 1024 * 1024;
    private const int MAX_ENTRIES = 5000;
    private const float MAX_SECONDS = 2.0;
    private const int SECTOR = 2048;

    private string $directory = '';

    /**
     * @var list<string>
     */
    private array $log = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-fuzz-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function fixtures(): Iterator
    {
        foreach (glob(dirname(__DIR__) . '/fixtures/*.iso') ?: [] as $path) {
            if (filesize($path) <= self::MAX_FIXTURE_SIZE) {
                yield basename($path) => [$path];
            }
        }
    }

    #[DataProvider('fixtures')]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testMutatedImagesOnlyRaiseIsoExceptions(string $path): void
    {
        $original = (string) file_get_contents($path);
        $interesting = $this->interestingSectors($path, strlen($original));
        $seed = (int) (getenv('FUZZ_SEED') ?: self::DEFAULT_SEED);
        $iterations = max(1, (int) (getenv('FUZZ_ITERATIONS') ?: self::DEFAULT_ITERATIONS));

        for ($case = 0; $case < $iterations; $case++) {
            $caseSeed = ($seed + crc32(basename($path)) + $case * 7919) & 0x7FFFFFFF;
            mt_srand($caseSeed);
            $this->log = [];
            $mutated = $this->mutate($original, $interesting);
            $target = $this->directory . DIRECTORY_SEPARATOR . 'case.iso';
            file_put_contents($target, $mutated);

            $description = basename($path) . ' case ' . $case . ' (seed ' . $caseSeed . '): ' . implode('; ', $this->log);
            $start = microtime(true);
            try {
                $this->exercise($target);
            } catch (Throwable $throwable) {
                $kept = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-fuzz-failure-' . $caseSeed . '.iso';
                copy($target, $kept);
                $this->fail($description . "\nunexpected " . $throwable::class . ': ' . $throwable->getMessage() . ' at ' . $throwable->getFile() . ':' . $throwable->getLine() . "\nimage kept in " . $kept);
            }

            $seconds = microtime(true) - $start;
            if ($seconds > self::MAX_SECONDS) {
                $kept = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-fuzz-slow-' . $caseSeed . '.iso';
                copy($target, $kept);
                $this->fail($description . "\ntook " . round($seconds, 2) . ' s' . "\nimage kept in " . $kept);
            }
        }
    }

    /**
     * Sectors worth corrupting: the ones holding the structures of the original image
     *
     * @return list<int>
     */
    private function interestingSectors(string $path, int $size): array
    {
        $total = intdiv($size, self::SECTOR);
        $sectors = range(16, min(40, $total - 1));

        try {
            $isoFile = new IsoFile($path);
            foreach ($isoFile->descriptors as $descriptor) {
                if ($descriptor instanceof Volume) {
                    array_push($sectors, $descriptor->rootDirectory->location, $descriptor->lPathTablePos, $descriptor->mPathTablePos);
                    foreach ($descriptor->walk($isoFile) as $entry) {
                        if ($entry->isDirectory) {
                            $sectors[] = $entry->location;
                        }
                    }
                }
            }
            $boot = $isoFile->getBootRecord();
            if ($boot !== null) {
                $sectors[] = $boot->bootCatalogLocation;
            }
        } catch (Exception) {
            // a fixture that cannot be parsed (invalid.iso) only has its first sectors
        }

        // UDF: anchor, volume descriptor sequences, the start of the partition and the end of the image
        if ($total > 300) {
            $sectors = [...$sectors, ...range(256, 272), ...range(32, 40), $total - 1, $total - 257];
        }

        $sectors = array_filter($sectors, static fn (int $sector): bool => $sector > 0 && $sector < $total);

        return array_values(array_unique($sectors));
    }

    /**
     * @param list<int> $interesting
     */
    private function mutate(string $data, array $interesting): string
    {
        $size = strlen($data);
        if ($size < 2) {
            return $data;
        }

        $mutations = mt_rand(1, 4);

        for ($i = 0; $i < $mutations; $i++) {
            $kind = mt_rand(0, 9);

            if ($kind <= 2) {
                // random byte flips in the volume descriptor area
                $count = mt_rand(1, 8);
                for ($j = 0; $j < $count; $j++) {
                    $low = min(16 * self::SECTOR, $size - 1);
                    $offset = mt_rand($low, max($low, min(41 * self::SECTOR, $size) - 1));
                    $data[$offset] = chr(mt_rand(0, 255));
                    $this->log[] = 'byte ' . $offset . ' set';
                }
            } elseif ($kind <= 5 && $interesting !== []) {
                // random byte flips in a structure of the original image
                $sector = $interesting[mt_rand(0, count($interesting) - 1)];
                $count = mt_rand(1, 8);
                for ($j = 0; $j < $count; $j++) {
                    $offset = $sector * self::SECTOR + mt_rand(0, self::SECTOR - 1);
                    if ($offset < $size) {
                        $data[$offset] = chr(mt_rand(0, 255));
                    }
                }
                $this->log[] = $count . ' bytes flipped in sector ' . $sector;
            } elseif ($kind <= 7 && $interesting !== []) {
                // a length / location field set to the maximum
                $sector = $interesting[mt_rand(0, count($interesting) - 1)];
                $offset = $sector * self::SECTOR + mt_rand(0, self::SECTOR - 8);
                $width = mt_rand(0, 1) === 1 ? 8 : 4;
                $data = substr_replace($data, str_repeat("\xFF", $width), $offset, $width);
                $this->log[] = $width . ' bytes at ' . $offset . ' (sector ' . $sector . ') set to 0xFF';
            } elseif ($kind === 8) {
                // a 32 bit field overwritten by a small or huge value, both byte orders
                $sector = $interesting === [] ? 16 : $interesting[mt_rand(0, count($interesting) - 1)];
                $offset = $sector * self::SECTOR + mt_rand(0, self::SECTOR - 4);
                $value = [0, 1, 0x7FFFFFFF, 0x80000000, 2047, 2049][mt_rand(0, 5)];
                $data = substr_replace($data, mt_rand(0, 1) === 1 ? pack('V', $value) : pack('N', $value), $offset, 4);
                $this->log[] = 'u32 ' . $value . ' at ' . $offset . ' (sector ' . $sector . ')';
            } else {
                $length = mt_rand(0, $size - 1);
                $data = substr($data, 0, $length);
                $size = $length;
                $this->log[] = 'truncated to ' . $length;
            }

            $size = strlen($data);
            if ($size < 2) {
                break;
            }
        }

        return $data;
    }

    /**
     * Every public entry point, a PhpIso\Exception is an accepted answer for each of them
     */
    private function exercise(string $path): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $isoFile = null;
            $this->step(static function () use (&$isoFile, $path): void {
                $isoFile = new IsoFile($path);
            });
            if (! $isoFile instanceof IsoFile) {
                return;
            }

            /** @var list<FileSystem> $fileSystems */
            $fileSystems = [];
            $this->step(function () use ($isoFile, &$fileSystems): void {
                $candidates = [$isoFile->getFileSystem(), $isoFile->getPrimaryVolume(), $isoFile->getSupplementaryVolume(), $isoFile->getEnhancedVolume(), $isoFile->getPreferredVolume()];
                foreach ($candidates as $candidate) {
                    if ($candidate instanceof FileSystem) {
                        $fileSystems[] = $candidate;
                    }
                }
            });

            $this->step(static function () use ($isoFile, &$fileSystems): void {
                $udf = $isoFile->getUdfFileSystem();
                if ($udf !== null) {
                    $fileSystems[] = $udf;
                }
            });

            foreach ($fileSystems as $fileSystem) {
                $this->step(fn () => $this->browse($isoFile, $fileSystem));
            }

            $this->step(static function () use ($isoFile): void {
                $isoFile->getBootRecord()?->loadCatalog($isoFile);
            });
        } finally {
            restore_error_handler();
        }
    }

    private function browse(IsoFile $isoFile, FileSystem $fileSystem): void
    {
        $first = null;
        $paths = [];
        $count = 0;
        foreach ($fileSystem->walk($isoFile) as $entry) {
            $paths[] = $entry->path;
            if ($first === null && ! $entry->isDirectory) {
                $first = $entry;
            }

            if (++$count >= self::MAX_ENTRIES) {
                break;
            }
        }

        if ($paths !== []) {
            $this->step(static function () use ($isoFile, $fileSystem, $paths): void {
                $fileSystem->find($isoFile, $paths[mt_rand(0, count($paths) - 1)]);
                $fileSystem->find($isoFile, '/nothing/here.txt');
            });
        }

        $this->step(static function () use ($isoFile, $fileSystem): void {
            foreach ($fileSystem->listDirectory($isoFile) as $entry) {
                if ($entry->isDirectory) {
                    foreach ($fileSystem->listDirectory($isoFile, $entry) as $ignored) {
                        break;
                    }
                    break;
                }
            }
        });

        if ($first instanceof IsoEntry) {
            $this->step(static function () use ($isoFile, $fileSystem, $first): void {
                $stream = $fileSystem->openStream($isoFile, $first);
                fread($stream, 1024);
                fclose($stream);
            });
        }
    }

    /**
     * Run a step: a PhpIso\Exception is fine, anything else is reported by the caller
     */
    private function step(callable $step): void
    {
        try {
            $step();
        } catch (Exception) {
            // rejecting a corrupt image is the expected behaviour
        }
    }
}
