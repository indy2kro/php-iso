<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

/**
 * Lays out a whole directory tree as an ISO 9660 image (optionally with a Joliet volume and path tables)
 *
 * The tree is an array: a string value is a file content, an array value is a directory.
 */
final class IsoTree
{
    private const int SECTOR = IsoBuilder::SECTOR;

    /**
     * @param array<array-key, mixed> $tree
     */
    public static function build(array $tree, bool $joliet = false, bool $lPathTable = true, bool $mPathTable = true): IsoBuilder
    {
        $dirs = self::collect($tree);
        $count = count($dirs);
        $zeros = array_fill(0, $count, 0);

        $volumes = [['encode' => static fn (string $name): string => $name, 'type' => 1, 'level' => 0]];
        if ($joliet) {
            $volumes[] = ['encode' => static fn (string $name): string => mb_convert_encoding($name, 'UTF-16BE', 'UTF-8'), 'type' => 2, 'level' => 3];
        }

        // pass 1: sizes only (a record does not change size with the location values)
        $next = 20;
        /** @var array<int, array{sizes: array<int, int>, dirSector: array<int, int>, tableSize: int, l: int, m: int}> $layout */
        $layout = [];
        foreach ($volumes as $index => $volume) {
            $sizes = [];
            foreach (array_keys($dirs) as $dirIndex) {
                $sizes[$dirIndex] = strlen(self::directoryBytes($dirs, $dirIndex, $volume['encode'], $zeros, [], $zeros));
            }

            $dirSector = [];
            foreach ($sizes as $dirIndex => $size) {
                $dirSector[$dirIndex] = $next;
                $next += intdiv($size, self::SECTOR);
            }

            $tableSize = strlen(self::pathTable($dirs, $volume['encode'], $zeros, true));
            $tableSectors = (int) ceil($tableSize / self::SECTOR);
            $lSector = $next;
            $mSector = $next + $tableSectors;
            $next += 2 * $tableSectors;

            $layout[$index] = ['sizes' => $sizes, 'dirSector' => $dirSector, 'tableSize' => $tableSize, 'l' => $lSector, 'm' => $mSector];
        }

        // file data comes last, shared by all volumes
        /** @var array<int, array<array-key, int>> $fileSector */
        $fileSector = [];
        $data = [];
        foreach ($dirs as $dirIndex => $dir) {
            foreach ($dir->entries as $name => $content) {
                if (is_string($content)) {
                    $fileSector[$dirIndex][$name] = $content === '' ? 0 : $next;
                    $data[$dirIndex][$name] = $content;
                    $next += (int) ceil(strlen($content) / self::SECTOR);
                }
            }
        }

        $builder = new IsoBuilder();
        foreach ($volumes as $index => $volume) {
            $info = $layout[$index];
            foreach (array_keys($dirs) as $dirIndex) {
                $bytes = self::directoryBytes($dirs, $dirIndex, $volume['encode'], $info['dirSector'], $fileSector, $info['sizes']);
                foreach (str_split($bytes, self::SECTOR) as $i => $chunk) {
                    $builder->setSector($info['dirSector'][$dirIndex] + $i, $chunk);
                }
            }

            foreach (['l' => true, 'm' => false] as $key => $little) {
                $table = self::pathTable($dirs, $volume['encode'], $info['dirSector'], $little);
                foreach (str_split($table, self::SECTOR) as $i => $chunk) {
                    $builder->setSector($info[$key] + $i, $chunk);
                }
            }

            $builder->addVolumeDescriptor(
                $index,
                $volume['type'],
                $info['dirSector'][0],
                $info['sizes'][0],
                $info['tableSize'],
                $lPathTable ? $info['l'] : 0,
                self::SECTOR,
                $volume['level'],
                $mPathTable ? $info['m'] : 0,
            );
        }

        $builder->addTerminator(count($volumes));

        foreach ($data as $dirIndex => $files) {
            foreach ($files as $name => $content) {
                if ($content === '') {
                    continue;
                }

                foreach (str_split($content, self::SECTOR) as $i => $chunk) {
                    $builder->setSector($fileSector[$dirIndex][$name] + $i, $chunk);
                }
            }
        }

        return $builder;
    }

    /**
     * The paths a walk of the tree must return
     *
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, int|null> path => size (null for directories)
     */
    public static function expectedPaths(array $tree, string $base = ''): array
    {
        $paths = [];
        foreach ($tree as $name => $content) {
            $path = $base . '/' . $name;
            if (is_array($content)) {
                $paths[$path] = null;
                $paths += self::expectedPaths($content, $path);
            } elseif (is_string($content)) {
                $paths[$path] = strlen($content);
            }
        }

        return $paths;
    }

    /**
     * Flatten the tree breadth first, like the path table requires
     *
     * @param array<array-key, mixed> $tree
     *
     * @return list<IsoTreeDir>
     */
    private static function collect(array $tree): array
    {
        $dirs = [new IsoTreeDir($tree, 0)];

        for ($index = 0; $index < count($dirs); $index++) {
            foreach ($dirs[$index]->entries as $name => $content) {
                if (is_array($content)) {
                    $dirs[] = new IsoTreeDir($content, $index);
                    $dirs[$index]->children[$name] = count($dirs) - 1;
                }
            }
        }

        return $dirs;
    }

    /**
     * @param list<IsoTreeDir> $dirs
     * @param callable(string): string $encode
     * @param array<int, int> $dirSector
     * @param array<int, array<array-key, int>> $fileSector
     * @param array<int, int> $sizes
     */
    private static function directoryBytes(array $dirs, int $dirIndex, callable $encode, array $dirSector, array $fileSector, array $sizes): string
    {
        $dir = $dirs[$dirIndex];
        $records = [
            IsoBuilder::record("\0", $dirSector[$dirIndex], $sizes[$dirIndex], 2),
            IsoBuilder::record("\1", $dirSector[$dir->parent], $sizes[$dir->parent], 2),
        ];

        foreach ($dir->entries as $name => $content) {
            if (is_array($content)) {
                $child = $dir->children[(string) $name];
                $records[] = IsoBuilder::record($encode((string) $name), $dirSector[$child], $sizes[$child], 2);
            } elseif (is_string($content)) {
                $records[] = IsoBuilder::record($encode((string) $name), $fileSector[$dirIndex][$name] ?? 0, strlen($content), 0);
            }
        }

        // records never cross a sector boundary
        $bytes = '';
        $current = '';
        foreach ($records as $record) {
            if (strlen($current) + strlen($record) > self::SECTOR) {
                $bytes .= str_pad($current, self::SECTOR, "\0");
                $current = '';
            }

            $current .= $record;
        }

        return $bytes . str_pad($current, self::SECTOR, "\0");
    }

    /**
     * @param list<IsoTreeDir> $dirs
     * @param callable(string): string $encode
     * @param array<int, int> $dirSector
     */
    private static function pathTable(array $dirs, callable $encode, array $dirSector, bool $little): string
    {
        $names = [0 => "\0"];
        foreach ($dirs as $dir) {
            foreach ($dir->children as $name => $child) {
                $names[$child] = $encode((string) $name);
            }
        }

        $table = '';
        foreach ($dirs as $index => $dir) {
            $id = $names[$index];
            $table .= chr(strlen($id)) . "\0"
                . ($little ? pack('V', $dirSector[$index]) : pack('N', $dirSector[$index]))
                . ($little ? pack('v', $dir->parent + 1) : pack('n', $dir->parent + 1))
                . $id . (strlen($id) % 2 === 1 ? "\0" : '');
        }

        return $table;
    }
}
