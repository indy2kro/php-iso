<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\Volume;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\RockRidgeBuilder as RR;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\Test\Support\UdfSpec;
use PHPUnit\Framework\TestCase;

/**
 * UDF symbolic links, special file types, owner and permissions (BUG-11, FEAT-07)
 */
final class UdfNodeAttributesTest extends TestCase
{
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

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, IsoEntry>
     */
    private function entries(array $tree): array
    {
        $path = UdfBuilder::build($tree)->save();
        $this->cleanup[] = $path;
        $isoFile = new IsoFile($path);
        $udf = $isoFile->getUdfFileSystem();
        $this->assertInstanceOf(\PhpIso\Udf\UdfFileSystem::class, $udf);

        $entries = [];
        foreach ($udf->walk($isoFile) as $entry) {
            $entries[$entry->path] = $entry;
        }

        return $entries;
    }

    public function testSymlinkTargetIsDecodedFromPathComponents(): void
    {
        $entries = $this->entries([
            'link' => UdfSpec::symlink([[3, ''], [3, ''], [5, 'etc'], [5, 'hosts']]),
            'abs' => UdfSpec::symlink([[2, ''], [5, 'usr'], [4, ''], [5, 'bin']]),
        ]);

        $this->assertTrue($entries['/link']->isSymlink());
        $this->assertSame('../../etc/hosts', $entries['/link']->getSymlinkTarget());
        $this->assertSame('/usr/./bin', $entries['/abs']->getSymlinkTarget());
        $this->assertSame('../../etc/hosts', $entries['/link']->toArray()['symlink']);
    }

    public function testSpecialFileTypesAreNotReported(): void
    {
        $entries = $this->entries([
            'block' => new UdfSpec('', 6),
            'char' => new UdfSpec('', 7),
            'fifo' => new UdfSpec('', 9),
            'socket' => new UdfSpec('', 10),
            'regular.txt' => 'data',
        ]);

        $this->assertSame(['/regular.txt'], array_keys($entries));
        $this->assertFalse($entries['/regular.txt']->isSymlink());
        $this->assertNull($entries['/regular.txt']->getSymlinkTarget());
    }

    public function testOwnerAndPermissionsAreExposed(): void
    {
        // owner rwx (bits 10-12), group r-x (bits 5 and 7: execute and read), other r (bit 2)
        $permissions = (7 << 10) | (5 << 5) | 4;
        $entries = $this->entries(['script.sh' => new UdfSpec('x', 5, 1000, 100, $permissions)]);

        $entry = $entries['/script.sh'];
        $this->assertSame(1000, $entry->uid);
        $this->assertSame(100, $entry->gid);
        $this->assertSame(0100754, $entry->mode);
        $this->assertSame(0100754, $entry->toArray()['mode']);
        $this->assertSame(1000, $entry->toArray()['uid']);
    }

    public function testUnspecifiedOwnerIsNull(): void
    {
        $entries = $this->entries(['a' => new UdfSpec('x', 5, 0xFFFFFFFF, 0xFFFFFFFF, 0)]);

        $this->assertNull($entries['/a']->uid);
        $this->assertNull($entries['/a']->gid);
    }

    public function testRockRidgeOwnerAndModeAreCopiedToTheEntry(): void
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [
                IsoBuilder::record("\0", 18, 2048, 2),
                IsoBuilder::record("\1", 18, 2048, 2),
                IsoBuilder::record('F.TXT', 19, 2, 0, RR::px(0100640, 1, 1001, 1002)),
            ])
            ->setSector(19, 'ab');
        $path = $builder->save();
        $this->cleanup[] = $path;
        $isoFile = new IsoFile($path);
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        $entries = iterator_to_array($volume->walk($isoFile), false);
        $this->assertCount(1, $entries);
        $this->assertSame(1001, $entries[0]->uid);
        $this->assertSame(1002, $entries[0]->gid);
        $this->assertSame(0100640, $entries[0]->mode);
    }
}
