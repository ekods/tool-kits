#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${1:-$ROOT_DIR/tool-kits.zip}"
STAGING_DIR="$(mktemp -d)"
PACKAGE_DIR_NAME="tool-kits"
VALIDATE_SCRIPT="$ROOT_DIR/scripts/validate-release-zip.sh"
METADATA_SCRIPT="$ROOT_DIR/scripts/check-release-metadata.sh"

cleanup() {
  rm -rf "$STAGING_DIR"
}
trap cleanup EXIT

if ! command -v git >/dev/null 2>&1; then
  echo "git is required to build the release package." >&2
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  echo "zip is required to build the release package." >&2
  exit 1
fi

if ! git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "The plugin directory is not inside a git work tree." >&2
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

PACKAGE_ROOT="$STAGING_DIR/$PACKAGE_DIR_NAME"
mkdir -p "$PACKAGE_ROOT"

is_release_excluded() {
  case "$1" in
    .DS_Store|.gitignore|.gitattributes|README.md|ROADMAP.md|tool-kits.zip|scripts/*)
      return 0
      ;;
  esac
  return 1
}

copy_release_file() {
  local path="$1"
  local source="$ROOT_DIR/$path"
  local target="$PACKAGE_ROOT/$path"
  local target_dir

  if [[ ! -f "$source" ]]; then
    echo "Release path is not a file: $path" >&2
    exit 1
  fi

  target_dir="$(dirname "$target")"
  mkdir -p "$target_dir"
  install -m 0644 "$source" "$target"
}

while IFS= read -r path; do
  if is_release_excluded "$path"; then
    continue
  fi

  copy_release_file "$path"
done < <(git -C "$ROOT_DIR" ls-files)

find "$PACKAGE_ROOT" -type d -exec chmod 0755 {} +
find "$PACKAGE_ROOT" -type f -exec chmod 0644 {} +

(
  cd "$STAGING_DIR"
  zip -X -qr "$OUTPUT_PATH" "$PACKAGE_DIR_NAME"
)

bash "$VALIDATE_SCRIPT" "$OUTPUT_PATH"

echo "Created clean release archive: $OUTPUT_PATH"
