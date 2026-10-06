<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

/**
 * Builds tiny (and deliberately broken) ISO 9660 images in memory for tests
 */
final class IsoBuilder
{
    public const int SECTOR = 2048;

    /**
     * @var array<int, string>
     */
    private array $sectors = [];

    public function setSector(int $number, string $data): self
    {
        $this->sectors[$number] = str_pad($data, self::SECTOR, "\0");

        return $this;
    }

    /**
     * Build a volume descriptor at sector 16 + $index
     */
    public function addVolumeDescriptor(int $index, int $type = 1, int $rootLocation = 18, int $rootSize = 2048, int $pathTableSize = 0, int $pathTableLocation = 0, int $blockSize = self::SECTOR, int $jolietLevel = 0, ?int $mPathTableLocation = null): self
    {
        $mPathTableLocation ??= $pathTableLocation;
        $blank = $jolietLevel > 0 ? "\0" : ' ';
        $data = chr($type) . 'CD001' . chr(1) . "\0";
        if ($jolietLevel > 0) {
            $data .= str_pad(mb_convert_encoding('SYSTEM', 'UTF-16BE', 'UTF-8'), 32, "\0") . str_pad(mb_convert_encoding('VOLUME', 'UTF-16BE', 'UTF-8'), 32, "\0");
        } else {
            $data .= str_pad('SYSTEM', 32) . str_pad('VOLUME', 32);
        }
        $data .= str_repeat("\0", 8);
        $data .= self::bbo32(100);
        $data .= $jolietLevel > 0 ? str_pad('%/' . [1 => '@', 2 => 'C', 3 => 'E'][$jolietLevel], 32, "\0") : str_repeat("\0", 32);
        $data .= self::bbo16(1) . self::bbo16(1) . self::bbo16($blockSize);
        $data .= self::bbo32($pathTableSize);
        $data .= pack('V', $pathTableLocation) . pack('V', 0);
        $data .= pack('N', $mPathTableLocation) . pack('N', 0);
        $data .= self::record("\0", $rootLocation, $rootSize, 2);
        $data .= str_repeat($blank, 128 * 4);
        $data .= str_repeat($blank, 37 * 3);
        $data .= str_repeat('0', 16) . "\0";
        $data .= str_repeat('0', 16) . "\0";
        $data .= str_repeat('0', 16) . "\0";
        $data .= str_repeat('0', 16) . "\0";
        $data .= chr(1);

        return $this->setSector(16 + $index, $data);
    }

    public function addTerminator(int $index): self
    {
        return $this->setSector(16 + $index, chr(255) . 'CD001' . chr(1));
    }

    /**
     * Put a list of directory records (see record()) in a sector
     *
     * @param array<int, string> $records
     */
    public function setDirectory(int $sector, array $records): self
    {
        return $this->setSector($sector, implode('', $records));
    }

    public function build(): string
    {
        if ($this->sectors === []) {
            return '';
        }

        $last = max(array_keys($this->sectors));
        $image = '';
        for ($i = 0; $i <= $last; $i++) {
            $image .= $this->sectors[$i] ?? str_repeat("\0", self::SECTOR);
        }

        return $image;
    }

    /**
     * Write the image in a temporary file and return its path
     */
    public function save(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iso');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file');
        }
        file_put_contents($path, $this->build());

        return $path;
    }

    /**
     * A directory record. The identifier is written as is (bytes), the first one of "\0" / "\1" are "." / ".."
     */
    public static function record(string $id, int $location, int $size, int $flags = 0): string
    {
        $length = 33 + strlen($id);
        $padding = $length % 2 === 1 ? "\0" : '';
        $length += strlen($padding);

        return chr($length) . chr(0) . self::bbo32($location) . self::bbo32($size)
            . chr(125) . chr(1) . chr(1) . chr(0) . chr(0) . chr(0) . chr(0)
            . chr($flags) . chr(0) . chr(0) . self::bbo16(1)
            . chr(strlen($id)) . $id . $padding;
    }

    public static function bbo32(int $value): string
    {
        return pack('V', $value) . pack('N', $value);
    }

    public static function bbo16(int $value): string
    {
        return pack('v', $value) . pack('n', $value);
    }
}
