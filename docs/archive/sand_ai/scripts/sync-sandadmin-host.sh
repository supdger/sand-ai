#!/bin/zsh
set -euo pipefail

# SandAdmin is the authoritative host. SandAI owns only the exclusions below.
# This is deliberately consumer-pulled and one-way: SandAdmin never pushes into
# this workspace, and SandAI never pushes host changes upstream.

sync_root="${0:A:h:h}"
source_root="${SANDADMIN_HOST_SOURCE:-${sync_root:h}/sandadmin}"
apply=false
allow_dirty=false

for argument in "$@"; do
  case "$argument" in
    --dry-run) apply=false ;;
    --apply) apply=true ;;
    --allow-dirty) allow_dirty=true ;;
    *) print -u2 "Unknown argument: $argument"; exit 64 ;;
  esac
done

[[ -d "$source_root/.git" ]] || { print -u2 "SandAdmin source is not a Git checkout: $source_root"; exit 2; }
[[ -f "$source_root/server/composer.json" && -f "$source_root/sandadmin-artd/package.json" ]] || {
  print -u2 "SandAdmin source is incomplete: $source_root"; exit 2;
}

host_state=clean
if [[ -n "$(git -C "$source_root" status --porcelain)" ]]; then
  host_state=dirty-local
  $allow_dirty || { print -u2 "SandAdmin source is dirty; use a clean published revision, or --allow-dirty for non-acceptance exploration."; exit 3; }
fi

rsync_arguments=(-a --delete-delay --itemize-changes)
$apply || rsync_arguments+=(--dry-run)

# These are SandAI application-owned files. A host change in any excluded file
# must be reconciled deliberately; it must never be silently overwritten.
server_excludes=(
  '--exclude=.DS_Store' '--exclude=.env' '--exclude=.env.*' '--exclude=vendor/' '--exclude=runtime/'
  '--exclude=app/Admin/***' '--exclude=app/Api/***' '--exclude=app/Application/***'
  '--exclude=app/Identity/***' '--exclude=app/Infrastructure/***' '--exclude=app/sandAi/***'
  '--exclude=app/process/TaskWorker.php' '--exclude=config/route.php'
  '--exclude=config/process.php' '--exclude=config/container.php' '--exclude=config/sand_ai_*.php'
  '--exclude=database/***'
  '--exclude=tests/SandAi/***' '--exclude=tests/SandAiPlugin/***' '--exclude=tests/fixtures/***'
  '--exclude=scripts/sand-ai-*' '--exclude=scripts/audit-sand-ai-plugin-package.php'
  '--exclude=scripts/provision-glm-deployment.php' '--exclude=scripts/paddleocr_service.py'
  '--exclude=scripts/start-paddleocr.sh' '--exclude=sand-ai-acceptance.env.example'
  '--exclude=plugin/sand-*/***' '--exclude=plugin/sandworkflow/***'
  '--exclude=.artifacts/' '--exclude=.playwright-cli/'
)
frontend_excludes=(
  '--exclude=.DS_Store' '--exclude=.env' '--exclude=.env.*' '--exclude=node_modules/' '--exclude=dist/'
  '--exclude=.auto-import.json' '--exclude=src/types/import/'
  '--exclude=src/views/plugin/sand-*/***' '--exclude=src/views/sand-ai/***'
  '--exclude=src/views/plugin/sandworkflow/***' '--exclude=src/router/modules/sand-ai.ts'
  '--exclude=.artifacts/' '--exclude=.playwright-cli/'
)

print "Synchronizing SandAdmin host from $source_root"
rsync "${rsync_arguments[@]}" "${server_excludes[@]}" "$source_root/server/" "$sync_root/server/"
rsync "${rsync_arguments[@]}" "${frontend_excludes[@]}" "$source_root/sandadmin-artd/" "$sync_root/sandadmin-artd/"

if ! $apply; then
  print "Dry run only: no files were changed. Re-run with --apply after review and authorization."
  exit 0
fi

host_revision="$(git -C "$source_root" rev-parse HEAD)"
host_ref="$(git -C "$source_root" describe --tags --exact-match 2>/dev/null || print "commit:$host_revision")"
cat > "$sync_root/sandadmin-host.lock" <<EOF
format=1
consumer=sand_ai
source_revision=$host_revision
source_ref=$host_ref
source_state=$host_state
synced_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
target=embedded
EOF
print "SandAdmin host synchronized at $host_revision ($host_state)."
