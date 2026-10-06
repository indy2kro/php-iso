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

    /**
     * A boot record volume descriptor pointing to a boot catalog
     */
    public function addBootRecord(int $index, int $catalogSector, string $systemId = 'EL TORITO SPECIFICATION'): self
    {
        $data = chr(0) . 'CD001' . chr(1) . str_pad($systemId, 32, "\0") . str_repeat("\0", 32) . pack('V', $catalogSector);

        return $this->setSector(16 + $index, $data);
    }

    /**
     * A partition volume descriptor
     */
    public function addPartition(int $index, string $systemId, string $partitionId, int $location, int $size): self
    {
        $data = chr(3) . 'CD001' . chr(1) . "\0" . str_pad($systemId, 32) . str_pad($partitionId, 32) . pack('J', $location) . pack('J', $size);

        return $this->setSector(16 + $index, $data);
    }

    /**
     * An El Torito boot catalog: validation entry, default entry and optional section entries
     *
     * @param array<int, string> $sections raw 32 bytes entries following the default entry
     */
    public static function bootCatalog(int $platform = 0, int $media = 2, int $loadRba = 25, array $sections = [], bool $validChecksum = true): string
    {
        $validation = chr(1) . chr($platform) . "\0\0" . str_pad('TEST', 24, "\0") . "\0\0" . "\x55\xAA";
        if ($validChecksum) {
            $words = unpack('v16', $validation);
            $sum = is_array($words) ? array_sum($words) : 0;
            $validation = substr($validation, 0, 28) . pack('v', (0x10000 - ($sum & 0xFFFF)) & 0xFFFF) . "\x55\xAA";
        }

        $default = chr(0x88) . chr($media) . pack('v', 0) . chr(6) . "\0" . pack('v', 1) . pack('V', $loadRba) . str_repeat("\0", 20);

        return $validation . $default . implode('', $sections);
    }

    /**
     * A section header (0x90 more follow, 0x91 last) followed by its boot entries
     */
    public static function bootSection(int $platform, int $entries, bool $last = true): string
    {
        $header = chr($last ? 0x91 : 0x90) . chr($platform) . pack('v', $entries) . str_repeat("\0", 28);
        $entry = chr(0x88) . chr(0) . pack('v', 0) . chr(0xEF) . "\0" . pack('v', 4) . pack('V', 40) . str_repeat("\0", 20);

        return $header . str_repeat($entry, $entries);
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
