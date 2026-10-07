<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Exception;
use PhpIso\IsoFile;

/**
 * El Torito boot catalog (validation entry, initial/default entry and section entries)
 */
final readonly class BootCatalog
{
    private const int ENTRY_SIZE = 32;
    private const int SECTOR_SIZE = 2048;
    private const int MAX_CATALOG_SECTORS = 16;

    /**
     * @param array<int, BootEntry> $entries initial/default entry first, then section entries
     */
    public function __construct(
        public int $platformId,
        public string $manufacturer,
        public bool $validChecksum,
        public array $entries,
    ) {
    }

    /**
     * Read the boot catalog stored at the given sector
     *
     * @throws Exception when the catalog is missing or does not start with a valid validation entry
     */
    public static function load(IsoFile $isoFile, int $sector): self
    {
        if ($sector <= 0 || $isoFile->seek($sector * self::SECTOR_SIZE, SEEK_SET) === -1) {
            throw new Exception('Invalid boot catalog location: ' . $sector);
        }

        $data = $isoFile->read(self::SECTOR_SIZE);

        if ($data === false || strlen($data) < self::ENTRY_SIZE * 2) {
            throw new Exception('Failed to read the boot catalog');
        }

        $validation = substr($data, 0, self::ENTRY_SIZE);

        /** @var array{header: int, platform: int, key1: int, key2: int}|false $header */
        $header = unpack('Cheader/Cplatform/x2/x24/x2/Ckey1/Ckey2', $validation);

        if ($header === false || $header['header'] !== 1 || $header['key1'] !== 0x55 || $header['key2'] !== 0xAA) {
            throw new Exception('Invalid boot catalog validation entry');
        }

        $words = unpack('v16', $validation);
        $validChecksum = $words !== false && (array_sum($words) & 0xFFFF) === 0;
        $platform = $header['platform'];
        $manufacturer = trim(substr($validation, 4, 24), "\0 ");

        $entries = [];
        $sectors = 1;

        // a catalog can span several sectors: read the following one when the current data is exhausted
        $need = static function (int $offset) use ($isoFile, $sector, &$data, &$sectors): bool {
            while ($offset + self::ENTRY_SIZE > strlen($data)) {
                if ($sectors >= self::MAX_CATALOG_SECTORS || $isoFile->seek(($sector + $sectors) * self::SECTOR_SIZE, SEEK_SET) === -1) {
                    return false;
                }

                $next = $isoFile->read(self::SECTOR_SIZE);
                if ($next === false || strlen($next) !== self::SECTOR_SIZE) {
                    return false;
                }

                $data .= $next;
                $sectors++;
            }

            return true;
        };

        // the entry right after the validation entry is the initial/default entry
        $offset = self::ENTRY_SIZE;
        $entries[] = self::parseEntry(substr($data, $offset, self::ENTRY_SIZE), $platform);
        $offset += self::ENTRY_SIZE;

        // section headers: 0x90 (more sections follow) or 0x91 (last section)
        while ($need($offset)) {
            $indicator = ord($data[$offset]);

            if ($indicator !== 0x90 && $indicator !== 0x91) {
                break;
            }

            $sectionPlatform = ord($data[$offset + 1]);
            $unpacked = unpack('v', substr($data, $offset + 2, 2));
            $count = $unpacked === false ? 0 : $unpacked[1];
            $offset += self::ENTRY_SIZE;

            for ($i = 0; $i < $count && $need($offset); $i++) {
                $entries[] = self::parseEntry(substr($data, $offset, self::ENTRY_SIZE), $sectionPlatform);
                $offset += self::ENTRY_SIZE;

                // skip extension entries (0x44) attached to the entry
                while ($need($offset) && ord($data[$offset]) === 0x44) {
                    $offset += self::ENTRY_SIZE;
                }
            }

            if ($indicator === 0x91) {
                break;
            }
        }

        return new self($platform, $manufacturer, $validChecksum, $entries);
    }

    /**
     * The initial/default entry
     */
    public function getDefaultEntry(): ?BootEntry
    {
        return $this->entries[0] ?? null;
    }

    /**
     * Write the boot image of an entry to a file path or an open stream
     *
     * The size of a hard disk image is read from the MBR partition table when it is valid.
     *
     * @param resource|string $destination
     *
     * @throws Exception when the image is outside of the ISO or cannot be written
     */
    public function extractImage(IsoFile $isoFile, BootEntry $entry, mixed $destination): void
    {
        $offset = $entry->loadRba * self::SECTOR_SIZE;
        $length = $entry->getImageSize();

        if ($entry->mediaType === BootEntry::MEDIA_HARD_DISK) {
            $length = $this->hardDiskSize($isoFile, $offset) ?? $length;
        }

        if (is_string($destination)) {
            $handle = fopen($destination, 'wb');
            if ($handle === false) {
                throw new Exception('Failed to open file for writing: ' . $destination);
            }

            try {
                $isoFile->copyRange($offset, $length, $handle);
            } finally {
                fclose($handle);
            }

            return;
        }

        $isoFile->copyRange($offset, $length, $destination);
    }

    /**
     * Size of a hard disk image from its MBR partition table, null when it cannot be determined
     */
    private function hardDiskSize(IsoFile $isoFile, int $offset): ?int
    {
        if ($offset + 512 > $isoFile->getSize() || $isoFile->seek($offset, SEEK_SET) === -1) {
            return null;
        }

        $mbr = $isoFile->read(512);
        if ($mbr === false || strlen($mbr) !== 512 || substr($mbr, 510, 2) !== "\x55\xAA") {
            return null;
        }

        $end = 0;
        for ($i = 0; $i < 4; $i++) {
            /** @var array{type: int, start: int, sectors: int}|false $partition */
            $partition = unpack('Ctype/x3/Vstart/Vsectors', substr($mbr, 446 + $i * 16 + 4, 12));
            if ($partition !== false && $partition['type'] !== 0) {
                $end = max($end, $partition['start'] + $partition['sectors']);
            }
        }

        $size = $end * 512;

        return ($size > 0 && $offset + $size <= $isoFile->getSize()) ? $size : null;
    }

    private static function parseEntry(string $raw, int $platformId): BootEntry
    {
        /** @var array{indicator: int, media: int, segment: int, system: int, count: int, rba: int} $entry */
        $entry = unpack('Cindicator/Cmedia/vsegment/Csystem/x/vcount/Vrba', $raw);

        return new BootEntry(
            $entry['indicator'] === 0x88,
            $entry['media'] & 0x0F,
            $entry['segment'],
            $entry['system'],
            $entry['count'],
            $entry['rba'],
            $platformId,
        );
    }
}
