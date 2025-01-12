<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;
use PhpIso\Util\Buffer;

class Partition extends Descriptor
{
    /**
     * Unused parameter
     */
    public int $unused;
    /**
     * The "Partition Volume Descriptors"'s System Identifier
     */
    public string $systemID;
    /**
     * The "Partition Volume Descriptors"'s Partition Identifier
     */
    public string $volPartitionID;
    /**
     * The "Partition Volume Descriptors"'s Partition location
     */
    public int $volPartitionLocation;
    /**
     * The "Partition Volume Descriptors"'s Partition size
     */
    public int $volPartitionSize;

    protected int $type = Type::PARTITION_VOLUME_DESC;
    protected string $name = 'Partition volume descriptor';

    public function init(IsoFile $isoFile, int &$offset): void
    {
        if ($this->bytes === null) {
            return;
        }

        $this->unused = $this->bytes[$offset];
        $offset++;

        $this->systemID = Buffer::readAString($this->bytes, 32, $offset);
        $this->volPartitionID = Buffer::readDString($this->bytes, 32, $offset);

        $this->volPartitionLocation = Buffer::readMSB($this->bytes, 8, $offset);
        $this->volPartitionSize = Buffer::readMSB($this->bytes, 8, $offset);

        // free some space...
        unset($this->bytes);
    }
}
