# Composer Quarantine

Composer Quarantine is a Composer wrapper that checks release age before it hands work back to Composer.

It does a dry-run first, checks Packagist release timestamps, prompts you for safe versions, then reruns Composer with exact version pins.

> [Documentation →](https://maxiviper117.github.io/composer-quarantine/) - setup, policy configuration, and operational notes.

## Install

Global install:

```bash
composer global require maxiviper117/composer-quarantine
```

That gives you the `composer-quarantine` command on your Composer bin path.

Local project install:

```bash
composer require --dev maxiviper117/composer-quarantine
```

Use the local install if you want `vendor/bin/composer-quarantine` in a specific repository or workbench. This package is a CLI tool, so local usage should normally be a dev dependency.

If you install it globally, run `composer-quarantine ...`; if you install it in a project, run `vendor/bin/composer-quarantine ...`.

## Run

```bash
vendor/bin/composer-quarantine init
vendor/bin/composer-quarantine require guzzlehttp/guzzle
vendor/bin/composer-quarantine update
```

The wrapper:

1. can scaffold `extra.composer-quarantine` into your `composer.json`
2. runs a dry-run solve
3. reads the planned versions
4. checks each candidate against your minimum age policy
5. prompts you to choose a safe version when needed
6. reruns Composer with exact version pins

## Configure

Initialize the policy block in the current project:

```bash
vendor/bin/composer-quarantine init
```

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
      "max-suggested-versions-to-show": 20,
      "check-dependencies": false,
      "verbose": false
    }
  }
}
```

`vendor/bin/composer-quarantine init` writes `check-dependencies` as `false` by default, so you can start by reviewing the package you explicitly asked for before widening to transitive dependencies.

You can also set a global default in `COMPOSER_HOME/composer.json`.
Project config overrides the global default.

Environment overrides:

```bash
COMPOSER_QUARANTINE_MINIMUM_AGE_HOURS=72
COMPOSER_QUARANTINE_PACKAGIST_REQUEST_INTERVAL_MS=1000
```

## What to expect

- the wrapper never edits `composer.json` on your behalf
- blocked versions are shown before Composer runs for real
- the interactive picker shows a capped list with each version's age
- set `check-dependencies` to `false` if you only want to inspect and pin the packages you explicitly requested; Composer will resolve transitive dependencies normally
- if you choose a safe version, the rerun uses exact version constraints
- if Packagist is unavailable and `fail-open` is enabled, the wrapper continues

## Temporary bypass

```bash
vendor/bin/composer-quarantine update --ignore-quarantine
```

## Notes

- Composer 2.6+ required
- PHP 8.3+ required
- in-memory cache only in the initial release
