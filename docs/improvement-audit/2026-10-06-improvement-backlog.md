# php-iso improvement backlog — 2026-10-06

Produced with the `project-improvement-audit` skill (`.claude/skills/project-improvement-audit/SKILL.md`).
Items marked `[x]` were implemented in the same round, `[ ]` items are still open.

Legend: effort S/M/L, impact low/med/high.

## Summary

| Lens | Done | Open |
|---|---|---|
| bug / robustness | 13 | 6 |
| feature | 4 | 5 |
| ux | 3 | 2 |
| test / ci | 5 | 3 |

## Sweeps

- [x] **SWEEP-01** Gates: `composer run check` now runs phpcs, phpstan, rector and phpunit exactly like CI (S, med)
- [x] **SWEEP-02** Line endings: `.gitattributes` forces LF so PHPCS no longer fails on Windows checkouts (S, low)
- [ ] **SWEEP-03** Add unit tests for the volume descriptors (`PrimaryVolume`, `SupplementaryVolume`, `Partition`, `Boot`) built from synthetic buffers instead of fixtures only (M, med)
- [ ] **SWEEP-04** Raise coverage of `src/Cli/` (currently excluded in `phpunit.xml`); the CLI is now testable via `IsoTool::run($argv)` (S, low)

## Bugs and robustness

- [x] **BUG-01** `isotool --extract` built destination paths from untrusted ISO names (path traversal). Now validated by `Util\SafePath`, used by `Extractor` (`src/Util/SafePath.php`) (S, high)
- [x] **BUG-02** `Buffer::readBBO` returned -1 on mismatching halves *without advancing the offset*, misaligning every following field of the descriptor (wrong block size, dates, file structure version) (`src/Util/Buffer.php`) (S, high)
- [x] **BUG-03** Directories were limited to the first 4096 bytes and a zero record length ended parsing instead of skipping to the next sector, so big directories were truncated (`src/FileDirectory.php`) (M, high)
- [x] **BUG-04** `IsoDate::init17` dropped the UTC offset byte, treated hundredths of a second as milliseconds and ignored invalid components; `init7` read the signed offset as unsigned (`src/Util/IsoDate.php`) (S, med)
- [x] **BUG-05** `Buffer::readAString` ignored `$supplementary`, garbling Joliet names, path table identifiers and volume strings (`src/Util/Buffer.php:54`) (S, med)
- [x] **BUG-06** Joliet level detection compared concatenated decimal byte values with `str_contains`; now compares the three escape sequence bytes (`src/Descriptor/Volume.php`) (S, low)
- [x] **BUG-07** `loadGenPathTable` read every directory of the image once for nothing and with the wrong `supplementary` flag (`src/Descriptor/Volume.php`) (S, med)
- [x] **BUG-08** Only the M path table was loaded; the L (little endian) table is now used as fallback (`Volume::loadTable`) (S, med)
- [x] **BUG-09** `PathTableRecord::extractFile` leaked the handle on error and looped on a hostile `dataLength` after end of file; now `IsoFile::extractRange` with bounds, EOF detection and `finally` (S, high)
- [x] **ROB-01** Path table / directory sizes are bounded by the ISO file size (no multi-GB reads from a crafted descriptor) (S, high)
- [x] **ROB-02** `FileDirectory::init`, `PathTableRecord::init`, `IsoDate::init7` raise exceptions / return false on short buffers instead of PHP warnings on undefined indexes (S, med)
- [x] **ROB-03** `PathTableRecord::getFullPath` threw a TypeError on a missing parent directory number; now an `Exception` (S, low)
- [x] **ROB-04** Directory walk uses an explicit stack, a visited set and a depth limit, so loops or very deep trees in a crafted image cannot hang or overflow the stack (S, high)
- [ ] **BUG-10** Some writers (e.g. WinISO) store ASCII instead of UTF-16 in Joliet strings; detect and fall back (`src/Util/Buffer.php:41`, fixture `DOS4.01_bootdisk.iso` publisher id) (S, low)
- [ ] **BUG-11** `Buffer::getString` drops every NUL byte, which corrupts non UTF-16 data containing zeros (S, low)
- [ ] **BUG-12** Multi-extent files (flag 0x80) are listed as separate entries instead of one file (M, med)
- [ ] **BUG-13** `Volume::init` ignores the unused/reserved fields but still assigns to unused variables (`src/Descriptor/Volume.php:50-58`) (S, low)
- [ ] **ROB-05** `IsoFile::read` has no upper bound for `$length`; cap it centrally (S, med)
- [ ] **ROB-06** Duplicate descriptors (e.g. several supplementary volumes) end reading with an exception, the second Joliet / enhanced volume is lost (`src/IsoFile.php`, `processFile`) (M, low)

