# Configure Policy

Use the `extra.composer-quarantine` block in your root `composer.json`.

```json
{
  "extra": {
    "composer-quarantine": {
      "minimum-age-hours": 48,
      "fail-open": true,
      "ignored-packages": [
        "symfony/*"
      ],
      "allow-dev": false,
      "verbose": false,
      "allow-prerelease": false,
      "packagist-request-interval-ms": 1000
    }
  }
}
```

Environment overrides:

```bash
COMPOSER_QUARANTINE_MINIMUM_AGE_HOURS=72
COMPOSER_QUARANTINE_PACKAGIST_REQUEST_INTERVAL_MS=1000
```

Temporary bypass:

```bash
composer update --ignore-quarantine
```
