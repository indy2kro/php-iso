<?php

declare(strict_types=1);

namespace PhpIso;

abstract class Descriptor
{
    protected const string NAME = '';
    protected const int TYPE = -1;

    public readonly string $name;

    public function __construct(public readonly string $stdId = '', public readonly int $version = 0)
    {
        $this->name = static::NAME;
    }

    public function getType(): int
    {
        return static::TYPE;
    }
}
