# Upgrading to 2.1

This guide lists the backward incompatible changes between `2.0.0` and `2.1.0` with before / after snippets. 2.0.0 was released one day before these changes were finished, so they ship as a minor release; the full list is in [CHANGELOG.md](CHANGELOG.md). Upgrading from 1.1.0? Read [UPGRADE-2.0.md](UPGRADE-2.0.md) first.

Requirements are unchanged (PHP 8.3+, `nesbot/carbon` 3).

## Dates are `CarbonImmutable`

```php
// before
$pvd->creationDate->addDay();             // changed the descriptor's date (Carbon is mutable)
function show(?\Carbon\Carbon $date) {}

// after
$next = $pvd->creationDate?->addDay();    // a new CarbonImmutable, the descriptor is untouched
function show(?\Carbon\CarbonImmutable $date) {}
```

This applies to the volume dates, `FileDirectory::$recordingDate`, `IsoEntry::$recordingDate`, `UdfNode::$modified` and the return type of `IsoDate::init7()` / `init17()`. Impossible dates (e.g. 31 February) now give `null` instead of rolling over into March, and GMT offsets outside -48..+52 are ignored instead of losing the date.

## `FileSystem` gained `listDirectory()` and `getEntryRanges()`

The interface also declares the optional `WalkWarnings` parameter of `walk()`, `listDirectory()`, `find()` and `search()`. Only matters for your own `FileSystem` implementations; `Volume` and `UdfFileSystem` already provide them.

```php
// before
final class MyFileSystem implements FileSystem
{
    // walk, find, search, copyEntryTo, openStream, readFile
}

// after: also implement
public function listDirectory(IsoFile $isoFile, ?IsoEntry $directory = null): Generator; // direct children, null = root
public function getEntryRanges(IsoFile $isoFile, IsoEntry $entry): array;               // list<array{offset, length}>
```

The `BrowsesEntries` trait provides `find()`, `search()`, `openStream()` and `readFile()` on top of these.

## ISO 9660 names are normalised

The version suffix (`;1`, `;2`, `;32767`) and the separator dot of names without extension are removed from file names (directories and Joliet names are not touched).

```php
// before
$record->fileId;                          // 'README.' (stored as README.;1), 'FILE.TXT'
$volume->find($isoFile, '/README.');

// after
$record->fileId;                          // 'README', 'FILE.TXT'
$volume->find($isoFile, '/README');
```

Update lookups, comparisons and tests that expect `;1` or a trailing `.`.

## `Volume::walk()` takes the warnings collector before `$rockRidge`

`walk()`, `listDirectory()`, `find()` and `search()` accept an optional `WalkWarnings` collector (incomplete listings). On `Volume`, it is the third parameter of `walk()` and `listDirectory()`, so the Rock Ridge switch moved to the end:

```php
// before
$volume->walk($isoFile, 64, false);                    // without Rock Ridge

// after
$volume->walk($isoFile, 64, null, false);              // or named: walk($isoFile, rockRidge: false)
```

## `getSupplementaryVolume()` only returns Joliet descriptors

Supplementary descriptors without a Joliet escape sequence, such as the ISO 9660:1999 enhanced volume descriptor, used to be returned (and their names decoded as UTF-16, giving garbage). They are now separate.

```php
// before
$svd = $isoFile->getSupplementaryVolume();   // Joliet, enhanced or any other type 2 descriptor

// after
$joliet   = $isoFile->getSupplementaryVolume();  // only when jolietLevel > 0, else null
$enhanced = $isoFile->getEnhancedVolume();       // ISO 9660:1999 (version 2), else null
```

`getPreferredVolume()` follows: Joliet, otherwise primary.

## `getFileSystem()` may return UDF for bridge images

For UDF bridge images (Windows install media...) the ISO 9660 tree is a stub (typically one `README.TXT`). When the ISO 9660 root has no directory and at most one file while the UDF root has more entries, `getFileSystem()` returns the UDF file system instead of the volume.

```php
// before
$fs = $isoFile->getFileSystem();             // the stub ISO 9660 tree

// after
$fs = $isoFile->getFileSystem();             // the UDF tree (UdfFileSystem), the real content
// need the ISO 9660 tree explicitly?
$volume = $isoFile->getPreferredVolume();
```

Do not assume `getFileSystem()` returns a `Volume`: use `getPreferredVolume()` when you need path tables or descriptor fields. UDF entries use absolute byte offsets in `IsoEntry::$location` and the extents.

## `PathTableRecord::getFullPath()` always uses `/`

```php
// before (on Windows)
$record->getFullPath($pathTable);            // '\DIR\SUB\'

// after (on every OS)
$record->getFullPath($pathTable);            // '/DIR/SUB/'
```

## CLI

The exit codes are unchanged (`0` success, `1` usage, `2` invalid file, `3` read or extract error).

The default information output no longer lists the files (it could be huge):

```
# before
isotool -f image.iso                 # descriptors and the files of every volume

# after
isotool -f image.iso                 # descriptors only
isotool -f image.iso --files         # also the files of every volume
isotool -f image.iso --list          # one line per file
```

A missing `--file` is a usage error:

```
# before
isotool -l                           # "Invalid value for file received", exit code 2

# after
isotool -l                           # "ERROR: Missing --file option", exit code 1
```

Conflicting actions (`--list`, `--json`, `--extract`, `--cat`, `--find`, `--extract-boot`) are a usage error; before, one of them silently won. Unknown options and unexpected arguments are usage errors as well.

```
# before
isotool -f image.iso -x out -c /FILE   # ran only the extraction

# after
isotool -f image.iso -x out -c /FILE   # ERROR: Only one action can be used at a time, got: --extract, --cat (exit code 1)
```

## Extraction rejects more names

`Util\SafePath` (used by `Extractor` and `isotool --extract`) rejects names that were silently accepted before, on every OS: Windows reserved device names (`CON`, `PRN`, `AUX`, `NUL`, `COM1`-`COM9`, `LPT1`-`LPT9`, with or without an extension), names ending with a dot or a space, and the characters `< > " | ? *`, together with separators, `:` and control characters. By default `extract()` throws on the first such name; to extract everything else and collect the problems:

```php
// before / default: aborts with PhpIso\Exception on the first unsafe name
(new Extractor())->extract($isoFile, $fileSystem, $dest);

// carry on and report
$extractor = new Extractor(continueOnError: true);
$extractor->extract($isoFile, $fileSystem, $dest);
$problems = $extractor->getErrors();         // entry path => message
```

Extracted files now get the modification time of the image; pass `preserveMode: true` to also apply the Rock Ridge permissions.
