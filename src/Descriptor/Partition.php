<?php

declare(strict_types=1);

namespace PhpIso\Descriptor;

use PhpIso\Descriptor;
use PhpIso\IsoFile;
use PhpIso\Util\Buffer;

class Partition extends Descriptor
{
    /**
     * The "Partition Volume Descriptors"'s System Identifier
     */
    public readonly string $systemID;

    /**
     * The "Partition Volume Descriptors"'s Partition Identifier
     */
    public readonly string $volPartitionID;

    /**
     * The "Partition Volume Descriptors"'s Partition location
     */
    public readonly int $volPartitionLocation;

    /**
     * The "Partition Volume Descriptors"'s Partition size
     */
    public readonly int $volPartitionSize;

    protected const string NAME = 'Partition volume descriptor';

    protected const int TYPE = Type::PARTITION_VOLUME_DESC;

    /**
     * @param array<int, int> $bytes the descriptor sector
     * @param int $offset position after the descriptor header, moved after the parsed fields
     */
    public function __construct(string $stdId, int $version, array $bytes, int &$offset)
    {
        parent::__construct($stdId, $version);

        Buffer::getRawBytes($bytes, 1, $offset);

        $this->systemID = Buffer::readAString($bytes, 32, $offset);
        $this->volPartitionID = Buffer::readDString($bytes, 32, $offset);

        // both byte order 32 bit numbers (ECMA-119 7.3.3)
        $this->volPartitionLocation = Buffer::readBBO($bytes, 8, $offset);
        $this->volPartitionSize = Buffer::readBBO($bytes, 8, $offset);
    }
}
