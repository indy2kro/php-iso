<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

use RuntimeException;

final class Streams
{
    /**
     * An in-memory stream, e.g. to keep the CLI error output out of the test run
     *
     * @return resource
     */
    public static function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open a memory stream');
        }

        return $stream;
    }
}
