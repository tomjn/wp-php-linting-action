# WordPress PHP Linting Action & Metapackage

A WordPress PHP linting solution providing:
- **GitHub Action** for CI/CD automation
- **Composer package** for local development

## Linting Checks

| Check | Tool | Description |
|-------|------|-------------|
| PHP Syntax | parallel-lint | Validates PHP syntax across all files |
| Coding Standards | PHPCS + WPCS v3 | WordPress Coding Standards compliance |
| Static Analysis | PHPStan | Type checking at configurable level |
| Dependency Diff | composer-diff | Shows package changes in PRs |

## GitHub Action Usage

### Basic Usage

```yaml
name: PHP Linting

on: [push, pull_request]

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: tomjn/wp-php-linting-action@main
```

### With Configuration

```yaml
- uses: tomjn/wp-php-linting-action@main
  with:
    php-version: '8.2'
    run-syntax: 'true'
    run-phpcs: 'true'
    run-phpstan: 'true'
    phpstan-level: '0'
    content-path: 'wp-content'
```

### Available Inputs

| Input | Default | Description |
|-------|---------|-------------|
| `token` | `github.token` | GitHub token (PAT required if action repo is private) |
| `php-version` | `8.2` | PHP version to use |
| `run-syntax` | `true` | Run PHP syntax checks |
| `run-phpcs` | `true` | Run PHPCS with WPCS |
| `run-phpstan` | `true` | Run PHPStan analysis |
| `phpstan-level` | `0` | PHPStan strictness (0-9) |
| `run-composer-diff` | `true` | Show dependency changes in PRs |
| `content-path` | `wp-content` | Path to content folder |
| `config-file` | `.github/wp-lint.yml` | Path to config file |
| `extra-phpcs-args` | `''` | Additional PHPCS arguments |
| `extra-phpstan-args` | `''` | Additional PHPStan arguments |

### Private Action Repository

If this action's repository is private, you must provide a PAT with repo access:

```yaml
- uses: tomjn/wp-php-linting-action@main
  with:
    token: ${{ secrets.LINT_ACTION_PAT }}
```

### Configuration File

Create `.github/wp-lint.yml` to configure without modifying your workflow:

```yaml
content-path: content
run-syntax: true
run-phpcs: true
run-phpstan: false
phpstan-level: 0
```

## Composer Package Usage

### Installation

```bash
composer require --dev tomjn/wp-php-linting-action
```

### Available Scripts

After installation, these scripts are available:

```bash
# Run all linting checks
composer run lint

# Individual checks
composer run lint:php      # PHP syntax
composer run lint:phpcs    # Coding standards
composer run lint:phpstan  # Static analysis

# Fix auto-fixable issues
composer run lint:phpcs:fix
```

### How Script Injection Works

This package uses a Composer plugin to inject linting scripts into your project. Scripts are only injected if:

1. The package matches the allow pattern (default: `tomjn/*`)
2. Your project doesn't already define the same script name
3. The script is explicitly listed in `shared-scripts`

#### Collision Handling

If your project already defines a script with the same name, the default behavior (`keep-root`) preserves your script. You can configure this in your `composer.json`:

```json
{
    "extra": {
        "shared-scripts": {
            "default-collision": "keep-root"
        }
    }
}
```

Options:
- `keep-root` - Your scripts take priority (default)
- `chain` - Run both scripts in sequence
- `replace-nonroot` - Package scripts take priority

## Requirements

- PHP 7.4+
- Composer 2.0+

## License

GPL-3.0-or-later
