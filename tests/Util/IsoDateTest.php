<?php

declare(strict_types=1);

namespace PhpIso\Test\Util;

use PHPUnit\Framework\TestCase;
use PhpIso\Exception;
use PhpIso\Util\IsoDate;
use Carbon\Carbon;

final class IsoDateTest extends TestCase
{
    public function testInit7ValidDate(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 16]; // Represents 2023-05-15 10:30:45 UTC+4
        $offset = 0;
        $expectedDate = Carbon::create(2023, 5, 15, 10, 30, 45, '+04:00');

        $date = IsoDate::init7($buffer, $offset);

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertEquals($expectedDate, $date);
        $this->assertSame(7, $offset);
    }

    public function testInit7InvalidDate(): void
    {
        $buffer = [0, 0, 0, 0, 0, 0, 0]; // Invalid date
        $offset = 0;

        $date = IsoDate::init7($buffer, $offset);

        $this->assertNotInstanceOf(Carbon::class, $date);
        $this->assertSame(7, $offset);
    }

    public function testInit17ValidDate(): void
    {
        $buffer = array_map(ord(...), str_split('2023051510304516')); // Represents 2023-05-15 10:30:45.16 UTC
        $offset = 0;
        $expectedDate = Carbon::create(2023, 5, 15, 10, 30, 45, 0);
        $this->assertInstanceOf(Carbon::class, $expectedDate);
        $expectedDate->addMilliseconds(160); // the last two digits are hundredths of a second

        $date = IsoDate::init17($buffer, $offset);

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertEquals($expectedDate, $date);
        $this->assertSame(17, $offset);
    }

    public function testInit17InvalidDate(): void
    {
        $buffer = array_map(ord(...), str_split('0000000000000000')); // Invalid date
        $offset = 0;

        $date = IsoDate::init17($buffer, $offset);

        $this->assertNotInstanceOf(Carbon::class, $date);
        $this->assertSame(17, $offset);
    }

    public function testInit7NegativeUtcOffset(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 0xFC]; // -4 intervals of 15 minutes = UTC-01:00
        $offset = 0;

        $date = IsoDate::init7($buffer, $offset);

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(-3600, $date->getOffset());
    }

    public function testInit7InvalidMonthReturnsNull(): void
    {
        $buffer = [123, 13, 40, 10, 30, 45, 0];
        $offset = 0;

        $this->assertNotInstanceOf(Carbon::class, IsoDate::init7($buffer, $offset));
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

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame(7200, $date->getOffset());
    }
}
