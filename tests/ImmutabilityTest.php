<?php

declare(strict_types=1);

namespace PhpIso\Test;

use Iterator;
use PhpIso\Descriptor;
use PhpIso\Descriptor\Boot;
use PhpIso\Descriptor\Partition;
use PhpIso\Descriptor\Volume;
use PhpIso\FileDirectory;
use PhpIso\IsoEntry;
use PhpIso\IsoFile;
use PhpIso\PathTableRecord;
use PhpIso\RockRidgeInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The parsed structures are value objects: none of their public properties can be changed
 */
final class ImmutabilityTest extends TestCase
{
    /**
     * @return Iterator<string, array{class-string}>
     */
    public static function classes(): Iterator
    {
        foreach ([Descriptor::class, Boot::class, Partition::class, Volume::class, FileDirectory::class, PathTableRecord::class, IsoEntry::class, RockRidgeInfo::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testEveryPublicPropertyIsReadonly(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $this->assertTrue($property->isReadOnly(), $class . '::$' . $property->getName() . ' must be readonly');
        }
    }

    public function testIsoFileDescriptorListsAreReadonly(): void
    {
        foreach (['descriptors', 'additionalDescriptors'] as $name) {
            $this->assertTrue((new ReflectionProperty(IsoFile::class, $name))->isReadOnly());
        }
    }
}
