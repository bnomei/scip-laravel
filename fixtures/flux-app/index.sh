#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target_dir="${1:-$repo_root/fixtures/_worktrees/flux-app}"
output_path="${2:-$target_dir/build/flux-app.scip}"

if [ ! -f "$target_dir/vendor/autoload.php" ]; then
    "$repo_root/fixtures/flux-app/scaffold.sh" "$target_dir"
fi

php "$repo_root/bin/scip-laravel" --output="$output_path" "$target_dir"
