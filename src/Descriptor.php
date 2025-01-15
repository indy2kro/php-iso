<?php

declare(strict_types=1);

namespace PhpIso;

abstract class Descriptor
{
    public string $name;
    protected int $type;

    /**
     * @param array<int, mixed>|null $bytes
     */
    public function __construct(public string $stdId = '', public int $version = 0, protected ?array $bytes = null)
    {
    }

    public function getType(): int
    {
        return $this->type;
    }

    abstract public function init(IsoFile $isoFile, int &$offset): void;
}
