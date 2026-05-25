# Configuration Reference

Supported options:

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `minimum-age-hours` | integer | `48` | Minimum package age before the version is eligible |
| `fail-open` | boolean | `true` | Allow installs when Packagist is unavailable |
| `ignored-packages` | array | `[]` | Package name globs to skip quarantine checks |
| `allow-dev` | boolean | `false` | Allow dev versions |
| `verbose` | boolean | `false` | Print detailed diagnostics |
| `allow-prerelease` | boolean | `false` | Allow alpha, beta, and rc versions |
| `packagist-request-interval-ms` | integer | `1000` | Minimum spacing between Packagist requests |

Policy sources, in order:

1. Global Composer home config at `COMPOSER_HOME/composer.json`
1. Environment variables
1. The project root `composer.json`

Global bypass:

```bash
composer update --ignore-quarantine
```
