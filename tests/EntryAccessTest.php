<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\Volume;
use PhpIso\Exception;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\IsoTree;
use PHPUnit\Framework\TestCase;

final class EntryAccessTest extends TestCase
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

    private function open(IsoBuilder $builder): IsoFile
    {
        $path = $builder->save();
        $this->files[] = $path;

        return new IsoFile($path);
    }

    private function volume(IsoFile $isoFile): Volume
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        return $volume;
    }

    private function sample(): IsoFile
    {
        return $this->open(IsoTree::build(['DIR' => ['A.TXT' => 'alpha', 'B.LOG' => 'beta'], 'C.TXT' => 'gamma', 'EMPTY.BIN' => '']));
    }

    public function testFindReturnsExactPath(): void
    {
        $isoFile = $this->sample();

        $entry = $this->volume($isoFile)->find($isoFile, '/DIR/A.TXT');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame(5, $entry->size);
    }

    public function testFindIsCaseInsensitiveAndAcceptsBackslashes(): void
    {
        $isoFile = $this->sample();

        $entry = $this->volume($isoFile)->find($isoFile, 'dir\\a.txt');

        $this->assertInstanceOf(IsoEntry::class, $entry);
        $this->assertSame('/DIR/A.TXT', $entry->path);
    }

    public function testFindUnknownPathReturnsNull(): void
    {
        $isoFile = $this->sample();

        $this->assertNotInstanceOf(IsoEntry::class, $this->volume($isoFile)->find($isoFile, '/NOPE'));
    }

    public function testSearchMatchesPatternCaseInsensitively(): void
    {
        $isoFile = $this->sample();

        $paths = array_map(static fn (IsoEntry $entry): string => $entry->path, iterator_to_array($this->volume($isoFile)->search($isoFile, '*.txt'), false));

        $this->assertEqualsCanonicalizing(['/DIR/A.TXT', '/C.TXT'], $paths);
    }

    public function testReadFileReturnsContent(): void
    {
        $isoFile = $this->sample();
        $volume = $this->volume($isoFile);
        $entry = $volume->find($isoFile, '/C.TXT');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->assertSame('gamma', $volume->readFile($isoFile, $entry));
    }

    public function testReadFileOfEmptyFileIsEmpty(): void
    {
        $isoFile = $this->sample();
        $volume = $this->volume($isoFile);
        $entry = $volume->find($isoFile, '/EMPTY.BIN');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->assertSame('', $volume->readFile($isoFile, $entry));
    }

    public function testReadFileRefusesFilesBiggerThanTheLimit(): void
    {
        $isoFile = $this->sample();
        $volume = $this->volume($isoFile);
        $entry = $volume->find($isoFile, '/C.TXT');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->expectException(Exception::class);

        $volume->readFile($isoFile, $entry, 2);
    }

    public function testOpenStreamIsReadableFromTheStart(): void
    {
        $isoFile = $this->sample();
        $volume = $this->volume($isoFile);
        $entry = $volume->find($isoFile, '/DIR/B.LOG');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $stream = $volume->openStream($isoFile, $entry);

        $this->assertSame('beta', fread($stream, 100));
        fclose($stream);
    }

    public function testDirectoriesCannotBeRead(): void
    {
        $isoFile = $this->sample();
        $volume = $this->volume($isoFile);
        $entry = $volume->find($isoFile, '/DIR');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->expectException(Exception::class);

        $volume->readFile($isoFile, $entry);
    }

    private function multiExtentImage(): IsoBuilder
    {
        return (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [
                IsoBuilder::record("\0", 18, 2048, 2),
                IsoBuilder::record("\1", 18, 2048, 2),
                IsoBuilder::record('BIG.BIN', 19, 4, 0x80),
                IsoBuilder::record('BIG.BIN', 20, 3, 0),
                IsoBuilder::record('NEXT.TXT', 21, 2, 0),
            ])
            ->setSector(19, 'abcd')
            ->setSector(20, 'efg')
            ->setSector(21, 'zz');
    }

    public function testMultiExtentFileIsReportedOnce(): void
    {
        $isoFile = $this->open($this->multiExtentImage());
        $entries = iterator_to_array($this->volume($isoFile)->walk($isoFile), false);

        $this->assertSame(['/BIG.BIN', '/NEXT.TXT'], array_map(static fn (IsoEntry $entry): string => $entry->path, $entries));
        $this->assertSame(7, $entries[0]->size);
        $this->assertCount(2, $entries[0]->getExtents());
    }

    public function testMultiExtentFileContentIsConcatenated(): void
    {
        $isoFile = $this->open($this->multiExtentImage());
        $volume = $this->volume($isoFile);
        $entry = $volume->find($isoFile, '/BIG.BIN');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->assertSame('abcdefg', $volume->readFile($isoFile, $entry));
    }

    public function testMultiExtentFollowedByAnotherNameIsStillReported(): void
    {
        // the second extent of BIG.BIN is missing: the dangling entry must be reported before NEXT.TXT
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [
                IsoBuilder::record("\0", 18, 2048, 2),
                IsoBuilder::record("\1", 18, 2048, 2),
                IsoBuilder::record('BIG.BIN', 19, 4, 0x80),
                IsoBuilder::record('NEXT.TXT', 21, 2, 0),
            ])
            ->setSector(19, 'abcd')
            ->setSector(21, 'zz');
        $isoFile = $this->open($builder);

        $paths = array_map(static fn (IsoEntry $entry): string => $entry->path, iterator_to_array($this->volume($isoFile)->walk($isoFile), false));

        $this->assertSame(['/BIG.BIN', '/NEXT.TXT'], $paths);
    }

    public function testMultiExtentFileLeftOpenAtTheEndOfTheDirectoryIsReported(): void
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [
                IsoBuilder::record("\0", 18, 2048, 2),
                IsoBuilder::record("\1", 18, 2048, 2),
                IsoBuilder::record('OPEN.BIN', 19, 4, 0x80),
            ])
            ->setSector(19, 'abcd');
        $isoFile = $this->open($builder);

        $entries = iterator_to_array($this->volume($isoFile)->walk($isoFile), false);

        $this->assertCount(1, $entries);
        $this->assertSame(4, $entries[0]->size);
    }

    public function testRegularEntryHasASingleExtent(): void
    {
        $isoFile = $this->sample();
        $entry = $this->volume($isoFile)->find($isoFile, '/C.TXT');
        $this->assertInstanceOf(IsoEntry::class, $entry);

        $this->assertSame([[$entry->location, 5]], $entry->getExtents());
    }
}
