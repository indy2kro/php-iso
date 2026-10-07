# php-iso improvement backlog — 2026-10-07

Produced with the `project-improvement-audit` skill (`.claude/skills/project-improvement-audit/SKILL.md`).
Second round: the 2026-10-06 backlog (removed in `98fb134` once implemented) was compared and none of its items are repeated here.

Items marked `[x]` were implemented in the same round (all of them; see the PR for notes and limits).

Legend: effort S/M/L, impact low/med/high.

## Mechanical harvest

All gates are green: phpcs, phpstan, rector (dry run), phpunit (365 tests, 1406 assertions), `composer audit`, gitleaks, actionlint.
Line coverage is 96.6% (1179/1221). Method coverage is 82% (111/135), and coverage is weakest in `RockRidge` (0/4 methods fully covered), `IsoFile`, `Reader` and `IsoDate`.
Dev dependencies are slightly behind (phpstan 2.3, rector 2.7, phpunit 12.5.38); Dependabot already handles these.

## Summary

| Lens | Done | Open |
|---|---|---|
| sweep | 4 | 0 |
| bug | 12 | 0 |
| robustness | 7 | 0 |
| feature | 7 | 0 |
| ux | 6 | 0 |
| test / ci / docs | 8 | 0 |

After this round: 608 tests, 97.1% line coverage (1680/1730), 85.3% method coverage (162/190).

## Sweeps

- [x] **SWEEP-01** Expose every date as `CarbonImmutable`. Today `Volume::$creationDate` and the other volume dates, `FileDirectory::$recordingDate`, `IsoEntry::$recordingDate` and `UdfNode::$modified` are `readonly ?Carbon`, and that object can still be changed: `$pvd->creationDate->addDay()` modifies the descriptor. This undoes the immutability refactor (`a7c6101`) and contradicts AGENTS.md (`src/Util/IsoDate.php:21-92`, `src/Descriptor/Volume.php:43-46`, `src/FileDirectory.php:80`, `src/Udf/UdfFileSystem.php:445`) (M, med)
- [x] **SWEEP-02** Report incomplete listings. Several limits truncate the tree silently and callers cannot tell that it is incomplete: the 64-level depth limit in `walk`, directories over 64 MiB (`IsoFile::MAX_READ_LENGTH`), corrupt directory records (`break`), UDF root or entry errors (`continue`/`return`). Add a warnings collector or a strict mode (`src/Descriptor/Volume.php:154-156`, `src/FileDirectory.php:323-329`, `src/Udf/UdfFileSystem.php:156-185,372-390`) (M, med)
- [x] **SWEEP-03** Add real-world fixtures built with xorriso/genisoimage: Rock Ridge with symlinks and CE areas, ISO 9660:1999 EVD (`-iso-level 4`), Joliet with CJK names, an associated file, a UDF 2.50 metadata partition and a Windows-style UDF bridge. Rock Ridge, EVD and multi-extent behaviour is only proven against the synthetic `tests/Support` builders, which mirror the parser's own assumptions (see BUG-01) (M, med)
- [x] **SWEEP-04** Cover the remaining method-level gaps found by `--coverage-text`: `RockRidge` (CE edge cases, symlink component flags), `IsoFile` (`fromStream` failures, `closeFile`/`seek` on a closed handle), `Reader` (unknown type-0 identifier), and `IsoDate` (`tests/`) (S, low)

## Bugs

