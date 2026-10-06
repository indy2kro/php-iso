<?php

declare(strict_types=1);

namespace PhpIso;

use Carbon\Carbon;

/**
 * A file or directory found while walking the directory tree of a volume
 */
final readonly class IsoEntry
{
    public function __construct(
        public string $path,
        public string $name,
        public bool $isDirectory,
        public int $size,
        public int $location,
        public ?Carbon $recordingDate,
        public bool $isHidden,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'type' => $this->isDirectory ? 'directory' : 'file',
            'size' => $this->size,
            'location' => $this->location,
            'date' => $this->recordingDate?->toIso8601String(),
            'hidden' => $this->isHidden,
        ];
    }
}
