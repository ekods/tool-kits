#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${1:-$ROOT_DIR/tool-kits.zip}"
STAGING_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$STAGING_DIR"
}
trap cleanup EXIT

if ! command -v git >/dev/null 2>&1; then
  echo "git is required to build the release package." >&2
  exit 1
fi

if ! git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "The plugin directory is not inside a git work tree." >&2
  exit 1
fi

mkdir -p "$(dirname "$OUTPUT_PATH")"
rm -f "$OUTPUT_PATH"

PACKAGE_ROOT="$STAGING_DIR/tool-kits"
mkdir -p "$PACKAGE_ROOT"

while IFS= read -r path; do
  case "$path" in
    .DS_Store|.gitignore|.gitattributes|README.md)
      continue
      ;;
  esac

  target_dir="$PACKAGE_ROOT/$(dirname "$path")"
  mkdir -p "$target_dir"
  cp -R "$ROOT_DIR/$path" "$target_dir/"
done < <(git -C "$ROOT_DIR" ls-files)

(
  cd "$STAGING_DIR"
  zip -qr "$OUTPUT_PATH" tool-kits
)

echo "Created clean release archive: $OUTPUT_PATH"
