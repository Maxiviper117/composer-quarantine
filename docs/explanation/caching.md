# Caching

The plugin keeps an in-memory cache for the duration of a Composer run.

That cache stores Packagist metadata by package name and stability mode so repeated lookups do not hit the network again.

The initial scope does not persist cache between runs.
