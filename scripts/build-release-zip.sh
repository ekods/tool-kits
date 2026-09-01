#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="$(basename "${ROOT_DIR}")"
OUTPUT_PATH="${1:-"${ROOT_DIR}/../${PLUGIN_SLUG}.zip"}"
if [[ "$OUTPUT_PATH" != /* ]]; then
  OUTPUT_PATH="$(pwd)/$OUTPUT_PATH"
fi
VALIDATE_SCRIPT="$ROOT_DIR/scripts/validate-release-zip.sh"
METADATA_SCRIPT="$ROOT_DIR/scripts/check-release-metadata.sh"
TEMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

if ! command -v zip >/dev/null 2>&1; then
  echo "zip is required to build the release package." >&2
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

mkdir -p "$TEMP_DIR/$PLUGIN_SLUG"

rsync -a "$ROOT_DIR/" "$TEMP_DIR/$PLUGIN_SLUG/" \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  --exclude '.github' \
  --exclude '.DS_Store' \
  --exclude 'README.md' \
  --exclude 'ROADMAP.md' \
  --exclude 'scripts' \
  --exclude 'tool-kits.zip'

(cd "$TEMP_DIR" && zip -qr "$OUTPUT_PATH" "$PLUGIN_SLUG")

bash "$VALIDATE_SCRIPT" "$OUTPUT_PATH"

echo "Created $OUTPUT_PATH"
