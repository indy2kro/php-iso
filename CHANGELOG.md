# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Changes marked **BREAKING** are described with before / after snippets in [UPGRADE-2.0.md](UPGRADE-2.0.md) (1.1.0 to 2.0.0) and [UPGRADE-2.1.md](UPGRADE-2.1.md) (2.0.0 to 2.1.0).

## [Unreleased]

## [2.1.0] - 2026-10-07

2.0.0 was released one day before this round of fixes was complete; the breaking changes below ship in a minor release on purpose, see [UPGRADE-2.1.md](UPGRADE-2.1.md).

### Added

- UDF metadata partition maps (UDF 2.50+, e.g. Blu-ray); sparable and virtual partitions are reported as unsupported with the map name
- UDF symbolic links, owner and permissions; Rock Ridge precise timestamps (`TF`) and device numbers (`PN`); `IsoEntry` gained `uid`, `gid`, `mode` and `symlinkTarget` (`getSymlinkTarget()`), filled from Rock Ridge or UDF
- `FileSystem::listDirectory()` (direct children of a directory) and `getEntryRanges()`; `find()` descends one directory at a time instead of walking the whole tree
- Lazy, seekable `openStream()` (`Util\EntryStream`): data is read from the image on demand instead of being copied to a temporary stream first
- `search()` matches the path when the pattern contains `/`
- `IsoFile::getEnhancedVolume()` (ISO 9660:1999 enhanced volume descriptor) and `IsoFile::MAX_DESCRIPTORS`
- El Torito: `BootEntry::getImageSize()` / `getEmulationType()`, `BootCatalog::extractImage()`, catalogs spanning several sectors
- `Extractor`: modification times restored, `preserveMode` (Rock Ridge permissions), `continueOnError` with `getErrors()`, partial files removed when a copy fails
- `WalkWarnings` collector reporting incomplete listings (depth limit, oversized, unreadable or corrupt directories, skipped UDF entries), with a strict mode that throws
- CLI: `--files`, `--volume=primary|joliet|udf`, `--no-rock-ridge`, `--ndjson` (with `--list`), `--extract-boot`, `--strict` (`WARNING:` lines on the standard error otherwise), bundled short flags (`-lj`); `IsoTool` accepts the streams used for the standard input and the error output
- `CHANGELOG.md`, `UPGRADE-2.0.md`, `UPGRADE-2.1.md`, a rewritten `SECURITY.md` (private vulnerability reporting)
- Tests: generated real-world fixtures (`fixtures/build-fixtures.sh`: xorriso, genisoimage, mkudffs with the Linux UDF driver, pycdlib) with manifests of their source files, end to end smoke tests of `bin/isotool` (`tests/smoke/run.sh`, `composer run smoke`), deterministic parser fuzzing, builder cross-checks
- CI: one matrix over Linux, Windows and macOS with PHP 8.3 to 8.5, smoke tests on every OS (also through `bin/isotool.bat`), `tests:coverage` working with Xdebug, dev files excluded from the package

### Changed

- **BREAKING:** every date is a `CarbonImmutable` (`Volume` dates, `FileDirectory::$recordingDate`, `IsoEntry::$recordingDate`, `UdfNode::$modified`, `IsoDate::init7()` / `init17()`) instead of `Carbon`
- **BREAKING:** `FileSystem` has `listDirectory()` and `getEntryRanges()` and an optional `WalkWarnings` parameter on `walk()`, `listDirectory()`, `find()` and `search()`; custom implementations must follow (`Volume` and `UdfFileSystem` do)
- **BREAKING:** `Volume::walk()` takes the `WalkWarnings` collector as third parameter, `$rockRidge` moved to the end
- **BREAKING:** ISO 9660 file names are normalised: the version suffix (`;1`, `;32767`...) and the separator dot of names without extension are removed (`README.;1` is `README`, not `README.`); Joliet names are kept as stored
- **BREAKING:** `IsoFile::getSupplementaryVolume()` only returns Joliet descriptors; ISO 9660:1999 enhanced volume descriptors are available with `getEnhancedVolume()`
- **BREAKING:** `IsoFile::getFileSystem()` may return the UDF file system for bridge images (Windows install media) whose ISO 9660 tree is only a stub (no directory and at most one file)
- **BREAKING:** `PathTableRecord::getFullPath()` always uses `/`, it no longer depends on `DIRECTORY_SEPARATOR`
- **BREAKING:** `Util\SafePath` also rejects Windows reserved device names, names ending with a dot or a space and `<>"|?*`, on every OS
- **BREAKING (CLI):** the information output no longer lists the files, use `--files`
- **BREAKING (CLI):** a missing `--file` is a usage error (exit code 1, `Missing --file option`)
- **BREAKING (CLI):** conflicting actions (e.g. `-x out -c path`) are a usage error (exit code 1) instead of running only one
- Dates are validated: impossible dates (31 February) are rejected instead of rolling over
- `IsoFile::openFile()` can be called twice without leaking the handle and rejects directories
- Associated files (resource forks) are no longer listed

