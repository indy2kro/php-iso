<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\RockRidge;
use PhpIso\Descriptor\Volume;
use PhpIso\FileDirectory;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\RockRidgeInfo;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\Records;
use PhpIso\Test\Support\RockRidgeBuilder as RR;
use PHPUnit\Framework\TestCase;

final class WalkFixesTest extends TestCase
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
     * @param array<int, string> $records records of the root directory (sector 18), after "." and ".."
     * @param array<int, string> $sectors extra sectors (number => content)
     */
    private function image(array $records, array $sectors = [], string $rootSystemUse = ''): IsoFile
    {
        $builder = (new IsoBuilder())
            ->addVolumeDescriptor(0)
            ->addTerminator(1)
            ->setDirectory(18, [IsoBuilder::record("\0", 18, 2048, 2, $rootSystemUse), IsoBuilder::record("\1", 18, 2048, 2), ...$records]);
        foreach ($sectors as $number => $content) {
            $builder->setSector($number, $content);
        }
        $path = $builder->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    /**
     * @return array<string, IsoEntry>
     */
    private function entries(IsoFile $isoFile): array
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        $entries = [];
        foreach ($volume->walk($isoFile) as $entry) {
            $entries[$entry->path] = $entry;
        }

        return $entries;
    }

    private function content(IsoFile $isoFile, IsoEntry $entry): string
    {
        $volume = $isoFile->getPreferredVolume();
        $this->assertInstanceOf(Volume::class, $volume);

        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($output);
        $volume->copyEntryTo($isoFile, $entry, $output);
        rewind($output);

        return (string) stream_get_contents($output);
    }

    public function testAnyVersionSuffixIsStripped(): void
    {
        $this->assertSame('A.TXT', Records::directory('A.TXT;32767')->fileId);
        $this->assertSame('A.TXT', Records::directory('A.TXT;2')->fileId);
    }

    public function testSeparatorDotOfNamesWithoutExtensionIsStripped(): void
    {
        $this->assertSame('README', Records::directory('README.;1')->fileId);
        $this->assertSame('LICENSE', Records::directory('LICENSE.')->fileId);
    }

    public function testJolietNameLosesItsVersionButKeepsItsDots(): void
    {
        $bytes = Records::bytes(IsoBuilder::record(mb_convert_encoding('a.b;1', 'UTF-16BE', 'UTF-8'), 0, 0));
        $offset = 1;

        $this->assertSame('a.b', FileDirectory::read($bytes, $offset, true, 3)?->fileId);

        $bytes = Records::bytes(IsoBuilder::record(mb_convert_encoding('a.', 'UTF-16BE', 'UTF-8'), 0, 0));
        $offset = 1;

        $this->assertSame('a.', FileDirectory::read($bytes, $offset, true, 3)?->fileId);
    }

    public function testAssociatedFilesAreNotListed(): void
    {
        $isoFile = $this->image([
            IsoBuilder::record('A.TXT;1', 19, 2),
            IsoBuilder::record('A.TXT;1', 20, 2, FileDirectory::FILE_MODE_ASSOCIATED),
        ], [19 => 'ab', 20 => 'cd']);

        $entries = $this->entries($isoFile);

        $this->assertCount(1, $entries);
        $this->assertSame('ab', $this->content($isoFile, $entries['/A.TXT']));
    }

    public function testDataStartsAfterTheExtendedAttributeRecord(): void
    {
        $record = substr_replace(IsoBuilder::record('X.TXT;1', 19, 2), chr(1), 1, 1);
        $isoFile = $this->image([$record], [19 => 'XX', 20 => 'ab']);

        $entry = $this->entries($isoFile)['/X.TXT'];

        $this->assertSame(20, $entry->location);
        $this->assertSame('ab', $this->content($isoFile, $entry));
    }

    public function testExtendedAttributeRecordIsSkippedInEveryExtent(): void
    {
        $first = substr_replace(IsoBuilder::record('X.DAT;1', 19, 2, FileDirectory::FILE_MODE_MULTI_EXTENT), chr(1), 1, 1);
        $second = substr_replace(IsoBuilder::record('X.DAT;1', 22, 2), chr(2), 1, 1);
        $isoFile = $this->image([$first, $second], [19 => 'XX', 20 => 'ab', 22 => 'YY', 23 => 'YY', 24 => 'cd']);

        $this->assertSame('abcd', $this->content($isoFile, $this->entries($isoFile)['/X.DAT']));
    }

    public function testMultiExtentFileKeepsItsRockRidgeData(): void
    {
        $system = RR::nm('big file.dat') . RR::px(0100644, 1, 1000, 100);
        $isoFile = $this->image([
            IsoBuilder::record('BIG.DAT;1', 19, 2, FileDirectory::FILE_MODE_MULTI_EXTENT, $system),
            IsoBuilder::record('BIG.DAT;1', 20, 2, 0, $system),
        ], [19 => 'ab', 20 => 'cd']);

        $entry = $this->entries($isoFile)['/big file.dat'];

        $this->assertSame('big file.dat', $entry->name);
        $this->assertSame(4, $entry->size);
        $this->assertSame(1000, $entry->rockRidge?->uid);
        $this->assertSame('abcd', $this->content($isoFile, $entry));
    }

    public function testPathTableRecordsAlwaysUseForwardSlashes(): void
    {
        $root = Records::pathRecord('ROOT', 1, 1);
        $dir = Records::pathRecord('DIR', 1, 2);
        $sub = Records::pathRecord('SUB', 2, 3);
        $table = [1 => $root, 2 => $dir, 3 => $sub];

        $this->assertSame('/DIR/', $dir->getFullPath($table));
        $this->assertSame('/DIR/SUB/', $sub->getFullPath($table));
    }

    public function testEntriesAfterAContinuationEntryAreKept(): void
    {
        $continuation = RR::px(0100600, 1, 7, 8);
        $system = RR::ce(22, 0, strlen($continuation)) . RR::nm('after-ce');
        $isoFile = $this->image([IsoBuilder::record('F.TXT;1', 19, 2, 0, $system)], [22 => $continuation]);

        $entry = $this->entries($isoFile)['/after-ce'];

        $this->assertSame(7, $entry->rockRidge?->uid);
    }

    public function testSystemUseAreaIsSkippedAsAnnouncedByTheSpEntry(): void
    {
        $record = IsoBuilder::record('F.TXT;1', 19, 2, 0, 'XXXX' . RR::nm('skipped-ok'));

        $this->assertArrayHasKey('/skipped-ok', $this->entries($this->image([$record], [], RR::sp(4))));
        $this->assertArrayHasKey('/F.TXT', $this->entries($this->image([$record])));
    }

    public function testParseHonoursTheSkipParameter(): void
    {
        $this->assertSame('n', RockRidge::parse('ZZ' . RR::nm('n'), null, 2048, 2)?->name);
        $this->assertNotInstanceOf(RockRidgeInfo::class, RockRidge::parse('ZZ' . RR::nm('n')));
    }

    public function testTimestampsAreParsed(): void
    {
        $info = RockRidge::parse(RR::tf(0x01 | 0x02 | 0x04 | 0x08, RR::date7(2020, 1, 2, 3, 4, 5) . RR::date7(2021, 2, 3, 4, 5, 6) . RR::date7(2022, 3, 4, 5, 6, 7) . RR::date7(2023, 4, 5, 6, 7, 8)));

        $this->assertInstanceOf(RockRidgeInfo::class, $info);
        $this->assertSame('2020-01-02 03:04:05', $info->creationTime?->format('Y-m-d H:i:s'));
        $this->assertSame('2021-02-03 04:05:06', $info->modifyTime?->format('Y-m-d H:i:s'));
        $this->assertSame('2022-03-04 05:06:07', $info->accessTime?->format('Y-m-d H:i:s'));
        $this->assertSame('2023-04-05 06:07:08', $info->attributesTime?->format('Y-m-d H:i:s'));
    }

    public function testOnlyThePresentTimestampsAreSet(): void
    {
        $info = RockRidge::parse(RR::tf(0x02, RR::date7(2021, 2, 3)));

        $this->assertInstanceOf(RockRidgeInfo::class, $info);
        $this->assertNull($info->creationTime);
        $this->assertSame('2021-02-03', $info->modifyTime?->format('Y-m-d'));
    }

    public function testLongFormTimestampsAreParsed(): void
    {
        $info = RockRidge::parse(RR::tf(0x80 | 0x02, RR::date17(2030, 12, 31, 23, 59, 58)));

        $this->assertSame('2030-12-31 23:59:58', $info?->modifyTime?->format('Y-m-d H:i:s'));
    }

    public function testTruncatedTimestampsAreIgnored(): void
    {
        $info = RockRidge::parse(RR::tf(0x03, RR::date7(2021, 2, 3)) . RR::nm('x'));

        $this->assertInstanceOf(RockRidgeInfo::class, $info);
        $this->assertSame('x', $info->name);
        $this->assertNull($info->modifyTime);
    }

    public function testDeviceNumbersAreParsed(): void
    {
        $info = RockRidge::parse(RR::pn(8, 1));

        $this->assertInstanceOf(RockRidgeInfo::class, $info);
        $this->assertSame(8, $info->deviceHigh);
        $this->assertSame(1, $info->deviceLow);
    }
}
