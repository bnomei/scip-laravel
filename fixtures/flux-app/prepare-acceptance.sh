#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target_dir="${1:-$repo_root/fixtures/_worktrees/flux-app}"
lock_snapshot="$repo_root/fixtures/flux-app/acceptance-composer.lock"

if [ ! -f "$lock_snapshot" ]; then
    echo "Acceptance lock snapshot not found: $lock_snapshot" >&2
    exit 1
fi

SCIP_LARAVEL_SKIP_FIXTURE_INSTALL=1 "$repo_root/fixtures/flux-app/scaffold.sh" "$target_dir"

php "$repo_root/fixtures/flux-app/prepare-acceptance-composer.php" "$target_dir/composer.json" > /dev/null

cp "$lock_snapshot" "$target_dir/composer.lock"

php "$repo_root/fixtures/flux-app/materialize-acceptance.php" "$target_dir"

composer install --working-dir="$target_dir" --no-dev --no-interaction --no-progress

echo
echo "Flux acceptance fixture ready at: $target_dir"
