# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Changes marked **BREAKING** are described with before / after snippets in [UPGRADE-2.0.md](UPGRADE-2.0.md).

## [Unreleased]

## [2.0.0] - 2026-10-07

### Added

- UDF file system reading: `IsoFile::getUdfFileSystem()` and `Udf\UdfFileSystem`, with plain and metadata (UDF 2.50, e.g. Blu-ray) partition maps, symbolic links, owner and permissions (sparable and virtual partitions are reported as unsupported)
- `FileSystem` interface shared by ISO 9660 volumes and UDF (`walk`, `listDirectory`, `getEntryRanges`, `find`, `search`, `copyEntryTo`, `openStream`, `readFile`) and `IsoFile::getFileSystem()`, which also covers UDF only and UDF bridge images
- `IsoEntry` value object (path, name, size, location, dates, `extents`, `rockRidge`, `uid`, `gid`, `mode`, `symlinkTarget`) and `Volume::walk()`, a depth limited generator (`$maxDepth`, 64 by default)
- Rock Ridge support: POSIX long names, mode, owner, symbolic links, precise timestamps (`TF`), device numbers (`PN`), continuation areas and relocated directories (`RockRidgeInfo`); `Volume::walk()` / `listDirectory()` take `$rockRidge = false` (last parameter) to ignore it
- Reading file content: `readFile()`, lazy seekable `openStream()` (`Util\EntryStream`, data is read from the image on demand), `copyEntryTo()`, multi-extent files reported once
- `find()` by path, descending one directory at a time; `search()` matches the path when the pattern contains `/`
- `IsoFile::fromStream()` to read images from non-seekable streams (`IsoFile::MAX_STREAM_BYTES` limit)
- `IsoFile::getPrimaryVolume()`, `getSupplementaryVolume()`, `getEnhancedVolume()` (ISO 9660:1999), `getBootRecord()`, `getPreferredVolume()`, `getSize()`, `extractRange()`, `copyRange()`; `IsoFile::MAX_READ_LENGTH` and `MAX_DESCRIPTORS`
- El Torito: `Boot::loadCatalog()`, `BootCatalog` (including catalogs spanning several sectors), `BootEntry` with the image size, and `BootCatalog::extractImage()`
- `Extractor` with restored modification times, `preserveMode` (Rock Ridge permissions) and `continueOnError` with `getErrors()`; symbolic links are never created
- `Util\SafePath` to validate untrusted names
- `WalkWarnings` collector reporting incomplete listings (depth limit, oversized, unreadable or corrupt directories, skipped UDF entries), with a strict mode that throws
- CLI: `--cat`, `--find`, `--list`, `--json`, `--ndjson`, `--extract-boot`, `--files`, `--volume=primary|joliet|udf`, `--no-rock-ridge`, `--strict` (`WARNING:` lines on the standard error otherwise), `-f -` (standard input), bundled short flags (`-lj`), documented exit codes, errors on the standard error; `IsoTool::run()` returns the exit code and `IsoTool` accepts the streams used for the standard input and the error output
- `CHANGELOG.md`, `UPGRADE-2.0.md`, a rewritten `SECURITY.md` (private vulnerability reporting), generated real-world fixtures (`fixtures/build-fixtures.sh`), `composer run check` and the other composer scripts
- CI: tests on Linux, Windows and macOS with PHP 8.3 to 8.5, tests with the lowest dependencies, `composer audit`, coverage gates

### Changed

