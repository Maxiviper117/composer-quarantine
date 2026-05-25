# Configure Policy

Composer Quarantine reads policy from three places:

1. `COMPOSER_HOME/composer.json`
1. environment variables
1. the project root `composer.json`

Use the reference page for the full meaning of each setting. This guide shows where to put the config and how the pieces work together.

## 1. Start With `init`

If you want a working baseline in the current project, run:

```bash
vendor/bin/composer-quarantine init
```

That writes an `extra.composer-quarantine` block into the current project’s `composer.json`.

By default, `init` writes:
- `minimum-age-hours: 48`
- `fail-open: true`
- `ignored-packages: []`
- `allow-dev: false`
- `allow-prerelease: false`
- `verbose: false`
- `packagist-request-interval-ms: 1000`
- `max-suggested-versions-to-show: 20`
- `check-dependencies: false`

That default is intentional:
- it lets you review the package you explicitly requested first
- it keeps dependency resolution lighter on first use

## 2. Project-Level Policy

Put project policy in the root `composer.json` file under `extra.composer-quarantine`.

Example:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 72,
      "fail-open": true,
      "ignored-packages": [
        "symfony/*",
        "laravel/installer"
      ],
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

Project policy is the right place when:
- one repository needs a stricter or looser rule than your machine default
- a team wants a shared rule checked into the repo
- you want the policy to travel with the codebase

## 3. Global Default Policy

You can define a user-level default in `COMPOSER_HOME/composer.json`.

On Windows this is usually:

```txt
C:\Users\<you>\AppData\Roaming\Composer\composer.json
```

Example:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 72,
      "fail-open": true,
      "ignored-packages": [],
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

Use a global default when:
- you want every Composer project on your machine to inherit the same baseline
- you want personal preferences without editing every repo

Project config always wins over the global default.

## 4. Environment Overrides

Environment variables are useful for CI or one-off local overrides.

Examples:

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

Use env vars when:
- you want CI to enforce a different policy than developers
- you need a temporary override without editing JSON
- you want to script behavior in a shell profile or pipeline

## 5. Recommended Patterns

### Local workbench or personal repo

Use `vendor/bin/composer-quarantine` and keep `check-dependencies=false` if you want to review the package you explicitly requested first.

### Team repository

Commit a project-level `extra.composer-quarantine` block so everyone gets the same policy.

### CI

Use environment variables or checked-in project config. If you want strict enforcement, set:
- `fail-open: false`
- `verbose: true` only if you need logs
- `check-dependencies: true` if you want the full dependency graph inspected

## 6. Temporary Bypass

If you need to override quarantine for one run:

```bash
vendor/bin/composer-quarantine update --ignore-quarantine
```

Use this sparingly. It bypasses the wrapper’s age policy for that command only.

## 7. What To Edit First

If you are not sure where to put a setting:
- start with the current project `composer.json`
- use `init` to generate the block
- move broader defaults into `COMPOSER_HOME/composer.json` later if you want machine-wide behavior

## 8. Read The Full Setting Guide

For the exact meaning of every option, see the [Configuration Reference](../reference/configuration).
