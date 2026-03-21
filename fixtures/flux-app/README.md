# Flux App Testbed

This testbed materializes a pinned local checkout of [`livewire/flux-app`](https://github.com/livewire/flux-app) under `fixtures/_worktrees/flux-app` by default.

The scaffold rewrites the cloned app's `composer.json` into a public-installable profile:

- removes the repo-local path repositories
- replaces the private `livewire/flux-pro` dependency with public `livewire/flux:^1.0.2`
- pins `nikic/php-parser` to a version compatible with baseline indexing
- drops `require-dev` so the fixture can be installed with `--no-dev` on CI
- uses the repo-owned `acceptance-composer.lock` snapshot for acceptance preparation

That keeps the app bootable for local `scip-laravel` validation without relying on adjacent private repositories.
The checkout is pinned to commit `f356beac8797afcc8ee8a629ad8f4cde94ac0fd7`.

## Prepare

```bash
./fixtures/flux-app/scaffold.sh
```

## Index

```bash
./fixtures/flux-app/index.sh
```

## Profile

```bash
./fixtures/flux-app/profile.sh --warmup=1 --iterations=5
```

That command prepares the acceptance fixture, runs repeated in-process indexing passes, and prints per-bucket medians for the full Flux fixture.

## Profile

```bash
./fixtures/flux-app/profile.sh --repeats=5 --warmups=1
```

That command prepares the full acceptance-flavored Flux fixture, runs several timed indexing passes, and prints median bucket timings for the main pipeline stages and enrichers.

## Prepare Acceptance Fixture

```bash
./fixtures/flux-app/prepare-acceptance.sh
```

That command:

- ensures the pinned Flux checkout exists
- normalizes the app to a public-installable profile
- applies the repo-owned acceptance lock snapshot before install
- materializes the repo-owned acceptance probes used by the PHPUnit suite

To use a different checkout directory:

```bash
./fixtures/flux-app/scaffold.sh /tmp/flux-app-testbed
./fixtures/flux-app/index.sh /tmp/flux-app-testbed /tmp/flux-app-testbed/build/index.scip
```
