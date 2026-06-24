#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git -C "$(dirname "${BASH_SOURCE[0]}")/.." rev-parse --show-toplevel)"
PLUGIN_SLUG="$(basename "${ROOT_DIR}")"
OUTPUT_PATH="${1:-"${ROOT_DIR}/../${PLUGIN_SLUG}.zip"}"
VALIDATE_SCRIPT="$ROOT_DIR/scripts/validate-release-zip.sh"
METADATA_SCRIPT="$ROOT_DIR/scripts/check-release-metadata.sh"
TEMP_INDEX="$(mktemp)"

cleanup() {
  rm -f "$TEMP_INDEX"
}
trap cleanup EXIT

if ! command -v git >/dev/null 2>&1; then
  echo "git is required to build the release package." >&2
  exit 1
fi

if [[ ! -x "$METADATA_SCRIPT" && ! -f "$METADATA_SCRIPT" ]]; then
  echo "Release metadata checker not found: $METADATA_SCRIPT" >&2
  exit 1
fi

if [[ ! -x "$VALIDATE_SCRIPT" && ! -f "$VALIDATE_SCRIPT" ]]; then
  echo "Release ZIP validator not found: $VALIDATE_SCRIPT" >&2
  exit 1
fi

bash "$METADATA_SCRIPT"

mkdir -p "$(dirname "$OUTPUT_PATH")"
rm -f "$OUTPUT_PATH"

GIT_INDEX_FILE="$TEMP_INDEX" git -C "$ROOT_DIR" read-tree HEAD
GIT_INDEX_FILE="$TEMP_INDEX" git -C "$ROOT_DIR" add -A
TREE_SHA="$(GIT_INDEX_FILE="$TEMP_INDEX" git -C "$ROOT_DIR" write-tree)"

git -C "$ROOT_DIR" archive \
  --format=zip \
  --worktree-attributes \
  --prefix="${PLUGIN_SLUG}/" \
  --output="$OUTPUT_PATH" \
  "$TREE_SHA"

bash "$VALIDATE_SCRIPT" "$OUTPUT_PATH"

echo "Created $OUTPUT_PATH"
