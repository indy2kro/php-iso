<?php

declare(strict_types=1);

namespace PhpIso\Test\Util;

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

    public function testReadLSBNormalOperation(): void
    {
        // Test normal operation with a small number
        $buffer = [1, 0];
        $offset = 0;
        $this->assertSame(1, Buffer::readLSB($buffer, 2, $offset));
        $this->assertSame(2, $offset);

        // Test with a larger number
        $buffer = [0, 1];
        $offset = 0;
        $this->assertSame(256, Buffer::readLSB($buffer, 2, $offset));
        $this->assertSame(2, $offset);

        // Test with maximum 16-bit unsigned integer
        $buffer = [255, 255];
        $offset = 0;
        $this->assertSame(65535, Buffer::readLSB($buffer, 2, $offset));
        $this->assertSame(2, $offset);
    }

    public function testReadLSBBufferTooShortFirstByteMissing(): void
    {
        // Test exception when the first byte is missing
        $buffer = [];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readLSB($buffer, 2, $offset);
    }

    public function testReadLSBBufferTooShortSecondByteMissing(): void
    {
        // Test exception when the second byte is missing
        $buffer = [0];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readLSB($buffer, 2, $offset);
    }

    public function testReadMSBNormalOperation(): void
    {
        // Test normal operation with a small number
        $buffer = [0, 1];
        $offset = 0;
        $this->assertSame(1, Buffer::readMSB($buffer, 2, $offset));
        $this->assertSame(2, $offset);

        // Test with a larger number
        $buffer = [1, 0];
        $offset = 0;
        $this->assertSame(256, Buffer::readMSB($buffer, 2, $offset));
        $this->assertSame(2, $offset);

        // Test with maximum 16-bit unsigned integer
        $buffer = [255, 255];
        $offset = 0;
        $this->assertSame(65535, Buffer::readMSB($buffer, 2, $offset));
        $this->assertSame(2, $offset);
    }

    public function testReadMSBBufferTooShortFirstByteMissing(): void
    {
        // Test exception when the first byte is missing
        $buffer = [];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readMSB($buffer, 2, $offset);
    }

    public function testReadMSBBufferTooShortSecondByteMissing(): void
    {
        // Test exception when the second byte is missing
        $buffer = [0];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readMSB($buffer, 2, $offset);
    }

    public function testReadInt16NormalOperation(): void
    {
        // Test normal operation with a small number
        $buffer = [0, 1];
        $offset = 0;
        $this->assertSame(1, Buffer::readInt16($buffer, $offset));
        $this->assertSame(2, $offset);

        // Test with a larger number
        $buffer = [1, 0];
        $offset = 0;
        $this->assertSame(256, Buffer::readInt16($buffer, $offset));
        $this->assertSame(2, $offset);

        // Test with maximum 16-bit unsigned integer
        $buffer = [255, 255];
        $offset = 0;
        $this->assertSame(65535, Buffer::readInt16($buffer, $offset));
        $this->assertSame(2, $offset);
    }

    public function testReadInt16BufferTooShortFirstByteMissing(): void
    {
        // Test exception when the first byte is missing
        $buffer = [];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readInt16($buffer, $offset);
    }

    public function testReadInt16BufferTooShortSecondByteMissing(): void
    {
        // Test exception when the second byte is missing
        $buffer = [0];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readInt16($buffer, $offset);
    }

    public function testReadInt32NormalOperation(): void
    {
        // Test normal operation with a small number
        $buffer = [0, 0, 0, 1];
        $offset = 0;
        $this->assertSame(1, Buffer::readInt32($buffer, $offset));
        $this->assertSame(4, $offset);

        // Test with a larger number
        $buffer = [0, 0, 1, 0];
        $offset = 0;
        $this->assertSame(256, Buffer::readInt32($buffer, $offset));
        $this->assertSame(4, $offset);

        // Test with maximum 32-bit unsigned integer
        $buffer = [255, 255, 255, 255];
        $offset = 0;
        $this->assertSame(4294967295, Buffer::readInt32($buffer, $offset));
        $this->assertSame(4, $offset);
    }

    public function testReadInt32BufferTooShort(): void
    {
        // Test exception when buffer is too short
        $buffer = [0, 0, 0];
        $offset = 0;
        $this->expectException(Exception::class);
        Buffer::readInt32($buffer, $offset);
    }
}
