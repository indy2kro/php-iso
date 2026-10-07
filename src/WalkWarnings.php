<?php

declare(strict_types=1);

namespace PhpIso;

/**
 * Collects the problems that make a directory listing incomplete (depth limit, unreadable or corrupt directories)
 *
 * Pass an instance as the last argument of walk(), listDirectory(), find(), search() or Extractor::extract():
 * the tree is still listed as far as possible, and what was skipped is recorded here. In strict mode the first
 * problem throws an Exception instead.
 */
final class WalkWarnings
{
    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(public readonly bool $strict = false)
    {
    }

    /**
     * @throws Exception in strict mode
     */
    public function add(string $message): void
    {
        if ($this->strict) {
            throw new Exception('Incomplete listing: ' . $message);
        }

        $this->warnings[] = $message;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    public function isEmpty(): bool
    {
        return $this->warnings === [];
    }

    public function reset(): void
    {
        $this->warnings = [];
    }
}
