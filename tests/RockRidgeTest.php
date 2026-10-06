<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\RockRidge;
use PhpIso\Descriptor\Volume;
use PhpIso\Extractor;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\RockRidgeInfo;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\RockRidgeBuilder as RR;
use PHPUnit\Framework\TestCase;

final class RockRidgeTest extends TestCase
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

    /**
     * @param array<int, string> $records
     */
    private function image(array $records): IsoFile
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), ...$records])
            ->setSector(19, 'ab');
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * @return array<string, IsoEntry>
     */
    private function entries(IsoFile $isoFile, bool $rockRidge = true): array
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        $entries = [];
        foreach ($volume->walk($isoFile, 64, $rockRidge) as $entry) {
            $entries[$entry->path] = $entry;
        }

        return $entries;
    }

    public function testLongNameReplacesTheIsoName(): void
    {
        $isoFile = $this->image([IsoBuilder::record('LONGNA_1.TXT', 19, 0, 0, RR::nm('a long file name.txt'))]);

        $this->assertArrayHasKey('/a long file name.txt', $this->entries($isoFile));
    }

    public function testIsoNameIsKeptWhenRockRidgeIsDisabled(): void
    {
        $isoFile = $this->image([IsoBuilder::record('LONGNA_1.TXT', 19, 0, 0, RR::nm('a long file name.txt'))]);

        $this->assertArrayHasKey('/LONGNA_1.TXT', $this->entries($isoFile, false));
    }

    public function testNameSplitInSeveralEntriesIsJoined(): void
    {
        $isoFile = $this->image([IsoBuilder::record('SPLIT.TXT', 19, 0, 0, RR::nm('first-', 1) . RR::nm('second'))]);

        $this->assertArrayHasKey('/first-second', $this->entries($isoFile));
    }

    public function testPosixAttributesAreExposed(): void
    {
        $isoFile = $this->image([IsoBuilder::record('SCRIPT.SH', 19, 0, 0, RR::px(0100755, 1, 1000, 100))]);

        $info = $this->entries($isoFile)['/SCRIPT.SH']->rockRidge;

        $this->assertInstanceOf(RockRidgeInfo::class, $info);
        $this->assertSame(0755, $info->getPermissions());
        $this->assertSame(1000, $info->uid);
        $this->assertSame(100, $info->gid);
    }

    public function testSymbolicLinkTargetIsParsed(): void
    {
        $isoFile = $this->image([IsoBuilder::record('LINK', 0, 0, 0, RR::px(0120777) . RR::sl('../lib/x.so'))]);

        $entry = $this->entries($isoFile)['/LINK'];

        $this->assertTrue($entry->isSymlink());
        $this->assertSame('../lib/x.so', $entry->rockRidge?->symlink);
    }

    public function testAbsoluteSymbolicLinkTarget(): void
    {
        $info = RockRidge::parse(RR::sl('/etc/hosts'));

        $this->assertSame('/etc/hosts', $info?->symlink);
    }

    public function testSymbolicLinksAreNotExtracted(): void
    {
        $isoFile = $this->image([
            IsoBuilder::record('LINK', 0, 0, 0, RR::sl('../../etc/passwd')),
            IsoBuilder::record('FILE.TXT', 19, 2),
        ]);
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);
        $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-iso-rr-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $destination;

        $count = (new Extractor())->extract($isoFile, $volume, $destination);

        $this->assertSame(1, $count);
        $this->assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'LINK');
    }

    public function testContinuationAreaIsFollowed(): void
    {
        // the NM entry lives in sector 22, the record only has a CE entry pointing to it
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('CONT.TXT', 19, 0, 0, RR::ce(22, 0, 24))])
            ->setSector(22, RR::nm('from-continuation'));
        $path = $builder->save();
        $this->cleanup[] = $path;

        $this->assertArrayHasKey('/from-continuation', $this->entries(new IsoFile($path)));
    }

    public function testEndlessContinuationChainTerminates(): void
    {
        // sector 22 continues into itself
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('LOOP.TXT', 19, 0, 0, RR::ce(22, 0, 28))])
            ->setSector(22, RR::ce(22, 0, 28));
        $path = $builder->save();
        $this->cleanup[] = $path;

        $this->assertArrayHasKey('/LOOP.TXT', $this->entries(new IsoFile($path)));
    }

    public function testRelocatedDirectoryIsListedThroughItsChildLink(): void
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [
                IsoBuilder::record("\0", 18, 2048, 2),
                IsoBuilder::record("\1", 18, 2048, 2),
                IsoBuilder::record('DEEP', 0, 0, 0, RR::cl(23)),
                IsoBuilder::record('RR_MOVED', 24, 2048, 2),
            ])
            ->setDirectory(23, [IsoBuilder::record("\0", 23, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('F.TXT', 25, 1)])
            ->setDirectory(24, [IsoBuilder::record("\0", 24, 2048, 2), IsoBuilder::record("\1", 18, 2048, 2), IsoBuilder::record('DEEP', 23, 2048, 2, RR::re())]);
        $path = $builder->save();
        $this->cleanup[] = $path;

        $entries = $this->entries(new IsoFile($path));

        $this->assertTrue($entries['/DEEP']->isDirectory);
        $this->assertArrayHasKey('/DEEP/F.TXT', $entries);
        $this->assertArrayNotHasKey('/RR_MOVED/DEEP', $entries);
    }

    public function testRecordWithoutRockRidgeHasNoInfo(): void
    {
        $isoFile = $this->image([IsoBuilder::record('PLAIN.TXT', 19, 0)]);

        $this->assertNotInstanceOf(RockRidgeInfo::class, $this->entries($isoFile)['/PLAIN.TXT']->rockRidge);
    }

    public function testGarbageSystemUseIsIgnored(): void
    {
        $this->assertNotInstanceOf(RockRidgeInfo::class, RockRidge::parse("\x01\x02\x03\x04\x05\x06"));
    }

    public function testEntryLongerThanTheAreaIsIgnored(): void
    {
        $this->assertNotInstanceOf(RockRidgeInfo::class, RockRidge::parse('NM' . chr(200) . chr(1) . 'x'));
    }

    public function testPermissionsAndTypeOfInfo(): void
    {
        $info = RockRidge::parse(RR::px(0040750));

        $this->assertInstanceOf(RockRidgeInfo::class, $info);
        $this->assertSame(0750, $info->getPermissions());
        $this->assertFalse($info->isSymlink());
    }
}
