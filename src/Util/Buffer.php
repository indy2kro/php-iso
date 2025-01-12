<?php

declare(strict_types=1);

namespace PhpIso\Util;

use PhpIso\Exception;

class Buffer
{
    /**
     * Align a number
     */
    public static function align(int $num, int $align): int
    {
        $tmp = (int) ($num / $align);
        if ((int) ($num % $align) > 0) {
            $tmp++;
        }

        return $tmp * $align;
    }

    /**
     * Read a string from the buffer
     *
     * @param array<int, mixed> $buffer
     */
    public static function getString(array &$buffer, int $length, int &$offset = 0, bool $supplementary = false): string
    {
        $string = '';
        for ($i = $offset; $i < $offset + $length; $i++) {
            if (!isset($buffer[$i])) {
                throw new Exception('Failed to read buffer entry ' . $i);
            }
            $string .= chr($buffer[$i]);
        }

        if ($supplementary) {
            $string = mb_convert_encoding($string, 'UTF-8', 'UTF-16');
        }

        $offset += $length;
        return $string;
    }

    /**
     * Read an a-string from the buffer
     *
     * @param array<int, mixed> $buffer
     */
    public static function readAString(array &$buffer, int $length, int &$offset = 0, bool $supplementary = false): string
    {
        return self::getString($buffer, $length, $offset);
    }

    /**
     * Read a d-string from the buffer
     *
     * @param array<int, mixed> $buffer
     */
    public static function readDString(array &$buffer, int $length, int &$offset = 0, bool $supplementary = false): string
    {
        return self::getString($buffer, $length, $offset, $supplementary);
    }

    /**
     * Read datas from the buffer
     *
     * @param array<int, mixed> $buffer
     */
    public static function getBytes(array &$buffer, int $length, int &$offset = 0): string
    {
        $datas = '';
        for ($i = $offset; $i < $offset + $length; $i++) {
            if (!isset($buffer[$i])) {
                throw new Exception('Failed to read buffer entry ' . $i);
            }
            $datas .= $buffer[$i];
        }

        $offset += $length;
        return $datas;
    }

    /**
     * Read a number written in BBO (Bost Byte Order) (ex: a 4 BYTES number require 8 BYTES, 4 for LSM mode and 4 for MSB)
     *
     * @param array<int, mixed> $buffer
     *
     * @return int The BBO number OR -1 on error
     */
    public static function readBBO(array &$buffer, int $length, int &$offset = 0): int
    {
        $n1 = 0;
        $n2 = 0;
        $len = $length / 2;

        for ($i = 0; $i < $len; $i++) {
            if (!isset($buffer[$offset + ($len - 1 - $i)])) {
                throw new Exception('Failed to read buffer entry ' . ($offset + ($len - 1 - $i)));
            }
            if (!isset($buffer[$offset + $len + $i])) {
                throw new Exception('Failed to read buffer entry ' . ($offset + $len + $i));
            }
            $n1 += $buffer[$offset + ($len - 1 - $i)];
            $n2 += $buffer[$offset + $len + $i];

            if ($i + 1 < $len) {
                $n1 <<= 8;
                $n2 <<= 8;
            }
        }

        if ($n1 !== $n2) {
            return -1;
        }

        $offset += $length;
        return $n1;
    }

    /**
     * Read a number written in LSB mode ("Less Signifient Bit" first)
     *
     * @param array<int, mixed> $buffer
     */
    public static function readLSB(array &$buffer, int $length, int &$offset = 0): int
    {
        $lsb = 0;
        for ($i = 0; $i < $length; $i++) {
            if (!isset($buffer[$offset + ($length - 1 - $i)])) {
                throw new Exception('Failed to read buffer entry ' . ($offset + ($length - 1 - $i)));
            }

            $lsb += $buffer[$offset + $length - 1 - $i];

            if ($i + 1 < $length) {
                $lsb <<= 8;
            }
        }

        $offset += $length;
        return $lsb;
    }

    /**
     * Read a number written in MSB mode ("Most Signifient Bit" first)
     *
     * @param array<int, mixed> $buffer
     */
    public static function readMSB(array &$buffer, int $length, int &$offset = 0): int
    {
        $msb = 0;
        for ($i = 0; $i < $length; $i++) {
            if (!isset($buffer[$offset + $i])) {
                throw new Exception('Failed to read buffer entry ' . ($offset + $i));
            }
            $msb += $buffer[$offset + $i];

            if ($i + 1 < $length) {
                $msb <<= 8;
            }
        }

        $offset += $length;
        return $msb;
    }

    /**
     * Read a word (16 bits number)
     *
     * @param array<int, mixed> $buffer
     */
    public static function readInt16(array &$buffer, int &$offset = 0): int
    {
        $output = 0;

        if (!isset($buffer[$offset + 0])) {
            throw new Exception('Failed to read buffer entry ' . ($offset + 0));
        }

        if (!isset($buffer[$offset + 1])) {
            throw new Exception('Failed to read buffer entry ' . ($offset + 1));
        }

        $output += $buffer[$offset + 0] << 8;
        $output += $buffer[$offset + 1];

        $offset += 2;
        return $output;
    }

    /**
     * Read a DWORD (32 bits number)
     *
     * @param array<int, mixed> $buffer
     */
    public static function readInt32(array &$buffer, int &$offset = 0): int
    {
        $output = 0;

        if (!isset($buffer[$offset + 0])) {
            throw new Exception('Failed to read buffer entry ' . ($offset + 0));
        }

        if (!isset($buffer[$offset + 1])) {
            throw new Exception('Failed to read buffer entry ' . ($offset + 1));
        }

        if (!isset($buffer[$offset + 2])) {
            throw new Exception('Failed to read buffer entry ' . ($offset + 2));
        }

        if (!isset($buffer[$offset + 3])) {
            throw new Exception('Failed to read buffer entry ' . ($offset + 3));
        }

        $output += $buffer[$offset + 0] << 24;
        $output += $buffer[$offset + 1] << 16;
        $output += $buffer[$offset + 2] << 8;
        $output += $buffer[$offset + 3];

        $offset += 4;
        return $output;
    }
}
