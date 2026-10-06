<?php

declare(strict_types=1);

namespace PhpIso\Test\Util;

use PhpIso\Exception;
use PhpIso\Util\SafePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafePathTest extends TestCase
{
    public function testJoinBuildsPathInsideBase(): void
    {
        $expected = 'base' . DIRECTORY_SEPARATOR . 'dir' . DIRECTORY_SEPARATOR . 'file.txt';

        $this->assertSame($expected, SafePath::join('base/', '/dir/file.txt'));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function unsafePaths(): \Iterator
    {
        yield 'parent segment' => ['/../evil.txt'];
        yield 'nested parent segment' => ['/dir/../../evil.txt'];
        yield 'backslash separator' => ['/dir\\..\\evil.txt'];
        yield 'drive letter' => ['/C:/evil.txt'];
        yield 'null byte' => ["/evil\0.txt"];
        yield 'dot segment' => ['/dir/./file'];
    }

    #[DataProvider('unsafePaths')]
    public function testJoinRejectsUnsafePaths(string $path): void
    {
        $this->expectException(Exception::class);

        SafePath::join('base', $path);
    }

    public function testAssertSafeNameRejectsEmptyName(): void
    {
        $this->expectException(Exception::class);

        SafePath::assertSafeName('');
    }
}
