# Unleash OpenFeature PHP Provider

The official Unleash OpenFeature provider for PHP.

## Requirements

- PHP 8.4+
- Composer

## Install

```bash
composer require unleash/openfeature-provider
```

For local development:

```bash
composer install
```

If you cloned without submodules, initialize the verifier harness:

```bash
git submodule update --init --recursive
```

## Build

```bash
composer build
```

## Test

```bash
composer test
```

The contract tests use the `verifier` submodule. To refresh it:

```bash
git submodule update --remote --merge verifier
```

## Lint

```bash
composer lint
composer format
```
