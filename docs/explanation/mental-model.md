# Mental Model

Composer Quarantine does not modify your package manifest.

It works in two phases:

1. The wrapper runs a dry-run Composer solve
2. It fetches Packagist release timestamps for the planned versions
3. It flags any versions younger than the threshold
4. You pick a safe version in the wrapper prompt
5. The wrapper reruns Composer with exact version pins

That means the final install is explicit, not a silent fallback chosen by the solver.
