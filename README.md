# PHP ISO Library

[![codecov](https://codecov.io/gh/indy2kro/php-iso/graph/badge.svg?token=NBj76nYtmB)](https://codecov.io/gh/indy2kro/php-iso) [![Tests](https://github.com/indy2kro/php-iso/actions/workflows/tests.yml/badge.svg)](https://github.com/indy2kro/php-iso/actions/workflows/tests.yml)

PHP Library used to read metadata and extract information from ISO files based on [php-iso-file](https://github.com/php-classes/php-iso-file)

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

Features
------------
- Reads the ISO 9660 volume descriptors (primary, supplementary, boot, partition, terminator)
- Joliet (Unicode long file names) detection and support
- El Torito boot catalog parsing (`Boot::loadCatalog()`)
- Directory tree walking with `Volume::walk()` (no need to process the path table manually)
- Safe extraction with `Extractor` (names coming from the ISO are validated, nothing can be written outside of the destination)
- Both-endian (M and L) path tables, directories spanning multiple sectors
- UDF file system reading (`IsoFile::getUdfFileSystem()`), UDF only images are browsable through `IsoFile::getFileSystem()` like ISO 9660 ones (same `FileSystem` interface: `walk`, `find`, `search`, `readFile`, `openStream`)
- Rock Ridge: POSIX long names, mode, owner, symbolic links, continuation areas and relocated directories (`Volume::walk()`, `IsoEntry::$rockRidge`); symbolic links are never created on extraction
- Reading file content: `Volume::find()`, `search()`, `readFile()`, `openStream()` (multi-extent files are reported once)

Known limitations
------------
- UDF: plain partitions with 2048 bytes blocks are read; sparable, virtual and metadata partitions (e.g. some Blu-ray images) are reported as unsupported

Installation
------------

This class can easily be installed via [Composer](https://getcomposer.org):  
`composer require indy2kro/php-iso`

CLI tool
------------

This tool also provides a CLI tool that can be used to view information about ISO files - `bin\isotool`:  
```
Description:
  Tool to process ISO files

Usage:
  isotool [options] --file=<path>

Options:
  -f, --file=<path>              Path for the ISO file (mandatory)
  -l, --list                     Print only the list of files (path and size)
  -j, --json                     Print all the information as JSON
  -x, --extract=<extract_path>   Extract files in the given location
  -c, --cat=<path>               Write the content of a file of the ISO to the standard output
      --find=<pattern>           List the files matching a pattern (e.g. "*.txt", case insensitive)
  -h, --help                     Show this help

Exit codes:
  0  success
  1  usage error
  2  invalid file argument
  3  the ISO could not be read or extracted
```

Sample usage:
```
Input ISO file: fixtures/1mb.iso

Number of descriptors: 3
  - Primary volume descriptor
   - System ID: Win32
   - Volume ID: 25_12_2024
   - App ID: PowerISO
   - File Structure Version: 1
   - Volume Space Size: 542
   - Volume Set Size: 1
   - Volume SeqNum: 1
   - Block size: 2048
   - Volume Set ID: 
   - Publisher ID: 
   - Preparer ID: 
   - Copyright File ID: 
   - Abstract File ID: 
   - Bibliographic File ID: 
   - Creation Date: 2024-12-25 14:01:20
   - Modification Date: 2024-12-25 14:01:20
   - Expiration Date: 
   - Effective Date: 
   - Files:
/1MB.PNG (location: 30) (length: 1048576)

  - Supplementary volume descriptor
   - System ID: Win32
   - Volume ID: 25_12_2024
   - App ID: PowerISO
   - File Structure Version: 1
   - Volume Space Size: 542
   - Volume Set Size: 1
   - Volume SeqNum: 1
   - Block size: 2048
   - Volume Set ID: 
   - Publisher ID: 
   - Preparer ID: 
   - Copyright File ID: ?
   - Abstract File ID: ?
   - Bibliographic File ID: ?
   - Creation Date: 2024-12-25 14:01:20
   - Modification Date: 2024-12-25 14:01:20
   - Expiration Date: 
   - Effective Date: 
   - Joliet Level: 3
   - Files:
/1mb.png (location: 30) (length: 1048576)

  - Terminator descriptor

Other examples:
  isotool -f image.iso --list              # path and size of every file
  isotool -f image.iso --find "*.txt"       # search by name (case insensitive)
  isotool -f image.iso --cat /DIR/FILE.TXT > file.txt
  isotool -f image.iso --json | jq .
```

Usage
-----
Walking the files of an ISO (Joliet names are used when present):
```php
<?php

use PhpIso\IsoFile;

$isoFile = new IsoFile('test.iso');
$volume = $isoFile->getPreferredVolume();

foreach ($volume->walk($isoFile) as $entry) {
    echo $entry->path, $entry->isDirectory ? '/' : ' (' . $entry->size . ' bytes)', PHP_EOL;
}
```

Extracting everything (names are untrusted input, the extractor refuses names that could escape the destination):
```php
(new \PhpIso\Extractor())->extract($isoFile, $volume, '/tmp/out');
```

Reading the El Torito boot catalog:
```php
$catalog = $isoFile->getBootRecord()?->loadCatalog($isoFile);
$entry = $catalog?->getDefaultEntry();
echo $entry?->getMediaName(), PHP_EOL;
```

Low level access to the descriptors and the path table:
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
