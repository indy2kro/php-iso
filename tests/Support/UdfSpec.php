<?php

declare(strict_types=1);

namespace PhpIso\Test\Support;

/**
 * A non directory node of a UdfBuilder tree with an explicit file type, owner and permissions
 */
final readonly class UdfSpec
{
    public function __construct(
        public string $data = '',
        public int $type = 5,
        public int $uid = 0,
        public int $gid = 0,
        public int $permissions = 0,
    ) {
    }

    /**
     * A symbolic link made of path component records (ECMA-167 4/14.16)
     *
     * @param list<array{int, string}> $components type and identifier of every component
     */
    public static function symlink(array $components): self
    {
        $data = '';
        foreach ($components as [$type, $name]) {
            $identifier = $name === '' ? '' : chr(8) . $name;
            $data .= chr($type) . chr(strlen($identifier)) . pack('v', 1) . $identifier;
        }

        return new self($data, 12);
    }
}
