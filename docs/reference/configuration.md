# Configuration Reference

Composer Quarantine reads policy from three places, in this order:

1. `COMPOSER_HOME/composer.json`
1. Environment variables
1. The current project root `composer.json`

Project config wins over global config. Environment variables win over global config, but project config still overrides both.

## Policy Settings

### `minimum-age-hours`

- Type: integer
- Default: `48`
- Meaning: the minimum age a version must reach before it is considered safe to install

Use this to block freshly published releases. For example, `72` means a version must be at least three days old before the wrapper will allow it.

### `fail-open`

- Type: boolean
- Default: `true`
- Meaning: if Packagist metadata cannot be fetched, continue instead of hard failing

Keep this enabled if you want local and CI installs to keep moving when Packagist is unavailable. Set it to `false` if you want metadata retrieval failures to stop the run.

### `ignored-packages`

- Type: array
- Default: `[]`
- Meaning: package name patterns that should never be quarantined

This accepts exact names or globs. Examples:

```json
{
  "ignored-packages": [
    "symfony/*",
    "laravel/installer"
  ]
}
```

Use it for packages you trust or for internal exceptions you do not want the age policy to touch.

### `allow-dev`

- Type: boolean
- Default: `false`
- Meaning: allow dev branches and other development-only versions

Leave this off for normal safety policy enforcement. Turn it on only if you intentionally want to permit dev releases.

### `allow-prerelease`

- Type: boolean
- Default: `false`
- Meaning: allow alpha, beta, RC, and other prerelease versions

When this is `false`, prerelease versions are treated as unsafe even if they are old enough.

### `verbose`

- Type: boolean
- Default: `false`
- Meaning: print detailed diagnostic output

When enabled, the wrapper and metadata fetcher emit extra logs such as:
- Packagist fetches
- cache hits
- rate limiter waits
- blocked versions
- resolved policy values

### `packagist-request-interval-ms`

- Type: integer
- Default: `1000`
- Meaning: minimum spacing between Packagist requests in milliseconds

This is a local rate limiter. Lower values make the wrapper faster but increase the request rate. Higher values reduce request pressure but slow down the run.

### `max-suggested-versions-to-show`

- Type: integer
- Default: `20`
- Meaning: how many allowed versions to show in the interactive picker

If a package has many safe fallback versions, this caps the list to keep the prompt readable.

### `check-dependencies`

- Type: boolean
- Default: `false`
- Meaning: whether to inspect transitive dependencies in addition to the packages you explicitly requested

When `false`, the wrapper only checks and pins the package names you explicitly passed to `require` or `update`. Composer then resolves transitive dependencies normally.

Turn this on if you want the wrapper to quarantine the full planned dependency graph instead of just the explicitly requested packages.

## Example Project Config

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 48,
      "fail-open": true,
      "ignored-packages": ["symfony/*"],
      "allow-dev": false,
      "allow-prerelease": false,
      "verbose": false,
      "packagist-request-interval-ms": 1000,
      "max-suggested-versions-to-show": 20,
      "check-dependencies": false
    }
  }
}
```

## Environment Variables

```bash
COMPOSER_QUARANTINE_MINIMUM_AGE_HOURS=72
COMPOSER_QUARANTINE_FAIL_OPEN=true
COMPOSER_QUARANTINE_ALLOW_DEV=false
COMPOSER_QUARANTINE_ALLOW_PRERELEASE=false
COMPOSER_QUARANTINE_VERBOSE=true
COMPOSER_QUARANTINE_PACKAGIST_REQUEST_INTERVAL_MS=1000
COMPOSER_QUARANTINE_MAX_SUGGESTED_VERSIONS_TO_SHOW=20
COMPOSER_QUARANTINE_CHECK_DEPENDENCIES=false
COMPOSER_QUARANTINE_IGNORED_PACKAGES=symfony/*,laravel/installer
```

## Initial Setup

Run this to scaffold the policy block into the current project:

```bash
vendor/bin/composer-quarantine init
```

By default, `init` writes a conservative local policy with `check-dependencies` set to `false`, so you can start by reviewing the requested package before expanding to the full dependency tree.

## Temporary Bypass

```bash
vendor/bin/composer-quarantine update --ignore-quarantine
```

Use this when you need to override the wrapper intentionally for a specific run.
