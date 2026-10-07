<?php

declare(strict_types=1);

/*
 * Print "sha256  relative/path" for every file below a directory, sorted, with "/" separators,
 * so extractions done on different operating systems can be compared byte for byte.
 */

$root = $argv[1] ?? null;
if ($root === null || ! is_dir($root)) {
    fwrite(STDERR, 'Usage: php hashes.php <directory>' . PHP_EOL);
    exit(1);
}

$root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
$lines = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || ! $file->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $lines[] = hash_file('sha256', $path) . '  ' . substr($path, strlen($root) + 1);
}

usort($lines, static fn (string $a, string $b): int => strcmp(substr($a, 66), substr($b, 66)));

echo implode("\n", $lines), "\n";
