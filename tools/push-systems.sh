#!/usr/bin/env bash
# Brings the systems Ticket Worker reads up to date, from your own clones.
#
# Ticket Worker cannot reach GitLab (it sits behind the VPN), so this runs on the
# machine that can: it fetches each system's branch from origin (GitLab, VPN on)
# and pushes it to the clone's "ticket-worker" remote (the server, SSH).
#
#   push-systems.sh [fetch|push|both] [folder ...]
#
# Every git clone in the folders given, or in the folders directly below them, takes
# part when it has a "ticket-worker" remote. One-time setup per clone:
#
#   git remote add ticket-worker kermmeer@minas:/data/apps/ticket-worker-dev/shared/systems/billing
#   git config ticket-worker.branch main        # optional; main is the default
#
# If the VPN cuts you off from the server, run "fetch" while connected and "push"
# after disconnecting: the push sends what the last fetch brought in.
#
# The push mirrors GitLab: if a branch there was rewritten, the server follows it.

set -u

mode=both
case "${1:-}" in
    fetch | push | both) mode=$1; shift ;;
esac
[ $# -eq 0 ] && set -- .

failed=0
seen=0

for root in "$@"; do
    for repo in "$root" "$root"/*/; do
        [ -e "$repo/.git" ] || continue
        git -C "$repo" remote get-url ticket-worker >/dev/null 2>&1 || continue
        seen=$((seen + 1))
        name=$(basename "$(cd "$repo" && pwd)")
        branch=$(git -C "$repo" config --get ticket-worker.branch || echo main)

        if [ "$mode" != push ]; then
            if ! git -C "$repo" fetch --quiet origin "+refs/heads/$branch:refs/remotes/origin/$branch"; then
                echo "$name: could not fetch $branch from origin. Is the VPN on?" >&2
                failed=1
                continue
            fi
        fi

        if [ "$mode" != fetch ]; then
            if ! git -C "$repo" push --quiet ticket-worker "+refs/remotes/origin/$branch:refs/heads/$branch"; then
                echo "$name: could not push $branch to ticket-worker. Can you reach the server?" >&2
                failed=1
                continue
            fi
        fi

        echo "$name: $branch at $(git -C "$repo" rev-parse --short "refs/remotes/origin/$branch") ($mode)"
    done
done

if [ "$seen" -eq 0 ]; then
    echo "No clone with a ticket-worker remote in: $*" >&2
    exit 1
fi

exit "$failed"
