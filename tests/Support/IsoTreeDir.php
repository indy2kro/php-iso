<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

/**
 * A directory of an IsoTree, flattened
 */
final class IsoTreeDir
{
    /**
     * @var array<array-key, int> child directory name => index of the child
     */
    public array $children = [];

    /**
     * @param array<array-key, mixed> $entries
     */
    public function __construct(public readonly array $entries, public readonly int $parent)
    {
    }
}
