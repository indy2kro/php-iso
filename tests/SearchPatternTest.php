<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\BrowsesEntries;
use PhpIso\FileSystem;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchPatternTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, list<string>}>
     */
    public static function patterns(): \Iterator
    {
        yield 'name only' => ['*.txt', ['/docs/a.txt', '/b.txt', '/docs/sub/c.txt']];
        yield 'path' => ['docs/*.txt', ['/docs/a.txt']];
        yield 'path with leading slash' => ['/docs/*.txt', ['/docs/a.txt']];
        yield 'path case insensitive' => ['DOCS/A.TXT', ['/docs/a.txt']];
        yield 'star does not cross slash' => ['/*.txt', ['/b.txt']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('patterns')]
    public function testSearch(string $pattern, array $expected): void
    {
        $paths = ['/docs', '/docs/a.txt', '/docs/sub', '/docs/sub/c.txt', '/b.txt'];
        $entries = array_map(
            static fn (string $p): IsoEntry => new IsoEntry($p, basename($p), ! str_contains($p, '.'), 1, 0, null, false),
            $paths,
        );

        $fs = new readonly class ($entries) implements FileSystem {
            use BrowsesEntries;

            /** @param list<IsoEntry> $entries */
            public function __construct(private array $entries)
            {
            }

            public function walk(IsoFile $isoFile, int $maxDepth = 64): \Generator
            {
                yield from $this->entries;
            }

            public function copyEntryTo(IsoFile $isoFile, IsoEntry $entry, mixed $output): void
            {
            }
        };

        $found = array_map(
            static fn (IsoEntry $e): string => $e->path,
            iterator_to_array($fs->search(new IsoFile(dirname(__DIR__) . '/fixtures/subdir.iso'), $pattern), false),
        );

        $this->assertEqualsCanonicalizing($expected, $found);
    }
}
