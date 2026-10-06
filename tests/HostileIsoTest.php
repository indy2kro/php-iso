<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Exception;
use PhpIso\Extractor;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Crafted images must never hang, exhaust memory or write outside of the destination
 */
final class HostileIsoTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            $this->remove($path);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
                $this->remove($path . DIRECTORY_SEPARATOR . $name);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function open(IsoBuilder $builder): IsoFile
    {
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    private function baseImage(int $rootSize = 2048, int $pathTableSize = 0, int $pathTableLocation = 0, int $blockSize = IsoBuilder::SECTOR): IsoBuilder
    {
        return (new IsoBuilder())
            ->addVolumeDescriptor(0, 1, 18, $rootSize, $pathTableSize, $pathTableLocation, $blockSize)
            ->addTerminator(1);
    }

    /**
     * @return array<int, IsoEntry>
     */
    private function walk(IsoFile $isoFile): array
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        return iterator_to_array($volume->walk($isoFile), false);
    }

    public function testValidSyntheticImageIsWalkedAndExtracted(): void
    {
        $builder = $this->baseImage()
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('A.TXT', 19, 5)])
            ->setSector(19, 'hello');
        $isoFile = $this->open($builder);

        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-h-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;

        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);
        (new Extractor())->extract($isoFile, $volume, $destination);

        $this->assertSame('hello', file_get_contents($destination . DIRECTORY_SEPARATOR . 'A.TXT'));
    }

    public function testPathTraversalNameIsRejectedAndNothingIsWrittenOutside(): void
    {
        $builder = $this->baseImage()
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('../EVIL.TXT', 19, 5)])
            ->setSector(19, 'owned');
        $isoFile = $this->open($builder);

        $parent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-p-' . bin2hex(random_bytes(4));
        $destination = $parent . DIRECTORY_SEPARATOR . 'out';
        $this->cleanup[] = $parent;
        mkdir($parent);

        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        try {
            (new Extractor())->extract($isoFile, $volume, $destination);
            $this->fail('The unsafe name must be rejected');
        } catch (Exception) {
            $this->assertFileDoesNotExist($parent . DIRECTORY_SEPARATOR . 'EVIL.TXT');
        }
    }

    public function testDirectoryLoopTerminates(): void
    {
        // SUB (sector 19) contains a directory pointing back to the root
        $builder = $this->baseImage()
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('SUB', 19, 2048, 2)])
            ->setDirectory(19, [IsoBuilder::record("\0", 19, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('BACK', 18, 2048, 2)]);

        $paths = array_map(static fn (IsoEntry $entry): string => $entry->path, $this->walk($this->open($builder)));

        $this->assertSame(['/SUB', '/SUB/BACK'], $paths);
    }

    public function testHugeFileSizeFailsInsteadOfLooping(): void
    {
        $builder = $this->baseImage()
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('BIG.BIN', 19, 0xFFFFFFF0)]);
        $isoFile = $this->open($builder);

        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-b-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        $this->expectException(Exception::class);

        (new Extractor())->extract($isoFile, $volume, $destination);
    }

    public function testHugePathTableSizeIsIgnored(): void
    {
        $isoFile = $this->open($this->baseImage(2048, 0x7FFFFFFF, 19));
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\Volume::class, $volume);

        $this->assertNull($volume->loadTable($isoFile));
    }

    public function testCorruptDirectoryRecordLengthDoesNotCrash(): void
    {
        $builder = $this->baseImage()->setSector(18, chr(5) . 'junk');

        $this->assertSame([], $this->walk($this->open($builder)));
    }

    public function testZeroBlockSizeProducesNoEntries(): void
    {
        $this->assertSame([], $this->walk($this->open($this->baseImage(2048, 0, 0, 0))));
    }

    public function testDuplicateDescriptorsDoNotStopReading(): void
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0, 2)
            ->addVolumeDescriptor(1, 2)
            ->addTerminator(2);
        $isoFile = $this->open($builder);

        $this->assertCount(1, $isoFile->additionalDescriptors);
        $this->assertInstanceOf(\PhpIso\Descriptor\SupplementaryVolume::class, $isoFile->getSupplementaryVolume());
    }

    public function testReadIsCappedForHugeLengths(): void
    {
        $isoFile = $this->open($this->baseImage());

        $this->assertFalse($isoFile->read(IsoFile::MAX_READ_LENGTH + 1));
    }
}
