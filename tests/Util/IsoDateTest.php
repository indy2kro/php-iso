<?php

declare(strict_types=1);

namespace PhpIso\Test\Util;

use PHPUnit\Framework\TestCase;
use PhpIso\Exception;
use PhpIso\Util\IsoDate;
use Carbon\CarbonImmutable;

final class IsoDateTest extends TestCase
{
    public function testInit7ValidDate(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 16]; // Represents 2023-05-15 10:30:45 UTC+4
        $offset = 0;
        $expectedDate = CarbonImmutable::create(2023, 5, 15, 10, 30, 45, '+04:00');

        $date = IsoDate::init7($buffer, $offset);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertEquals($expectedDate, $date);
        $this->assertSame(7, $offset);
    }

    public function testInit7InvalidDate(): void
    {
        $buffer = [0, 0, 0, 0, 0, 0, 0]; // Invalid date
        $offset = 0;

        $date = IsoDate::init7($buffer, $offset);

        $this->assertNotInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(7, $offset);
    }

    public function testInit17ValidDate(): void
    {
        $buffer = array_map(ord(...), str_split('2023051510304516')); // Represents 2023-05-15 10:30:45.16 UTC
        $offset = 0;
        $expectedDate = CarbonImmutable::create(2023, 5, 15, 10, 30, 45, 0);
        $this->assertInstanceOf(CarbonImmutable::class, $expectedDate);
        $expectedDate = $expectedDate->addMilliseconds(160); // the last two digits are hundredths of a second

        $date = IsoDate::init17($buffer, $offset);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertEquals($expectedDate, $date);
        $this->assertSame(17, $offset);
    }

    public function testInit17WithFloatNotationDigitsIsNotAWarning(): void
    {
        // found by the fuzzer: "2e24" is a float string, casting it to int is deprecated
        foreach (['2e24012300000000', '1e100101000000000', '0x1F0101000000000', '    0101000000000'] as $text) {
            $buffer = array_map(ord(...), str_split(str_pad($text, 16, '0')));
            $offset = 0;

            // the date itself does not matter, the conversion must not raise a warning or a deprecation
            IsoDate::init17($buffer, $offset);

            $this->assertSame(17, $offset);
        }
    }

    public function testInit17InvalidDate(): void
    {
        $buffer = array_map(ord(...), str_split('0000000000000000')); // Invalid date
        $offset = 0;

        $date = IsoDate::init17($buffer, $offset);

        $this->assertNotInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(17, $offset);
    }

    public function testInit7NegativeUtcOffset(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 0xFC]; // -4 intervals of 15 minutes = UTC-01:00
        $offset = 0;

        $date = IsoDate::init7($buffer, $offset);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(-3600, $date->getOffset());
    }

    public function testInit7InvalidMonthReturnsNull(): void
    {
        $buffer = [123, 13, 40, 10, 30, 45, 0];
        $offset = 0;

        $this->assertNotInstanceOf(CarbonImmutable::class, IsoDate::init7($buffer, $offset));
    }

    public function testInit7ShortBufferThrows(): void
    {
        $buffer = [123, 5];
        $offset = 0;

        $this->expectException(Exception::class);

        IsoDate::init7($buffer, $offset);
    }

    public function testInit17ReadsUtcOffsetByte(): void
    {
        $buffer = [...array_map(ord(...), str_split('2023051510304500')), 8]; // +2 hours
        $offset = 0;

        $date = IsoDate::init17($buffer, $offset);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(7200, $date->getOffset());
    }

    public function testCreateWithOffsetMinutesRejectsImpossibleDate(): void
    {
        $this->assertNotInstanceOf(CarbonImmutable::class, IsoDate::createWithOffsetMinutes(2020, 2, 31, 0, 0, 0, 0));
        $this->assertNotInstanceOf(CarbonImmutable::class, IsoDate::createWithOffsetMinutes(2021, 2, 29, 0, 0, 0, 0));
        $this->assertInstanceOf(CarbonImmutable::class, IsoDate::createWithOffsetMinutes(2020, 2, 29, 0, 0, 0, 0));
    }

    public function testInit7OffsetOutOfRangeIsIgnored(): void
    {
        foreach ([53, 100, 0x80, 0xCF] as $byte) {
            $buffer = [123, 5, 15, 10, 30, 45, $byte];
            $offset = 0;
            $date = IsoDate::init7($buffer, $offset);
            $this->assertInstanceOf(CarbonImmutable::class, $date);
            $this->assertSame(0, $date->getOffset());
            $this->assertSame('2023-05-15 10:30:45', $date->toDateTimeString());
        }
    }

    public function testInit7OffsetBoundsAreKept(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 52];
        $offset = 0;
        $date = IsoDate::init7($buffer, $offset);
        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(52 * 900, $date->getOffset());

        $buffer = [123, 5, 15, 10, 30, 45, 0xD0]; // -48
        $offset = 0;
        $date = IsoDate::init7($buffer, $offset);
        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(-48 * 900, $date->getOffset());
    }

    public function testInit17OffsetOutOfRangeIsIgnored(): void
    {
        $buffer = [...array_map(ord(...), str_split('2023051510304500')), 100];
        $offset = 0;
        $date = IsoDate::init17($buffer, $offset);
        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame(0, $date->getOffset());
    }

    public function testCreateWithOffsetMinutesOutOfRangeIsIgnored(): void
    {
        $this->assertSame(0, IsoDate::createWithOffsetMinutes(2023, 5, 15, 1, 2, 3, 781)?->getOffset());
        $this->assertSame(0, IsoDate::createWithOffsetMinutes(2023, 5, 15, 1, 2, 3, -721)?->getOffset());
        $this->assertSame(780 * 60, IsoDate::createWithOffsetMinutes(2023, 5, 15, 1, 2, 3, 780)?->getOffset());
        $this->assertSame(-720 * 60, IsoDate::createWithOffsetMinutes(2023, 5, 15, 1, 2, 3, -720)?->getOffset());
    }

    public function testDatesAreImmutable(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 0];
        $offset = 0;
        $date = IsoDate::init7($buffer, $offset);
        $this->assertInstanceOf(CarbonImmutable::class, $date);

        $moved = $date->addDay();

        $this->assertNotSame($date, $moved);
        $this->assertSame('2023-05-15 10:30:45', $date->toDateTimeString());
        $this->assertSame('2023-05-16 10:30:45', $moved->toDateTimeString());
    }
}
