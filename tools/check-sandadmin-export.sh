#!/usr/bin/env bash
set -euo pipefail

source_root="${SAND_AI_PLUGIN_SOURCE:-/Users/code/project/sand_plugins/sand-ai}"
sandadmin_root="${SANDADMIN_ROOT:-/Users/code/project/sandadmin}"
export_root="${sandadmin_root}/plugins/sand-ai"

if [[ ! -d "${source_root}" ]]; then
  echo "SandAI plugin source not found: ${source_root}" >&2
  exit 2
fi

if [[ ! -d "${export_root}" ]]; then
  echo "SandAdmin plugin export not found: ${export_root}" >&2
  exit 2
fi

diff -ru --exclude='.DS_Store' "${source_root}" "${export_root}"
echo "SandAI plugin source and SandAdmin export are identical."
