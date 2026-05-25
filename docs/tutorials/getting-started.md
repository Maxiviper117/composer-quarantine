# Getting Started

Install the plugin globally:

```bash
composer global require maxiviper117/composer-quarantine
composer global config --no-plugins allow-plugins.maxiviper117/composer-quarantine true
```

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

You can also set a global default in your Composer home directory at `COMPOSER_HOME/composer.json`:

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 72
    }
  }
}
```

On Windows, that is usually `C:\Users\<you>\AppData\Roaming\Composer\composer.json`.

Project-level config overrides the global default.

Then install packages as usual:

```bash
composer require symfony/console
```

What to expect:

- Composer resolves the package against the versions that are old enough
- freshly published versions are removed from the solver candidate pool
- if a package has an older compatible release, Composer will select that instead

If the age policy blocks every version that satisfies the constraint, `composer require` fails. In that case you can:

```bash
composer require symfony/console --ignore-quarantine
```

Or temporarily lower the threshold / add the package to `ignored-packages` if the update is intentional.
