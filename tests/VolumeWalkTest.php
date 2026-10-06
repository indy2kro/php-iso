<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PHPUnit\Framework\TestCase;

final class VolumeWalkTest extends TestCase
{
    /**
     * @return array<int, IsoEntry>
     */
    private function walk(string $fixture): array
    {
        $isoFile = new IsoFile(dirname(__DIR__) . '/fixtures/' . $fixture);
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        return iterator_to_array($volume->walk($isoFile), false);
    }

    /**
     * @param array<int, IsoEntry> $entries
     *
     * @return array<int, string>
     */
    private function paths(array $entries): array
    {
        return array_map(static fn (IsoEntry $entry): string => $entry->path, $entries);
    }

    public function testWalkFindsFilesInNestedDirectories(): void
    {
        $paths = $this->paths($this->walk('subdir.iso'));

        $this->assertContains('/DIR1/DIR2/DIR3/TEST4.TXT', $paths);
        $this->assertContains('/DIR1/DIR2', $paths);
    }

    public function testWalkReportsFileSize(): void
    {
        $files = array_values(array_filter($this->walk('subdir.iso'), static fn (IsoEntry $entry): bool => $entry->path === '/TEST1.TXT'));

        $this->assertSame(6, $files[0]->size);
    }

    public function testWalkPrefersJolietLongNames(): void
    {
        $this->assertContains('/nimbie.jpg', $this->paths($this->walk('iso9660_udf.iso')));
    }

    public function testWalkListsFilesOfNestedDirectoryFromFixture(): void
    {
        $this->assertContains('/CLASSES/PATH_TAB.PHP', $this->paths($this->walk('test.iso')));
    }

    public function testEntryToArray(): void
    {
        $entry = $this->walk('subdir.iso')[0];

        $this->assertSame($entry->path, $entry->toArray()['path']);
    }
}
