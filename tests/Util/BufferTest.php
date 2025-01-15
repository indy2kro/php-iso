<?php

declare(strict_types=1);

namespace PhpIso\Test;

use PHPUnit\Framework\TestCase;
use PhpIso\Util\Buffer;
use PhpIso\Exception;

class BufferTest extends TestCase
{
    public function testAlign(): void
    {
        $this->assertSame(10, Buffer::align(7, 5));
        $this->assertSame(15, Buffer::align(15, 5));
        $this->assertSame(20, Buffer::align(16, 5));
    }

    public function testGetStringValid(): void
    {
        $buffer = array_map('ord', str_split('HelloWorld'));
        $offset = 0;
        $result = Buffer::getString($buffer, 5, $offset);
        $this->assertSame('Hello', $result);
        $this->assertSame(5, $offset);
    }

    public function testGetStringInvalid(): void
    {
        $this->expectException(Exception::class);
        $buffer = [72, 101, 108]; // 'Hel'
        $offset = 0;
        Buffer::getString($buffer, 5, $offset);
    }

    public function testReadAString(): void
    {
        $buffer = array_map('ord', str_split('HelloWorld'));
        $offset = 0;
        $result = Buffer::readAString($buffer, 5, $offset);
        $this->assertSame('Hello', $result);
        $this->assertSame(5, $offset);
    }

    public function testReadDString(): void
    {
        $buffer = array_map('ord', str_split('HelloWorld'));
        $offset = 0;
        $result = Buffer::readDString($buffer, 5, $offset);
        $this->assertSame('Hello', $result);
        $this->assertSame(5, $offset);
    }

    public function testGetBytesValid(): void
    {
        $buffer = [1, 2, 3, 4, 5];
        $offset = 0;
        $result = Buffer::getBytes($buffer, 3, $offset);
        $this->assertSame('123', $result);
        $this->assertSame(3, $offset);
    }

    public function testGetBytesInvalid(): void
    {
        $this->expectException(Exception::class);
        $buffer = [1, 2];
        $offset = 0;
        Buffer::getBytes($buffer, 3, $offset);
    }

    public function testReadBBOException(): void
    {
        $buffer = [0, 1, 0, 1, 0, 0, 0, 1];

        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readBBO($buffer, 100, $offset);
    }

    public function testReadLSB(): void
    {
        $buffer = [1, 0]; // LSB representation of 1
        $offset = 0;
        $result = Buffer::readLSB($buffer, 2, $offset);
        $this->assertSame(1, $result);
        $this->assertSame(2, $offset);
    }

    public function testReadMSB(): void
    {
        $buffer = [0, 1]; // MSB representation of 1
        $offset = 0;
        $result = Buffer::readMSB($buffer, 2, $offset);
        $this->assertSame(1, $result);
        $this->assertSame(2, $offset);
    }

    public function testReadInt16(): void
    {
        $buffer = [0, 1]; // 16-bit representation of 1
        $offset = 0;
        $result = Buffer::readInt16($buffer, $offset);
        $this->assertSame(1, $result);
        $this->assertSame(2, $offset);
    }

    public function testReadInt32(): void
    {
        $buffer = [0, 0, 0, 1]; // 32-bit representation of 1
        $offset = 0;
        $result = Buffer::readInt32($buffer, $offset);
        $this->assertSame(1, $result);
        $this->assertSame(4, $offset);
    }
}
