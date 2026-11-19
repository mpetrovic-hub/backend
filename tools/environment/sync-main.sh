#!/usr/bin/env bash
set -euo pipefail

# Run at an idle task boundary. This does not install a background updater.
# Default: fetch and report. --update: additionally fast-forward a clean checkout.
mode=check
repo=
while (($#)); do
    case "$1" in
        --check) mode=check; shift ;;
        --update) mode=update; shift ;;
        --repo) repo=${2:?--repo requires a path}; shift 2 ;;
        *) printf 'Usage: %s [--check|--update] [--repo PATH]\n' "$0" >&2; exit 1 ;;
    esac
done
if [[ -z "$repo" ]]; then
    script_dir=$(cd -- "$(dirname -- "$0")" && pwd)
    repo=$(git -C "$script_dir" rev-parse --show-toplevel)
fi
repo=$(git -C "$repo" rev-parse --show-toplevel)

lock_path=$(git -C "$repo" rev-parse --path-format=absolute --git-path codex-main-sync.lock)
exec {lock_fd}>"$lock_path"
if ! flock -n "$lock_fd"; then
    printf 'status=blocked reason=sync_already_running\n'
    exit 2
fi

local_head=$(git -C "$repo" rev-parse HEAD)
branch=$(git -C "$repo" symbolic-ref -q --short HEAD || true)
dirty=false
if [[ -n "$(git -C "$repo" status --porcelain --untracked-files=all)" ]]; then dirty=true; fi
in_progress=false
for marker in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD rebase-merge rebase-apply BISECT_LOG; do
    if [[ -e "$(git -C "$repo" rev-parse --path-format=absolute --git-path "$marker")" ]]; then
        in_progress=true
    fi
done

# Fetch does not change checked-out files. Prevent interactive login prompts.
if ! GIT_TERMINAL_PROMPT=0 timeout 45s git -C "$repo" fetch --quiet --no-tags origin \
    +refs/heads/main:refs/remotes/origin/main; then
    printf 'status=failed reason=fetch_failed base_commit=%s\n' "$local_head"
    exit 1
fi
remote_head=$(git -C "$repo" rev-parse refs/remotes/origin/main)
read -r ahead behind <<< "$(git -C "$repo" rev-list --left-right --count "$local_head...$remote_head")"
printf 'mode=%s base_commit=%s github_main=%s ahead=%s behind=%s working_tree_dirty=%s\n' \
    "$mode" "$local_head" "$remote_head" "$ahead" "$behind" "$dirty"

if [[ "$local_head" == "$remote_head" ]]; then
    printf 'status=current\n'
    exit 0
fi
if [[ "$mode" == check ]]; then
    printf 'status=differs\n'
    exit 0
fi
if [[ -z "$branch" ]]; then
    printf 'status=blocked reason=detached_head\n'
    exit 2
fi
if [[ "$in_progress" == true ]]; then
    printf 'status=blocked reason=git_operation_in_progress\n'
    exit 2
fi
if [[ "$dirty" == true ]]; then
    printf 'status=blocked reason=local_changes\n'
    exit 2
fi
if ((ahead > 0)); then
    printf 'status=blocked reason=local_commits\n'
    exit 2
fi

# Recheck after network work. Never stash, reset, rebase, discard, or force-merge.
if [[ "$(git -C "$repo" rev-parse HEAD)" != "$local_head" \
    || "$(git -C "$repo" symbolic-ref -q --short HEAD || true)" != "$branch" \
    || -n "$(git -C "$repo" status --porcelain --untracked-files=all)" ]]; then
    printf 'status=blocked reason=checkout_changed_during_fetch\n'
    exit 2
fi
if ! git -C "$repo" merge --ff-only --no-edit "$remote_head"; then
    printf 'status=failed reason=fast_forward_failed\n'
    exit 1
fi
updated_head=$(git -C "$repo" rev-parse HEAD)
if [[ "$updated_head" != "$remote_head" ]]; then
    printf 'status=failed reason=updated_commit_mismatch\n'
    exit 1
fi
printf 'status=updated base_commit=%s\n' "$updated_head"
