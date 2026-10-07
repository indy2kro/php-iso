<?php

declare(strict_types=1);

namespace PhpIso\Util;

use PhpIso\Exception;

/**
 * Builds destination paths from names stored inside an ISO, which are untrusted input
 */
class SafePath
{
    /**
     * Validate a single file / directory name coming from an ISO
     *
     * @throws Exception
     */
    public static function assertSafeName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new Exception('Unsafe name in ISO: "' . $name . '"');
        }

        // control characters, both path separators and the drive / stream separator
        if (preg_match('/[\x00-\x1f\/\\\\:]/', $name) === 1) {
            throw new Exception('Unsafe characters in ISO name: ' . addcslashes($name, "\0..\37"));
        }

        // rejected on every OS: an image is portable and must extract the same way everywhere
        if (preg_match('/[<>"|?*]/', $name) === 1) {
            throw new Exception('Unsafe characters in ISO name: ' . $name);
        }

        if (str_ends_with($name, '.') || str_ends_with($name, ' ')) {
            throw new Exception('Unsafe trailing dot or space in ISO name: "' . $name . '"');
        }

        if (preg_match('/^(?:con|prn|aux|nul|(?:com|lpt)[1-9\x{00B9}\x{00B2}\x{00B3}])(?:\..*)?$/iu', $name) === 1) {
            throw new Exception('Reserved device name in ISO: "' . $name . '"');
        }
    }

    /**
     * Join a base directory and a relative path ("/dir/sub/file") made of untrusted names
     *
     * @throws Exception when any segment could escape the base directory
     */
    public static function join(string $baseDir, string $relativePath): string
    {
        $base = rtrim($baseDir, '/\\');
        $segments = array_filter(explode('/', $relativePath), static fn (string $s): bool => $s !== '');

        foreach ($segments as $segment) {
            self::assertSafeName($segment);
        }

        return $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }
}
