<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\PHPUnit\PHPUnit100\Rector\Class_\ParentTestClassConstructorRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets()
    ->withSets(
        [
            PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
            PHPUnitSetList::PHPUNIT_80,
            PHPUnitSetList::PHPUNIT_90,
            PHPUnitSetList::PHPUNIT_100,
            PHPUnitSetList::PHPUNIT_110,
            PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        ]
    )
    ->withSkip([
        ParentTestClassConstructorRector::class
    ])
    ->withRules([
        AddVoidReturnTypeWhereNoReturnRector::class,
    ]);