## Features

- [x] **FEAT-01** El Torito boot catalog: `Boot::loadCatalog()`, `BootCatalog`, `BootEntry` (validation entry checksum, default and section entries) (M, med)
- [x] **FEAT-02** `Volume::walk()` generator with `IsoEntry` value objects (path, size, location, date, hidden) (M, high)
- [x] **FEAT-03** `Extractor` class (library level extraction, callback per file) (M, high)
- [x] **FEAT-04** `IsoFile::getPrimaryVolume()/getSupplementaryVolume()/getBootRecord()/getPreferredVolume()` helpers (S, med)
- [ ] **FEAT-05** Rock Ridge (POSIX names, permissions, symlinks) (L, med)
- [ ] **FEAT-06** Read a single file as a stream / string without extracting (`IsoFile::openEntry()`) (M, med)
- [ ] **FEAT-07** Read the UDF file system (the descriptors are only detected today) (L, med)
- [ ] **FEAT-08** Non-seekable input (stream wrappers, `php://stdin`) (M, low)
- [ ] **FEAT-09** `isotool --cat <path>` and `--find <pattern>` on top of FEAT-06 (S, low)

## UX

- [x] **UX-01** CLI: `--list`, `--json`, `--help`, boot catalog in the info output (S, med)
- [x] **UX-02** CLI: errors go to STDERR, documented exit codes, `-x` without a value is a usage error instead of silently printing info, `-x` alone no longer triggers an undefined index notice (S, med)
- [x] **UX-03** `IsoTool::run()` returns the exit code (testable) and `bin/isotool` exits with it (S, low)
- [ ] **UX-04** Many descriptor properties are public and mutable; consider readonly properties in the next major version (L, low)
- [ ] **UX-05** `README` sample output should be regenerated from the current CLI (S, low)

## Tests / CI

- [x] **TEST-01** Tests for `SafePath`, `Extractor`, `Volume::walk`, `BootCatalog`, `IsoTool`, new `IsoDate` cases (S, med)
- [x] **TEST-02** The DOS fixture assertions encoded the misaligned parsing (file structure version 0, no dates); corrected (S, med)
- [x] **CI-01** `composer audit` step, `$GITHUB_OUTPUT` quoted (shellcheck SC2086) (S, low)
- [x] **CI-02** `AGENTS.md` referenced a test suite name and scripts that did not exist; fixed and `composer.json` scripts added (S, low)
- [x] **CI-03** Removed the Scrutinizer badge (S, low)
- [ ] **TEST-03** Build synthetic hostile ISOs in tests (path traversal names, huge sizes, directory loops) to prove ROB-01/ROB-04 and BUG-01 end to end (M, high)
- [ ] **CI-04** Run phpstan/rector with the lowest and highest dependency sets (`--prefer-lowest`) (S, low)
- [ ] **CI-05** Fail CI when coverage drops (Codecov `patch`/`project` status) (S, low)

## Execution instructions

1. Pick an open item, write a failing test first, implement, then run `composer run check`.
2. Tick the box here in the same commit.
3. Re-run the `project-improvement-audit` skill when most items are done; it compares against this file.
