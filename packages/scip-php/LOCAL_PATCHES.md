# Local `scip-php` Patches

This repository vendors `davidrjenni/scip-php` under `packages/scip-php` and carries local patches on top of upstream.

Do not replace or update this vendored copy blindly. If upstream is pulled in later, review and port the local patches deliberately.

Current local patches include:

- parser-path performance work for SCIP compilation
- request-scoped caches in the vendored indexer runtime
- nested-vendor bootstrap hardening for local use in this repository
- a local fix path for upstream issue `davidrjenni/scip-php#235`

If you need to refresh from upstream, treat this directory as a fork and re-validate compile output and performance before merging the update.
