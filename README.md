# PHP ISO Library

[![codecov](https://codecov.io/gh/indy2kro/php-iso/graph/badge.svg?token=NBj76nYtmB)](https://codecov.io/gh/indy2kro/php-iso) [![Tests](https://github.com/indy2kro/php-iso/actions/workflows/tests.yml/badge.svg)](https://github.com/indy2kro/php-iso/actions/workflows/tests.yml)

PHP Library used to read metadata from ISO files based on [php-iso-file](https://github.com/php-classes/php-iso-file)

This library follows the [ISO 9660 / ECMA-119](https://www.ecma-international.org/wp-content/uploads/ECMA-119_4th_edition_june_2019.pdf) standard.

Basic concepts
-----
- `IsoFile` - main ISO file object, contains one more descriptors
- `Descriptor` - descriptor object which can have one of the following types defined in `Type` class:
  - `BOOT_RECORD_DESC` : `Boot` object
  - `PRIMARY_VOLUME_DESC` : `PrimaryVolume` object
  - `SUPPLEMENTARY_VOLUME_DESC` : `SupplementaryVolume` object
  - `PARTITION_VOLUME_DESC` : `Partition` object
  - `TERMINATOR_DESC` : `Terminator` object
  - upon initialization of the `IsoFile` object, the descriptors will be populated automatically
- Volume descriptors contain path table inside which can be loaded using `loadTable`
  - `PathTableRecord` - object which contains the record information for a file/directory
- Each class contains various properties which can be used to interact with them, most of them `public`

Known limitations
------------
- ISO extensions currently not supported:
  - El Torito
  - Joliet
  - Rock Ridge
- UDF file format not supported
- Reading metadata requires manually processing the descriptors
  - Some Iterator implementation would be nice to have

Installation
------------

This class can easily be installed via [Composer](https://getcomposer.org):  
`composer require indy2kro/php-iso`


Usage
-----
```php
<?php

use PhpIso\IsoFile;
use PhpIso\Descriptor\Type;
use PhpIso\Descriptor\PrimaryVolume;
use PhpIso\FileDirectory;
use PhpIso\PathTableRecord;

$isoFilePath = 'test.iso';

$isoFile = new IsoFile($isoFilePath);

// you can process each descriptor using $isoFile->descriptors directly

/** @var PrimaryVolume $primaryVolumeDescriptor */
$primaryVolumeDescriptor = $isoFile->descriptors[Type::PRIMARY_VOLUME_DESC];

// get the path table
$pathTable = $primaryVolumeDescriptor->loadTable($isoFile);

$paths = [];

/** @var PathTableRecord $pathRecord */
foreach ($pathTable as $pathRecord) {
    $currentPath = $pathRecord->getFullPath($pathTable);

    $paths[$currentPath] = [];

    // check extents
    $extents = $pathRecord->loadExtents($isoFile, $primaryVolumeDescriptor->blockSize);

    if ($extents !== false) {
        /** @var FileDirectory $extentRecord */
        foreach ($extents as $extentRecord) {
            $path = $extentRecord->fileId;
            if ($extentRecord->isDirectory()) {
                $path .= '/';
            }
            $paths[$currentPath][] = $path;
        }
    }
}

print_r($paths);
```