- [x] **BUG-01** `Partition` reads the 8-byte location and size fields as one 64-bit big-endian number. ECMA-119 8.6.7/8.6.8 define them as 7.3.3 both-byte-order 32-bit values, so real images get `(LE << 32) | BE`. `IsoBuilder::addPartition` encodes the same mistake (`pack('J')`), so the test passes (`src/Descriptor/Partition.php:50-51`, `tests/Support/IsoBuilder.php:83`) (S, med)
- [x] **BUG-02** Every type-2 descriptor is decoded as UTF-16. An ISO 9660:1999 Enhanced Volume Descriptor (type 2, version 2, e.g. `genisoimage -iso-level 4`) or any SVD without a Joliet escape sequence becomes `getSupplementaryVolume()` and `getPreferredVolume()`, and all its names become garbage. Base `$supplementary` on `jolietLevel > 0` and expose the EVD separately (`src/Descriptor/Volume.php:58,144`, `src/IsoFile.php:202-250`) (M, high)
- [x] **BUG-03** On Windows install ISOs, `getFileSystem()` returns the ISO 9660 bridge, which only holds a stub `README.TXT`, so the real UDF tree is never listed or extracted. Prefer UDF when the ISO 9660 tree is a stub, or when UDF is the richer tree (`src/IsoFile.php:239-250`) (S, high)
- [x] **BUG-04** Only a trailing `;1` is removed from file identifiers. Other versions (`;2`, `;32767`) are kept, and so is the separator dot of names without an extension (`README.;1` becomes `README.`; the tests expect `'LICENSE.'`). This affects `find()`, extraction and listings (`src/FileDirectory.php:170-175`, `tests/IsoFileTest.php:139`) (S, med)
- [x] **BUG-05** The Joliet ASCII fallback treats many CJK UTF-16 strings as ASCII, because both bytes of code points in U+2020–U+7E7E are printable (e.g. 本 = `0x67 0x2C`). A Chinese or Japanese volume id is then shown as `g,`. Fall back only on clear ASCII markers, such as odd length or `0x20 0x20` padding (`src/Util/Buffer.php:41-44`) (S, med)
- [x] **BUG-06** Associated files (flag `0x04`, e.g. Mac resource forks) are yielded as separate entries with the same path as the data file. `find()` can return the fork, and extraction writes one file over the other (`src/Descriptor/Volume.php:161-198`) (S, med)
- [x] **BUG-07** File content is read from `location * blockSize` without skipping the extended attribute record. When `extendedAttrRecordLength > 0`, the data starts that many blocks later (ECMA-119 6.5.2/9.1.2), so the XAR bytes are returned as content (`src/Descriptor/Volume.php:198,245-247`) (S, low)
- [x] **BUG-08** Multi-extent files on Rock Ridge images lose their RR data: `appendExtent` stores the ISO `fileId` as the name (while the path uses the RR name) and drops `$rr` (mode, owner) (`src/Descriptor/Volume.php:177-230`) (S, low)
- [x] **BUG-09** `PathTableRecord::getFullPath()` builds paths with `DIRECTORY_SEPARATOR`, which gives `\DIR\SUB\` on Windows, while `walk()` and `find()` use `/`. ISO paths should not depend on the host OS (`src/PathTableRecord.php:113-145`) (S, low)
- [x] **BUG-10** Impossible dates such as 31 February are not rejected: `Carbon::create(2020, 2, 31)` silently returns 2 March. Validate with `checkdate()` (`src/Util/IsoDate.php:80-91`) (S, low)
- [x] **BUG-11** UDF symlinks (file type 12) and other non-regular file types are reported as regular files, so extraction writes the raw path-component records as file content (`src/Udf/UdfFileSystem.php:277-291`) (S, low)
- [x] **BUG-12** `Buffer::getBytes()` joins the decimal values (`[1,2,3]` becomes `'123'`, and the test asserts this) instead of raw bytes. It has no caller in `src/`; fix it or remove it (`src/Util/Buffer.php:100-112`, `tests/Util/BufferTest.php:61-63`) (S, low)

## Robustness

- [x] **ROB-01** Check the `CD001` identifier for ISO descriptor types 1, 2, 3 and 255, and cap the number of descriptors. Today any file with byte `1` at offset `0x8000` is parsed as a PVD, and a crafted image full of duplicate SVDs grows `additionalDescriptors` until EOF (`src/Descriptor/Reader.php:38-61`, `src/IsoFile.php:318-359`) (S, med)
- [x] **ROB-02** `SafePath` only rejects separators, `:` and control characters. On Windows, names such as `CON`, `NUL`, `COM1`, names with trailing dots or spaces, and the characters `<>"|?*` either write to devices or make `fopen` fail and abort the extraction (`src/Util/SafePath.php:19-29`) (S, med)
- [x] **ROB-03** UDF tags are accepted on the identifier alone. Verify the tag checksum (byte 4) and the tag location (bytes 12-15) so that a stray `0x0002` sector is not taken as an anchor (`src/Udf/UdfFileSystem.php:480-483`) (S, med)
- [x] **ROB-04** Short descriptor reads (fewer than 2048 bytes) are parsed as partial descriptors. A truncated or non-ISO file fails with `Failed to read buffer entry 1` (`fixtures/invalid.iso`) instead of a clear "not an ISO 9660 image / truncated" message (`src/Descriptor/Reader.php:19-36`) (S, low)
- [x] **ROB-05** Rock Ridge: when a `CE` entry is found, the parser jumps to the continuation area at once and drops the entries that follow it in the current area. It also ignores the `SP` presence check and its `len_skp` (`src/Descriptor/RockRidge.php:95-113`) (S, low)
- [x] **ROB-06** UDF extents are not checked against the partition length (Partition Descriptor, offset 192), so a crafted allocation descriptor can point anywhere in the image (`src/Udf/UdfFileSystem.php:115,352-353`) (S, low)
- [x] **ROB-07** A GMT offset byte outside -48..+52 builds an invalid timezone (`+25:00`), and the whole date is lost. Ignore the offset instead (`src/Util/IsoDate.php:70-73`) (S, low)

## Features