- **BREAKING:** descriptors, `FileDirectory`, `PathTableRecord` and `IsoFile::$descriptors` / `$additionalDescriptors` are immutable: public properties are `readonly` and set by the constructor. `Descriptor::init()` is gone, `FileDirectory::init()` / `PathTableRecord::init()` became `read()` returning `null` at the end of the records, `PathTableRecord::setDirectoryNumber()` is gone
- **BREAKING:** every date is a `CarbonImmutable` (`Volume` dates, `FileDirectory::$recordingDate`, `IsoEntry::$recordingDate`, `UdfNode::$modified`, `IsoDate::init7()` / `init17()`) instead of `Carbon`
- **BREAKING:** `FileSystem` (introduced in this release) has `listDirectory()` and `getEntryRanges()`; custom implementations must provide them (`Volume` and `UdfFileSystem` do)
- **BREAKING:** ISO 9660 file names are normalised: the version suffix (`;1`, `;32767`...) and the separator dot of names without extension are removed (`README.;1` is `README`, not `README.`); Joliet names are kept as stored
- **BREAKING:** `IsoFile::getSupplementaryVolume()` only returns Joliet descriptors; ISO 9660:1999 enhanced volume descriptors are available with `getEnhancedVolume()`
- **BREAKING:** `IsoFile::getFileSystem()` may return the UDF file system for bridge images (Windows install media) whose ISO 9660 tree is only a stub (no directory and at most one file)
- **BREAKING:** `PathTableRecord::getFullPath()` always uses `/`, it no longer depends on `DIRECTORY_SEPARATOR`
- **BREAKING (CLI):** the information output no longer lists the files, use `--files`
- **BREAKING (CLI):** a missing `--file` is a usage error (exit code 1, `Missing --file option`)
- **BREAKING (CLI):** conflicting actions (e.g. `-x out -c path`) are a usage error (exit code 1) instead of running only one; unknown options and unexpected arguments are usage errors too
- Dates are validated: impossible dates (31 February) are rejected instead of rolling over
- `IsoFile::openFile()` can be called twice without leaking the handle and rejects directories
- The CLI picks Joliet, then the primary volume, then UDF by default (see `--volume`)

### Fixed

- Partition descriptors read their location and size as both-byte-order 32-bit values (they were read as one 64-bit number)
- Type 2 descriptors without a Joliet escape sequence (ISO 9660:1999) are no longer decoded as UTF-16
- Joliet strings are only treated as ASCII on clear ASCII markers, CJK names and volume ids are no longer garbled
- Associated files (resource forks) no longer shadow their data file, and the extended attribute record is skipped when reading file data
- Multi-extent files keep their Rock Ridge name and attributes
- Rock Ridge entries following a `CE` entry are no longer dropped
- UDF symbolic links and special files are no longer reported as regular files with raw path components as content
- `Buffer::getBytes()` returns raw bytes instead of joined decimal values
- GMT offsets outside -48..+52 no longer lose the whole date, the offset is ignored
- Short descriptor reads fail with a clear "Truncated or invalid ISO image" message
- Earlier fixes already on the way to this release: path tables (both-endian, little endian fallback), directories spanning several sectors, Joliet level detection, date parsing, multi-extent files, duplicate descriptors

### Security

- Names from an image are validated by `Util\SafePath` before anything is written: separators, `:`, control characters, `. ` and `..`, `<>"|?*`, trailing dots or spaces and Windows reserved device names (`CON`, `NUL`, `COM1`...) are rejected on every OS, so nothing can be written outside of the destination
- Reads are bounded (`IsoFile::MAX_READ_LENGTH`, `MAX_DESCRIPTORS`, `MAX_STREAM_BYTES`, directory walk depth and visited set), `CD001` is checked for ISO descriptors, and the number of descriptors is capped
- UDF descriptor tags are verified (checksum and location) and allocation extents are checked against the partition length
- `SECURITY.md` asks for vulnerabilities to be reported privately through GitHub

### Removed

- `Descriptor::init()`, `FileDirectory::init()`, `PathTableRecord::init()` and `PathTableRecord::setDirectoryNumber()` (see Changed)
- The `processFile()` method of `IsoFile` (descriptors are read by the constructor)
- The Scrutinizer badge

## [1.1.0] - 2026-02-08

See the [GitHub release](https://github.com/indy2kro/php-iso/releases/tag/1.1.0).

[Unreleased]: https://github.com/indy2kro/php-iso/compare/2.0.0...HEAD
[2.0.0]: https://github.com/indy2kro/php-iso/compare/1.1.0...2.0.0
[1.1.0]: https://github.com/indy2kro/php-iso/releases/tag/1.1.0
