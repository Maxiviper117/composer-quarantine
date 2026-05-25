# Mental Model

Composer Quarantine does not block packages after installation.

It works earlier, during dependency resolution:

1. Composer builds a candidate pool
2. The plugin fetches Packagist release timestamps
3. Versions younger than the threshold are removed
4. Composer resolves against the remaining versions

That means the solver never sees the quarantined version as an available choice.
