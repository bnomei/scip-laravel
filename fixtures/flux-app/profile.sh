#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target_dir="$repo_root/fixtures/_worktrees/flux-app"

if [[ "${1:-}" != "" && "${1:0:1}" != "-" ]]; then
    target_dir="$1"
    shift
fi

"$repo_root/fixtures/flux-app/prepare-acceptance.sh" "$target_dir" > /dev/null
php "$repo_root/fixtures/flux-app/profile.php" "$target_dir" "$@"
