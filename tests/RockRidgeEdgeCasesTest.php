<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PhpIso\Descriptor\RockRidge;
use PhpIso\IsoFile;
use PhpIso\RockRidgeInfo;
use PhpIso\Test\Support\IsoBuilder;
use PhpIso\Test\Support\RockRidgeBuilder as RR;
use PHPUnit\Framework\TestCase;

/**
 * Malformed or unusual System Use entries
 */
final class RockRidgeEdgeCasesTest extends TestCase
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

    private function image(): IsoFile
    {
        $path = (new IsoBuilder())->addVolumeDescriptor(0)->addTerminator(1)->setSector(19, 'x')->save();
        $this->cleanup[] = $path;

        return new IsoFile($path);
    }

    private function parse(string $systemUse, ?IsoFile $isoFile = null): RockRidgeInfo
    {
        $info = RockRidge::parse($systemUse, $isoFile);
        $this->assertInstanceOf(RockRidgeInfo::class, $info);

        return $info;
    }

    public function testContinuationAreaOutsideOfTheImageKeepsTheEntriesBeforeIt(): void
    {
        $info = $this->parse(RR::nm('name.txt') . RR::ce(99999, 0, 20), $this->image());

        $this->assertSame('name.txt', $info->name);
    }

    public function testContinuationAreaWithAnImpossibleSizeIsIgnored(): void
    {
        $isoFile = $this->image();

        $this->assertSame('a', $this->parse(RR::nm('a') . RR::ce(19, 0, 0), $isoFile)->name);
        $this->assertSame('b', $this->parse(RR::nm('b') . RR::ce(19, 0, 4096), $isoFile)->name);
    }

    public function testContinuationEntryCutShortIsIgnored(): void
    {
        $info = $this->parse(RR::nm('a') . 'CE' . chr(8) . chr(1) . "\x01\x00\x00\x00", $this->image());

        $this->assertSame('a', $info->name);
    }

    public function testNameEntryWithoutPayloadIsFoundButEmpty(): void
    {
        $info = $this->parse('NM' . chr(4) . chr(1));

        $this->assertContains($info->name, [null, '']);
    }

    public function testTimestampsEntryWithoutPayloadHasNoTimes(): void
    {
        $info = $this->parse('TF' . chr(4) . chr(1) . RR::nm('n'));

        $this->assertNotInstanceOf(\Carbon\CarbonImmutable::class, $info->modifyTime);
        $this->assertSame('n', $info->name);
    }

    public function testPosixAttributesEntryCutShortHasNoMode(): void
    {
        $info = $this->parse('PX' . chr(6) . chr(1) . 'ab' . RR::nm('n'));

        $this->assertNull($info->mode);
        $this->assertNull($info->uid);
        $this->assertSame('n', $info->name);
    }

    public function testSymbolicLinkWithCurrentAndParentDirectoryComponents(): void
    {
        $this->assertSame('./x', $this->parse(RR::sl('./x'))->symlink);
        $this->assertSame('../x', $this->parse(RR::sl('../x'))->symlink);
        $this->assertSame('/x', $this->parse(RR::sl('/x'))->symlink);
    }

    public function testSymbolicLinkSplitInSeveralEntries(): void
    {
        // the "continue" flag of the last component joins it with the first one of the next entry
        $first = 'SL' . chr(4 + 1 + 2 + 2) . chr(1) . chr(0) . chr(0x01) . chr(2) . 'ab';
        $second = 'SL' . chr(4 + 1 + 2 + 2) . chr(1) . chr(0) . chr(0) . chr(2) . 'cd';

        $this->assertSame('abcd', $this->parse($first . $second)->symlink);
    }

    public function testEntriesAfterTheTerminatorAreIgnored(): void
    {
        $info = $this->parse(RR::nm('kept') . 'ST' . chr(4) . chr(1) . RR::nm('lost'));

        $this->assertSame('kept', $info->name);
    }
}