### Fixed

- Partition descriptors read their location and size as both-byte-order 32-bit values (they were read as one 64-bit number)
- Type 2 descriptors without a Joliet escape sequence (ISO 9660:1999) are no longer decoded as UTF-16
- Joliet strings are only treated as ASCII on clear ASCII markers, CJK names and volume ids are no longer garbled
- The extended attribute record is skipped when reading file data
- Multi-extent files keep their Rock Ridge name and attributes
- Rock Ridge entries following a `CE` entry are no longer dropped, the `SP` skip length is honoured
- UDF symbolic links and special files are no longer reported as regular files with raw path components as content
- `Buffer::getBytes()` returns raw bytes instead of joined decimal values
- GMT offsets outside -48..+52 no longer lose the whole date, the offset is ignored
- A 17 byte date with float notation digits no longer raises a PHP warning
- Short descriptor reads fail with a clear "Truncated or invalid ISO image" message

### Security

- `CD001` is checked for ISO descriptors and the number of descriptors is capped
- UDF descriptor tags are verified (checksum and location) and allocation extents are checked against the partition length
- More unsafe names are rejected on extraction (see Changed)
- `SECURITY.md` asks for vulnerabilities to be reported privately through GitHub

## [2.0.0] - 2026-10-06

### Added

- UDF file system reading (`IsoFile::getUdfFileSystem()`, `Udf\UdfFileSystem`, plain partition maps); UDF only images are supported
- `FileSystem` interface shared by ISO 9660 volumes and UDF (`walk`, `find`, `search`, `copyEntryTo`, `openStream`, `readFile`), `IsoFile::getFileSystem()`, `IsoEntry` value object
- Rock Ridge: POSIX long names, mode, owner, symbolic links, continuation areas and relocated directories (`RockRidgeInfo`)
- `Volume::walk()` directory tree generator (explicit stack, visited set, depth limit), multi-extent files reported once
- `IsoFile::fromStream()` to read images from non-seekable streams, `getPrimaryVolume()`, `getSupplementaryVolume()`, `getBootRecord()`, `getPreferredVolume()`, `getSize()`, `extractRange()`, `copyRange()`, `MAX_READ_LENGTH`
- El Torito boot catalog (`Boot::loadCatalog()`, `BootCatalog`, `BootEntry`)
- `Extractor` and `Util\SafePath` (names from the image are validated, nothing is written outside of the destination)
- CLI: `--list`, `--json`, `--cat`, `--find`, `--help`, `-f -` (standard input), documented exit codes, errors on the standard error, `IsoTool::run()` returns the exit code
- `composer run check` and the other composer scripts, CI with the lowest dependencies, `composer audit`, coverage gates

### Changed

- **BREAKING:** descriptors, `FileDirectory`, `PathTableRecord` and `IsoFile::$descriptors` / `$additionalDescriptors` are immutable: public properties are `readonly` and set by the constructor. `FileDirectory::init()` / `PathTableRecord::init()` became `read()` returning `null` at the end of the records

### Fixed

- Path tables (both-endian, little endian fallback), directories spanning several sectors, Joliet level detection and strings, date parsing (UTC offset, hundredths), misaligned both-byte-order fields; duplicate descriptors no longer stop the reading

### Removed

- `Descriptor::init()`, `FileDirectory::init()`, `PathTableRecord::init()`, `PathTableRecord::setDirectoryNumber()` and `IsoFile::processFile()`
- The Scrutinizer badge

## [1.1.0] - 2026-02-08

See the [GitHub release](https://github.com/indy2kro/php-iso/releases/tag/1.1.0).

[Unreleased]: https://github.com/indy2kro/php-iso/compare/2.1.0...HEAD
[2.1.0]: https://github.com/indy2kro/php-iso/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/indy2kro/php-iso/compare/1.1.0...2.0.0
[1.1.0]: https://github.com/indy2kro/php-iso/releases/tag/1.1.0
