# Getting Started

Install the wrapper globally:

```bash
composer global require maxiviper117/composer-quarantine
```

That gives you the `composer-quarantine` command.

If you want to use it only in a specific project, install it as a dev dependency instead:

```bash
composer require --dev maxiviper117/composer-quarantine
```

That gives you `vendor/bin/composer-quarantine` in that repository or workbench.

Set your policy in the project root `composer.json` file, under `extra.composer-quarantine`:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 48
    }
  }
}
```

If you want the wrapper to write that block for you, run:

```bash
vendor/bin/composer-quarantine init
```

You can also set a global default in your Composer home directory at `COMPOSER_HOME/composer.json`:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 72,
      "max-suggested-versions-to-show": 20,
      "check-dependencies": false
    }
  }
}
```

On Windows, that is usually `C:\Users\<you>\AppData\Roaming\Composer\composer.json`.

Project-level config overrides the global default.

Then run the wrapper instead of raw Composer:

```bash
vendor/bin/composer-quarantine init
vendor/bin/composer-quarantine require symfony/console
```

What to expect:

- `vendor/bin/composer-quarantine init` writes the policy block into the current project's `composer.json`
- `vendor/bin/composer-quarantine init` sets `check-dependencies=false` by default so you can review explicit requests first
- the wrapper does a dry-run solve first
- it checks the planned versions against your age policy
- set `check-dependencies=false` if you only want to inspect and pin the packages you explicitly passed to `require` or `update`; Composer resolves transitive dependencies normally
- if a version is too new, it prompts you to choose a safe version
- it reruns Composer with exact version pins after you confirm

If the age policy blocks every version that satisfies the constraint, the wrapper stops and shows the safe versions it found. In that case you can:

```bash
vendor/bin/composer-quarantine require symfony/console --ignore-quarantine
```

Or temporarily lower the threshold / add the package to `ignored-packages` if the update is intentional.
