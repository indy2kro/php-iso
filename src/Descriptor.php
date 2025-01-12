<?php

declare(strict_types=1);

namespace PhpIso;

use PhpIso\Descriptor\Type;

abstract class Descriptor
{
    protected int $type = Type::NOT_SET_DESC;
    protected string $name = '';

    /**
     * @param array<int, mixed>|null $bytes
     */
    public function __construct(protected string $stdId = '', protected int $version = 0, protected ?array $bytes = null)
    {
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getId(): string
    {
        return $this->stdId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    abstract public function init(IsoFile $isoFile, int &$offset): void;
}
