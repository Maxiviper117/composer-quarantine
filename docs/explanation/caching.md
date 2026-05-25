# Caching

The wrapper keeps an in-memory cache for the duration of one wrapper run.

That cache stores Packagist metadata by package name and stability mode so repeated lookups do not hit the network again.

The initial scope does not persist cache between runs.
