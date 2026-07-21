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

## Usage

```php
use OpenFeature\OpenFeatureAPI;
use Unleash\Client\UnleashBuilder;
use Unleash\OpenFeature\Provider\UnleashFlagProvider;

$builder = UnleashBuilder::create()
    ->withAppUrl('https://app.unleash-hosted.com/demo/api')
    ->withInstanceId('default:development.example-token')
    ->withAppName('my-php-app')
    ->withHeader('Authorization', 'default:development.example-token');

OpenFeatureAPI::getInstance()->setProvider(new UnleashFlagProvider($builder));
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
