# Composer Quarantine

Composer Quarantine is a global Composer plugin that blocks freshly published package versions until they reach a configurable minimum age.

> [Documentation →](https://maxiviper117.github.io/composer-quarantine/) - setup, policy configuration, and operational notes.

## Install

```bash
composer global require maxiviper117/composer-quarantine
composer global config --no-plugins allow-plugins.maxiviper117/composer-quarantine true
```

## Configure

Project-level policy goes in the root `composer.json` file under `extra.composer-quarantine`:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 48,
      "packagist-request-interval-ms": 1000,
      "fail-open": true,
      "ignored-packages": [
        "symfony/*"
      ],
      "allow-dev": false,
      "allow-prerelease": false,
      "verbose": false
    }
  }
}
```

You can also set a global default in `COMPOSER_HOME/composer.json`, for example:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 72
    }
  }
}
```

Project config overrides the global default.

Environment override:

```bash
COMPOSER_QUARANTINE_MINIMUM_AGE_HOURS=72
COMPOSER_QUARANTINE_PACKAGIST_REQUEST_INTERVAL_MS=1000
```

Temporary bypass:

```bash
composer update --ignore-quarantine
```

## Using `composer require`

The plugin also affects normal install flows such as:

```bash
composer require symfony/console
```

Expected behavior:

- Composer searches only the versions that are old enough for your policy
- versions younger than `minimum-age-hours` are removed before resolution
- if an older compatible version exists, Composer installs that version instead

If every version that satisfies your constraint is still too new, the command fails. Use one of these options:

```bash
composer require symfony/console --ignore-quarantine
```

Or adjust policy for that package by lowering `minimum-age-hours` or adding it to `ignored-packages`.

## Behavior

The plugin:

- inspects candidate packages during dependency resolution
- fetches release timestamps from Packagist p2 metadata
- rate-limits Packagist requests and backs off on 429/503 responses
- removes versions younger than the configured threshold
- warns when a package is quarantined

If Packagist is unavailable and `fail-open` is enabled, the plugin warns and lets Composer continue.

## Notes

- Composer 2.6+ required
- PHP 8.3+ required
- In-memory cache only in the initial release
