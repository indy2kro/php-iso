# Upgrading to 2.0

This guide lists the backward incompatible changes between `1.1.0` and `2.0.0` with before / after snippets. The full list of changes is in [CHANGELOG.md](CHANGELOG.md).

2.0.0 also added the `FileSystem` / `IsoEntry` API, UDF and Rock Ridge reading, `Extractor` and new CLI options (see [CHANGELOG.md](CHANGELOG.md)); they do not change existing code. For the changes between 2.0.0 and 2.1.0 see [UPGRADE-2.1.md](UPGRADE-2.1.md).

Requirements are unchanged (PHP 8.3+, `nesbot/carbon` 3).

## Descriptors, directory records and path table records are immutable

Public properties of `Descriptor` and its subclasses, `FileDirectory`, `PathTableRecord` and `IsoFile::$descriptors` / `$additionalDescriptors` are `readonly` and set by the constructor. Reading them is unchanged, assigning to them throws an `Error`.

```php
// before
$pvd = $isoFile->descriptors[Type::PRIMARY_VOLUME_DESC];
$pvd->volumeId = 'CHANGED';              // worked

// after
$pvd->volumeId = 'CHANGED';              // Error: Cannot modify readonly property
```

`init()` is gone. Descriptors parse their bytes in the constructor; directory and path table records are created with `read()`, which returns `null` at the end of the records. `PathTableRecord::setDirectoryNumber()` is gone, the number is passed to `read()`.

```php
// before
$record = new FileDirectory();
while ($record->init($buffer, $offset, $supplementary)) {
    handle($record);
    $record = new FileDirectory();
}

// after
while (($record = FileDirectory::read($buffer, $offset, $supplementary, $jolietLevel)) !== null) {
    handle($record);
}
```

```php
// before
$ptRec = new PathTableRecord();
$ok = $ptRec->init($bytes, $offset, $supplementary, $littleEndian);
$ptRec->setDirectoryNumber($dirNum);

// after
$ptRec = PathTableRecord::read($bytes, $offset, $dirNum, $supplementary, $littleEndian); // ?PathTableRecord
```

Descriptor names and types are class constants (`Descriptor::getType()` is unchanged). `IsoFile::processFile()` was removed: the descriptors are read by the constructor.

Rule of thumb: use `$isoFile->descriptors` and the volume helpers (`getPrimaryVolume()`...) for reading, never to build or change descriptors.

## CLI

The exit codes are documented (`0` success, `1` usage, `2` invalid file, `3` read or extract error), errors go to the standard error output, and `IsoTool::run()` returns the exit code instead of calling `exit()`; `bin/isotool` exits with it.
