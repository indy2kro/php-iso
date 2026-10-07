# PHP ISO Library

[![codecov](https://codecov.io/gh/indy2kro/php-iso/graph/badge.svg?token=NBj76nYtmB)](https://codecov.io/gh/indy2kro/php-iso) [![Tests](https://github.com/indy2kro/php-iso/actions/workflows/tests.yml/badge.svg)](https://github.com/indy2kro/php-iso/actions/workflows/tests.yml)

PHP Library used to read metadata and extract information from ISO files based on [php-iso-file](https://github.com/php-classes/php-iso-file)

This library follows the [ISO 9660 / ECMA-119](https://www.ecma-international.org/wp-content/uploads/ECMA-119_4th_edition_june_2019.pdf) standard.

See [CHANGELOG.md](CHANGELOG.md) for the release notes, [UPGRADE-2.0.md](UPGRADE-2.0.md) when moving from 1.x and [SECURITY.md](SECURITY.md) to report a vulnerability (the library parses untrusted images).

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
- Each class contains various properties which can be used to interact with them, they are `public readonly`: the parsed structures are immutable and fully initialised when the object is created, and dates are `CarbonImmutable`
- `FileSystem` - what can be browsed: an ISO 9660 / Joliet `Volume` or the `UdfFileSystem`. Both offer `walk`, `listDirectory`, `find`, `search`, `readFile`, `openStream` and `copyEntryTo`, and describe files with `IsoEntry` objects

Features
------------
- Reads the ISO 9660 volume descriptors (primary, supplementary, enhanced (ISO 9660:1999), boot, partition, terminator)
- Joliet (Unicode long file names) detection and support (`IsoFile::getSupplementaryVolume()`); the ISO 9660:1999 enhanced volume is available with `IsoFile::getEnhancedVolume()`
- El Torito boot catalog parsing (`Boot::loadCatalog()`), including catalogs spanning several sectors, the boot image size (`BootEntry::getImageSize()`) and image extraction (`BootCatalog::extractImage()`)
- Directory tree walking with `Volume::walk()` (no need to process the path table manually) and lookups by path with `find()` (only the directories on the path are read), `listDirectory()` and `search()`
- Safe extraction with `Extractor` (names coming from the ISO are validated, nothing can be written outside of the destination); modification times are restored, and the options `preserveMode` (apply the Rock Ridge permissions) and `continueOnError` (collect the problems with `getErrors()` instead of aborting) are available
- Both-endian (M and L) path tables, directories spanning multiple sectors, multi-extent files (reported once)
- UDF file system reading (`IsoFile::getUdfFileSystem()`): plain and metadata partitions (UDF 2.50, e.g. Blu-ray images), symbolic links, owner and permissions. UDF only images (and UDF bridge images carrying a stub ISO 9660 tree) are browsable through `IsoFile::getFileSystem()` like ISO 9660 ones
- Rock Ridge: POSIX long names, mode, owner, symbolic links, precise timestamps (`TF`), device numbers (`PN`), continuation areas and relocated directories (`Volume::walk()`, `IsoEntry::$rockRidge`); symbolic links are never created on extraction
- `IsoEntry` exposes `uid`, `gid`, `mode` and `symlinkTarget` (Rock Ridge and UDF)
- Reading file content: `find()`, `search()`, `readFile()` and `openStream()`; streams are seekable and read the image on demand (`Util\EntryStream`), nothing is copied to a temporary file
- Images coming from non-seekable streams (standard input, pipes) with `IsoFile::fromStream()`

Known limitations
------------
- UDF: only 2048 bytes blocks are read; sparable and virtual (VAT) partitions are reported as unsupported. For metadata partitions the mirror and bitmap files are ignored

Installation
------------

This class can easily be installed via [Composer](https://getcomposer.org):  
`composer require indy2kro/php-iso`

Requires PHP 8.3 or newer and `nesbot/carbon`.

CLI tool
------------

This tool also provides a CLI tool that can be used to view information about ISO files - `bin\isotool`:  
```
Description:
  Tool to process ISO files

Usage:
  isotool [options] --file=<path>

Options:
  -f, --file=<path>              Path for the ISO file, "-" reads it from the standard input (mandatory)
  -l, --list                     Print only the list of files (path and size)
  -j, --json                     Print all the information as JSON
      --ndjson                   With --list: one JSON object per line and entry, streamed (newline delimited JSON)
  -x, --extract=<extract_path>   Extract files in the given location
  -c, --cat=<path>               Write the content of a file of the ISO to the standard output
      --find=<pattern>           List the files matching a pattern (e.g. "*.txt", case insensitive)
      --extract-boot=<path>      Write the El Torito default boot image to the given file
      --files                    Also list the files of every volume in the default information output
      --volume=<name>            File system used by --list, --cat, --find and --extract: primary, joliet or udf
                                 (default: Joliet, else primary, else UDF)
      --no-rock-ridge            Ignore the Rock Ridge extensions (use the plain ISO 9660 / Joliet names)
  -h, --help                     Show this help

Only one of --list, --json, --extract, --cat, --find and --extract-boot can be used at a time.
Flags can be bundled (e.g. -lj).

Exit codes:
  0  success
  1  usage error (unknown, conflicting or missing options, including a missing --file)
  2  invalid file argument
  3  the ISO could not be read or extracted
```

Sample usage (`isotool -f fixtures/1mb.iso --files`, without `--files` the lists of files are left out):
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

```

Other examples:
```
isotool -f image.iso --list                # path and size of every file (symbolic links show "-> target")
isotool -f image.iso --find "*.txt"        # search by name (case insensitive); a pattern with "/" matches the path
isotool -f image.iso --cat /DIR/FILE.TXT > file.txt
isotool -f image.iso --volume=primary -l   # the ISO 9660 tree instead of the Joliet one
isotool -f image.iso --extract-boot boot.img
isotool -f image.iso -l --ndjson           # one JSON object per line, streamed
isotool -f image.iso --json | jq .
cat image.iso | isotool -f - -l            # standard input
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

`getFileSystem()` or `getPreferredVolume()`? `getPreferredVolume()` returns the Joliet volume (else the primary one), it is only about ISO 9660 and returns `null` for UDF only images. `getFileSystem()` returns a `FileSystem` that also covers UDF: the preferred volume, or the UDF file system for UDF only images and for bridge images (e.g. Windows install media) whose ISO 9660 tree is only a stub. Prefer `getFileSystem()` unless you need a `Volume` (path table, descriptor fields).
```php
$fileSystem = $isoFile->getFileSystem();   // ?FileSystem
```

Looking up and reading files (`find()` matches the exact name first, then ignoring the case):
```php
$entry = $fileSystem->find($isoFile, '/dir/readme.txt');

if ($entry !== null) {
    $content = $fileSystem->readFile($isoFile, $entry);      // whole file, limited by IsoFile::MAX_READ_LENGTH

    $stream = $fileSystem->openStream($isoFile, $entry);     // seekable stream, read from the image on demand
    echo fread($stream, 100);
    fclose($stream);
}

foreach ($fileSystem->search($isoFile, '*.txt') as $match) {
    echo $match->path, PHP_EOL;
}
```

Entries carry the Rock Ridge / UDF attributes:
```php
echo $entry->mode, $entry->uid, $entry->gid;   // null when the image has no such information
echo $entry->getSymlinkTarget();               // null unless the entry is a symbolic link
```

Reading an image from a pipe or standard input (copied to a temporary file, removed when the object is destroyed; at most `IsoFile::MAX_STREAM_BYTES` by default):
```php
$isoFile = IsoFile::fromStream(STDIN);
$isoFile = IsoFile::fromStream($stream, 512 * 1024 * 1024); // custom limit in bytes
```

Extracting everything (names are untrusted input, the extractor refuses names that could escape the destination):
```php
(new \PhpIso\Extractor())->extract($isoFile, $fileSystem, '/tmp/out');

// keep the Rock Ridge permissions and carry on after an unsafe name or a failed write
$extractor = new \PhpIso\Extractor(preserveMode: true, continueOnError: true);
$extractor->extract($isoFile, $fileSystem, '/tmp/out');
print_r($extractor->getErrors());   // entry path => message
```

Reading the El Torito boot catalog and extracting the boot image:
```php
$catalog = $isoFile->getBootRecord()?->loadCatalog($isoFile);
$entry = $catalog?->getDefaultEntry();
echo $entry?->getMediaName(), PHP_EOL;
$catalog?->extractImage($isoFile, $entry, 'boot.img');
```

Errors and exceptions
-----
Every failure caused by the image or by an invalid argument is a `PhpIso\Exception` (`PhpIso\Descriptor\Exception` extends it), nothing else should escape for a damaged image; catching `PhpIso\Exception` is enough:
- `new IsoFile($path)`: the file does not exist, is not a regular file or cannot be opened, the volume descriptors are truncated or invalid, or there are more than `IsoFile::MAX_DESCRIPTORS` of them
- `IsoFile::fromStream()`: the stream cannot be read, is bigger than the limit, or is not an image (same as above)
- `IsoFile::getUdfFileSystem()` and `getFileSystem()`: the UDF structures are present but unsupported or corrupt (`getFileSystem()` falls back to the ISO 9660 tree when it has one)
- `FileSystem::readFile()`, `openStream()`, `copyEntryTo()`: the entry is a directory, the file is bigger than `$maxSize`, or its data lies outside of the image
- `Extractor::extract()`: unsafe names (path traversal, Windows reserved names...), failed writes (collected in `getErrors()` instead when `continueOnError` is set)
- `Volume::loadTable()`, `Boot::loadCatalog()`, `BootCatalog::extractImage()`: corrupt tables or catalogs

Limits
-----
The image is untrusted input, so sizes taken from it are bounded:
- `IsoFile::MAX_READ_LENGTH` (64 MiB): the largest single read, so a directory, path table or `readFile()` result cannot be bigger
- `IsoFile::MAX_STREAM_BYTES` (4 GiB): the largest stream accepted by `IsoFile::fromStream()` unless another limit is given
- `IsoFile::MAX_DESCRIPTORS` (64): the maximum number of volume descriptors read
- `walk($isoFile, $maxDepth = 64)`: directories nested deeper are not listed
A listing can therefore be incomplete (too deep or oversized directories, corrupt directory records); incomplete listings can be detected through the warnings collector that `walk()` accepts as an optional parameter.

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
