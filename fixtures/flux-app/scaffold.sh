#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target_dir="${1:-$repo_root/fixtures/_worktrees/flux-app}"
source_url="https://github.com/livewire/flux-app.git"
source_ref="f356beac8797afcc8ee8a629ad8f4cde94ac0fd7"
skip_install="${SCIP_LARAVEL_SKIP_FIXTURE_INSTALL:-0}"

if [ -e "$target_dir" ] && [ ! -d "$target_dir/.git" ]; then
    echo "Target exists but is not a git checkout: $target_dir" >&2
    exit 1
fi

mkdir -p "$(dirname "$target_dir")"

if [ ! -d "$target_dir/.git" ]; then
    git clone --depth=1 "$source_url" "$target_dir"
    git -C "$target_dir" fetch --depth=1 origin "$source_ref"
    git -C "$target_dir" checkout --quiet "$source_ref"
fi

php "$repo_root/fixtures/flux-app/normalize-composer.php" "$target_dir/composer.json"

if [ "$skip_install" != "1" ] && [ ! -f "$target_dir/vendor/autoload.php" ]; then
    composer update livewire/flux --working-dir="$target_dir" --with-all-dependencies --no-dev --no-interaction --no-progress
fi

echo
echo "Flux app testbed ready at: $target_dir"
echo "Run the indexer with:"
echo "  $repo_root/fixtures/flux-app/index.sh \"$target_dir\""
