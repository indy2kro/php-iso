# AGENTS.md - php-iso Development Guide

This guide helps coding agents work with the php-iso library effectively.

## Quick Commands

```bash
# Install dependencies
composer install
composer clear-cache

# Run all tests
./vendor/bin/phpunit --testsuite=" PhpIso Testing Suite"

# Run a single test
./vendor/bin/phpunit --filter "TestName" ./tests/SomeTest.php

# List available tests
./vendor/bin/phpunit --list-tests

# Static analysis
composer run cs:check      # PHP CS Fixer / PSR12
composer run phpstan       # PHPStan static analysis
```

## Code Style Guidelines

### General PHP Standards
- **PSR-12**: Follow PSR-12 coding standards
- **PHP 8.3+**: Target PHP 8.3 minimum, use modern features
- **Namespaces**: Use `PhpIso\` namespace prefix for all classes
- **File Structure**: One class/interface per file

### Import Organization
```php
<?php declare(strict_types=1);

namespace PhpIso;

use Carbon\CarbonImmutable;
use PhpIso\Exception;
use PhpIso\Descriptor\FileDataRecord;

// No trailing imports needed in this project format

function processData(array $files): array
{
    // Your code here
}
```

### Type Declarations
- Always use `declare(strict_types=1);` at file start
- Use union types: `string|int` instead of `?string|null`
- Return type hints for all functions and methods
- Property type hints on class properties

### Naming Conventions
| Element | Convention | Example |
|---------|------------|---------|
| Classes | CamelCase | `IsoFile`, `FileDirectory` |
| Constants | UPPER_SNAKE_CASE | `ISO_PATH_LENGTH` |
| Functions/methods | camelCase | `readDescriptor()`, `$filePath` |
| Interfaces | `InterfaceName` | `PathReaderInterface` |

### Error Handling
```php
function readIso(string $path): IsoFile
{
    try {
        return new IsoFactory()->create($path);
    } catch (Exception e) {
        throw e; // Re-throw or handle appropriately
    }
}
```

### Testing Rules
- Tests must be in `tests/` directory with `.php` suffix
- Use `PhpIso\Test\` namespace prefix
- Prefer unit tests over integration tests
- Each test method should validate exactly one behavior
- Avoid test duplication (DRY principle)

### Directory Structure
```
src/
  ├── Cli/         Command-line interface tools
  ├── Descriptor/  ISO descriptor objects
  ├── Util/        Utility classes
  ├── Exception.php Custom exceptions
  └── *.php        Main library classes
tests/             Test files following same structure as src/
fixtures/          ISO file fixtures for testing
```

### Code Organization Pattern
```php
<?php declare(strict_types=1);

namespace PhpIso; // Root namespace

// Use imports, no trailing `use` statement block

class FileDirectory
{
    public function __construct(
        protected string $name,
        protected ?string $location = null
    ) {}
}
```

### Carbon Usage
```php
use Carbon\CarbonImmutable; // Prefer immutable carbon instances
$date = CarbonImmutable::now()->setTimezone('UTC');
```

## Project Details

**Package**: `indy2kro/php-iso`  
**PHP Requirement**: ^8.3  
**License**: GPL-2.0-or-later

**Key Dependencies**:
- `nesbot/carbon`: Date/time handling
- PHPStan: Static analysis
- PHP CodeSniffer: Coding standards

**Development Tools**:
- PHPUnit ^11 or ^12 for testing
- Rector ^2 for refactoring
- PHP CS Fixer for style fixes

## Workflow Patterns

### Fixing an Issue
1. Find failing test: `composer run tests:failed`
2. Run with output: `vendor/bin/phpunit --no-coverage -v`
3. Fix code, verify locally
4. Commit with clear message
5. Run full test suite before pushing

### Adding a Feature
1. Create implementation in `src/`
2. Write corresponding tests in `tests/`
3. Add type hints and return types
4. Run `composer run cs:check` to verify style
5. Run static analysis with PHPStan
6. Commit changes

### Refactoring Safety
- Use PHPUnit test suite as safety net
- Rector rules are pre-configured for safe refactors
- Always ensure tests pass before committing