- [x] **FEAT-01** UDF metadata partition maps (type 2, UDF 2.50+), used by Blu-ray and most modern UDF images, fail with "only plain partitions can be read". Sparable and VAT maps are also missing (`src/Udf/UdfFileSystem.php:248-250`) (L, med)
- [x] **FEAT-02** `find()` walks the whole tree for each lookup. Descend one path component at a time, reading only the directories on the way (`src/BrowsesEntries.php:17-33`) (M, med)
- [x] **FEAT-03** El Torito: add the boot image size (emulation type and sector count) and `isotool --extract-boot`, and read catalogs that span more than one sector (`src/Descriptor/BootEntry.php`, `src/Descriptor/BootCatalog.php:40`) (M, med)
- [x] **FEAT-04** `Extractor`: keep modification times (`touch` with `recordingDate`), and optionally the Rock Ridge mode (`src/Extractor.php:49-58`) (S, med)
- [x] **FEAT-05** `Extractor`: add a mode that continues past an unsafe name or failed write and reports it, and delete the partial file when a copy fails. Today the first problem aborts the whole extraction (`src/Extractor.php:30-61`) (S, med)
- [x] **FEAT-06** `openStream()` copies the whole file to `php://temp` before returning it. A stream wrapper that reads lazily would avoid writing several GB to disk for one `cat` (`src/BrowsesEntries.php:56-68`) (M, low)
- [x] **FEAT-07** Rock Ridge `TF` (precise timestamps) and `PN` (device numbers), and UDF owner and permissions from the File Entry (offsets 36-47), are not exposed on `IsoEntry` (`src/Descriptor/RockRidge.php:58-120`, `src/Udf/UdfFileSystem.php:259-291`) (M, low)

## UX

- [x] **UX-01** CLI: add `--volume=primary|joliet|udf` and `--no-rock-ridge`, so users can choose the tree used by `--list`, `--cat`, `--find` and `--extract`. Today they always get `getFileSystem()` (`src/Cli/IsoTool.php:255-258`) (S, med)
- [x] **UX-02** CLI: the info action prints every file of every volume (primary, Joliet and UDF), which is unusable on large images. Make the file listing opt-in, or add `--no-files` (`src/Cli/IsoTool.php:128-157`) (S, low)
- [x] **UX-03** CLI argument handling: conflicting actions (`-x out -c path`) silently run only one; bundled short flags (`-lj`) silently drop the second flag; a missing `-f` reports "Invalid value for file received" (`src/Cli/IsoTool.php:58-96,417-419`) (S, low)
- [x] **UX-04** `IsoFile::openFile()`/`closeFile()`/`seek()`/`read()` are public. Calling `openFile()` twice leaks the handle, and `file_exists()` accepts a directory (on Linux this gives PHP read warnings instead of an `Exception`). Make them idempotent or internal, and use `is_file()` (`src/IsoFile.php:252-297`) (S, low)
- [x] **UX-05** `--json` and the info output collect every entry with `iterator_to_array`, so memory grows with the image. Stream the output, or offer NDJSON for `--list --json` (`src/Cli/IsoTool.php:212-234`) (S, low)
- [x] **UX-06** `search()` matches only the entry name, so a pattern containing `/` (`docs/*.txt`) never matches. Match against the path when the pattern contains a separator (`src/BrowsesEntries.php:40-47`) (S, low)

## Tests / CI / docs

- [x] **DOC-01** `SECURITY.md` asks for vulnerabilities to be reported in public GitHub issues. For a parser of untrusted input, enable GitHub private vulnerability reporting and link to it (`SECURITY.md`) (S, med)
- [x] **DOC-02** No release since `1.1.0` (Feb 2026), despite UDF, Rock Ridge, streams and a BC-breaking `refactor!` (readonly descriptors). Add a `CHANGELOG.md` and an upgrade note, then tag `2.0.0` (S, med)
- [x] **CI-01** Add a `windows-latest` job (at least phpunit). `bin/isotool.bat`, `SafePath` (ROB-02) and `DIRECTORY_SEPARATOR` code (BUG-09) are never run on Windows in CI, although development happens there (`.github/workflows/tests.yml`) (S, med)
- [x] **TEST-01** Fuzz the parser: mutate the fixtures (random bytes and lengths) in a nightly job, and assert that only `PhpIso\Exception` is ever thrown, with no PHP warnings, `TypeError`s or hangs (`tests/HostileIsoTest.php`) (M, med)
- [x] **CI-02** `composer run tests:coverage` fails on Xdebug machines ("No tests executed", exit 1) unless `XDEBUG_MODE=coverage` is set; add `@putenv XDEBUG_MODE=coverage`. Also update the `phpunit.xml` schema location from 8.3 to the installed PHPUnit (`composer.json:45`, `phpunit.xml:15`) (S, low)
- [x] **CI-03** `export-ignore` the dev-only files (`.claude/`, `phpcs.xml`, `phpstan.neon`, `phpunit.xml`, `rector.php`, `codecov.yml`, `docs/`) so they are not shipped in the dist archive (`.gitattributes`) (S, low)
- [x] **TEST-02** `tests/Support/IsoBuilder` encodes fields with the parser's own assumptions (BUG-01 passed this way). Cross-check the builders against images produced by an external tool in at least one test per descriptor type (`tests/Support/IsoBuilder.php`) (S, med)
- [x] **DOC-03** README: document `IsoFile::fromStream()`, `getFileSystem()` vs `getPreferredVolume()`, the exceptions each entry point throws, and the size and depth limits (`MAX_READ_LENGTH`, `MAX_STREAM_BYTES`, `maxDepth`) (`README.md`) (S, low)

## Execution instructions

1. Pick an open item, write a failing test first, implement, then run `composer run check`.
2. Tick the box here in the same commit.
3. Re-run the `project-improvement-audit` skill when most items are done; it compares against this file.
