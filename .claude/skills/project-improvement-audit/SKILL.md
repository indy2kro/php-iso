---
name: project-improvement-audit
description: Use when you want a project-wide audit of the php-iso library (every class under src/, bin/, tests, CI workflow, docs) to discover many different improvement opportunities — correctness bugs, binary-parsing robustness, API/CLI UX gaps, test/CI gaps, and new-feature ideas — compiled into a prioritized, checkbox backlog in docs/improvement-audit/. Discovery only; no source changes.
---

# Project Improvement Audit — php-iso

## Overview

Harvest mechanical signals from the real gates. Then go through the module
clusters one batch at a time (no subagents), dedupe and prioritize, and write a
dated backlog. **No code changes.** The deliverable is the report.

## Token budget

- **No subagents.** Run batches one after another in the main conversation.
- Up to **10 findings per batch**. Rationale is **1 sentence**.
- Skip pure code-style/refactor findings unless they have caused bugs (PHPCS,
  PHPStan and Rector already guard style).
- **Write each batch's findings to disk** (scratchpad `findings/batch-N.json`)
  as soon as the batch is done, then drop the raw grep/read output from context.
- `rg -g`/`rg --type` can get rewritten by the rtk hook and fail. Use the Grep
  tool or `rtk proxy rg ...` for glob-filtered searches.
- Pipe gate output through `tail` only for display; always check exit codes
  separately (a `| tail` hides failures).

## Target count

Default **X = 30** deduplicated items (the library is ~25 classes). Honor an
explicit override. Never pad with filler.

## Lenses

| Lens | Looks for |
|---|---|
| `bug` | Wrong behavior: off-by-one in sector/offset math, wrong endianness or field widths, unchecked `fread`/`fseek` results, truncated reads treated as valid, descriptor-order assumptions, timezone/date parsing errors, swallowed exceptions |
| `robustness` | Malformed/hostile ISO input: huge lengths driving allocations or loops, infinite loops, missing bounds checks, files not closed, 32-bit/`int` overflow on large images |
| `ux` | `bin/` CLI: unclear messages, exit codes, ignored args, missing options; public API that is awkward or leaks internals (public mutable properties, nullable-everywhere) |
| `feature` | Missing capabilities of real value: Joliet/Rock Ridge/El Torito, directory tree listing, file extraction, stream/non-seekable input, JSON output, UDF details |
| `test` / `ci` | Coverage holes (no fixtures for X), missing gates in `.github/workflows/tests.yml` (PHP matrix, coverage, cs/rector check, composer audit), docs gaps (README, CONTRIBUTING) |

Tie-break: `bug` > `robustness` > `feature` > `ux`.

## Pipeline

### 1. Mechanical harvest (repo root)

```sh
composer install
php vendor/bin/phpstan analyze --error-format=raw ; echo "exit=$?"
vendor/bin/phpcs ; echo "exit=$?"                  # per phpcs.xml
vendor/bin/rector process --dry-run ; echo "exit=$?"
vendor/bin/phpunit --no-coverage ; echo "exit=$?"  # note: the suite name in AGENTS.md matches nothing
vendor/bin/phpunit --coverage-text                 # only if pcov/xdebug present
composer audit ; composer outdated --direct
gitleaks detect --no-banner -s .
actionlint
```

Aggregate these as `SWEEP-*` items (e.g. "add tests for classes X, Y, Z"), not
one row per file.

### 2. Batches (module clusters)

1. `src/IsoFile.php`, `src/Descriptor.php`, `src/Descriptor/Reader.php`, `Factory.php`, `Type.php`, `Exception.php`
2. `src/Descriptor/` volume descriptors: `PrimaryVolume`, `SupplementaryVolume`, `Volume`, `Boot`, `Partition`, `Terminator`
3. `src/Descriptor/Udf*.php`
4. `src/FileDirectory.php`, `src/PathTableRecord.php`
5. `src/Util/` (`Buffer`, `IsoDate`), `src/Exception.php`
6. `src/Cli/`, `bin/`, `tests/`, `fixtures/`, `.github/workflows/`, `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `composer.json`

For each batch, grep first and read only what a hit calls for. Useful probes:
`fread(` / `fseek(` without result checks, `unpack(` without length checks,
`substr(` on Buffer, `hexdec`/`ord` width assumptions, `while (true)`,
`catch (Exception` that swallows, `public` properties, `strict_types` missing,
`@` error suppression, `DateTime`/`Carbon` timezone handling, `?->` / null
returns that callers don't handle. Check each claim against the code before
recording it.

Finding schema (one JSON array per batch):

```json
{"t":"Short imperative summary","cat":"core|descriptor|udf|directory|util|cli|ci","l":"bug|robustness|ux|feature|test","f":["src/x.php:12-30"],"e":"S|M|L","i":"low|med|high","r":"One sentence why it matters.","mode":"single|sweep"}
```

`e`: S = one file, under 30 min. M = one class plus tests. L = cross-cutting.
`i`: high = wrong results / crash / hang on valid or hostile ISOs. med = common path. low = nice-to-have.

### 3. Dedupe & prioritize

Merge the batch files. Collapse duplicates. Promote anything that spans three or
more classes to `SWEEP-*`. Rank high-impact/low-effort first. Compare against the
previous backlog (`git log -- docs/improvement-audit/`) so already-fixed items are
not re-reported. If the total is below X, redo the thinnest batches.

### 4. Write the backlog

`docs/improvement-audit/YYYY-MM-DD-improvement-backlog.md`. Use stable IDs
(`SWEEP-NN`, `BUG-NN`, `ROB-NN`, `UX-NN`, `FEAT-NN`), make every item a `- [ ]`,
and reuse the header, summary table and execution-instructions layout of the
previous round if one exists. Commit it with no source changes and delete the
scratch batch files.

## Done criteria

- [ ] ≥ X deduplicated items, each with an ID, checkbox, `file:line`, effort, impact and rationale
- [ ] Mechanical gaps aggregated as sweep items
- [ ] No duplicates against each other or against previously completed backlogs
- [ ] Report written under `docs/improvement-audit/`; no source changes
