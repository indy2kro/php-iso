<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\Util\IsoDate;
use Carbon\Carbon;

class IsoDateTest extends TestCase
{
    public function testInit7ValidDate(): void
    {
        $buffer = [123, 5, 15, 10, 30, 45, 16]; // Represents 2023-05-15 10:30:45 UTC+4
        $offset = 0;
        $expectedDate = Carbon::create(2023, 5, 15, 10, 30, 45, 4);

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

        $this->assertNull($date);
        $this->assertSame(7, $offset);
    }

    public function testInit17ValidDate(): void
    {
        $buffer = array_map('ord', str_split('2023051510304516')); // Represents 2023-05-15 10:30:45.16 UTC
        $offset = 0;
        $expectedDate = Carbon::create(2023, 5, 15, 10, 30, 45, 0);
        $this->assertNotNull($expectedDate);
        $expectedDate->addMilliseconds(16);

        $date = IsoDate::init17($buffer, $offset);

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertEquals($expectedDate, $date);
        $this->assertSame(17, $offset);
    }

    public function testInit17InvalidDate(): void
    {
        $buffer = array_map('ord', str_split('0000000000000000')); // Invalid date
        $offset = 0;

        $date = IsoDate::init17($buffer, $offset);

        $this->assertNull($date);
        $this->assertSame(17, $offset);
    }
}
