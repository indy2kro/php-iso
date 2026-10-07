<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\Volume;
use PhpIso\Exception;
use PhpIso\IsoFile;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\UdfBuilder;
use PhpIso\Udf\UdfFileSystem;
use PHPUnit\Framework\TestCase;

final class DescriptorRobustnessTest extends TestCase
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

    private function openRaw(string $data): IsoFile
    {
        $path = tempnam(sys_get_temp_dir(), 'iso');
        $this->assertNotFalse($path);
        file_put_contents($path, $data);
        $this->files[] = $path;

        return new IsoFile($path);
    }

    /**
     * An ISO 9660 + UDF bridge image: $isoFiles are the files of the ISO 9660 root, $udfTree the UDF tree
     *
     * @param list<string> $isoFiles
     * @param array<array-key, mixed> $udfTree
     */
    private function bridge(array $isoFiles, array $udfTree): IsoBuilder
    {
        $builder = UdfBuilder::build($udfTree)
            ->addVolumeDescriptor(0, 1, 30)
            ->addTerminator(1);
        foreach (['BEA01', 'NSR02', 'TEA01'] as $index => $identifier) {
            $builder->setSector(18 + $index, chr(0) . $identifier . chr(1));
        }

        $records = [IsoBuilder::record("\0", 30, 2048, 2), IsoBuilder::record("\1", 30, 2048, 2)];
        foreach ($isoFiles as $name) {
            $records[] = IsoBuilder::record($name . ';1', 31, 5);
        }

        return $builder->setDirectory(30, $records);
    }

    public function testNonJolietSupplementaryDescriptorIsEightBit(): void
    {
        $isoFile = $this->open((new IsoBuilder())->addVolumeDescriptor(0)->addVolumeDescriptor(1, 2)->addTerminator(2));

        $this->assertNull($isoFile->getSupplementaryVolume());
        $this->assertNull($isoFile->getEnhancedVolume());
        $this->assertSame('VOLUME', $isoFile->descriptors[2]->volumeId ?? null);
    }

    public function testEnhancedVolumeDescriptorIsEightBit(): void
    {
        $builder = (new IsoBuilder())->addVolumeDescriptor(0)->addVolumeDescriptor(1, 2)->addTerminator(2)->patch(17, 6, "\x02");
        $isoFile = $this->open($builder);

        $enhanced = $isoFile->getEnhancedVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\SupplementaryVolume::class, $enhanced);
        $this->assertSame(2, $enhanced->version);
        $this->assertSame(0, $enhanced->jolietLevel);
        $this->assertSame('SYSTEM', $enhanced->systemId);
        $this->assertSame('VOLUME', $enhanced->volumeId);
        $this->assertNull($isoFile->getSupplementaryVolume());
    }

    public function testJolietVolumeIsFoundBesideAnEnhancedOne(): void
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addVolumeDescriptor(1, 2)
            ->patch(17, 6, "\x02")
            ->addVolumeDescriptor(2, 2, jolietLevel: 1)
            ->addTerminator(3);
        $isoFile = $this->open($builder);

        $joliet = $isoFile->getSupplementaryVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\SupplementaryVolume::class, $joliet);
        $this->assertSame(1, $joliet->jolietLevel);
        $this->assertSame('VOLUME', $joliet->volumeId);
        $this->assertSame($joliet, $isoFile->getPreferredVolume());

        $enhanced = $isoFile->getEnhancedVolume();
        $this->assertInstanceOf(\PhpIso\Descriptor\SupplementaryVolume::class, $enhanced);
        $this->assertSame(2, $enhanced->version);
    }

    public function testBadStandardIdentifierIsRejected(): void
    {
        $builder = (new IsoBuilder())->addVolumeDescriptor(0)->patch(16, 1, 'XXXXX');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Not an ISO 9660 volume descriptor');
        $this->open($builder);
    }

    public function testTooManyDescriptorsAreRejected(): void
    {
        $builder = new IsoBuilder();
        for ($i = 0; $i < IsoFile::MAX_DESCRIPTORS + 6; $i++) {
            $builder->addVolumeDescriptor($i);
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Too many volume descriptors');
        $this->open($builder);
    }

    public function testTruncatedDescriptorHasAClearMessage(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Truncated or invalid ISO image: volume descriptor at sector 16 is incomplete');
        $this->openRaw(str_repeat("\0", 16 * 2048) . str_repeat('A', 100));
    }

    public function testInvalidFixtureHasAClearMessage(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Truncated or invalid ISO image');
        new IsoFile(dirname(__FILE__, 2) . '/fixtures/invalid.iso');
    }

    public function testDirectoryIsNotAFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Not a file');
        new IsoFile(sys_get_temp_dir());
    }

    public function testOpenFileTwiceKeepsAWorkingHandle(): void
    {
        $isoFile = $this->open((new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1));

        $isoFile->openFile();
        $isoFile->openFile();

        $this->assertSame(0, $isoFile->seek(16 * 2048));
        $this->assertSame(chr(1) . 'CD001', $isoFile->read(6));
    }

    public function testStubIsoTreeFallsBackToUdf(): void
    {
        $isoFile = $this->open($this->bridge(['README.TXT'], ['sources' => ['install.wim' => 'data'], 'setup.exe' => 'exe']));

        $this->assertInstanceOf(UdfFileSystem::class, $isoFile->getFileSystem());
    }

    public function testIsoTreeWithSeveralFilesIsKept(): void
    {
        $isoFile = $this->open($this->bridge(['A.TXT', 'B.TXT'], ['sources' => [], 'setup.exe' => 'exe', 'c.txt' => 'c']));

        $this->assertInstanceOf(Volume::class, $isoFile->getFileSystem());
    }

    public function testStubIsoTreeIsKeptWhenUdfIsNotBigger(): void
    {
        $isoFile = $this->open($this->bridge(['README.TXT'], ['readme.txt' => 'data']));

        $this->assertInstanceOf(Volume::class, $isoFile->getFileSystem());
    }
}
