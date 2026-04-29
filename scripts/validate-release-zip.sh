#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_PATH="${1:-$ROOT_DIR/tool-kits.zip}"

if ! command -v unzip >/dev/null 2>&1; then
  echo "unzip is required to validate the release archive." >&2
  exit 1
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "Archive not found: $ZIP_PATH" >&2
  exit 1
fi

LISTING="$(unzip -Z1 "$ZIP_PATH")"

if [[ "$LISTING" != tool-kits/* ]]; then
  echo "Invalid archive root. Expected every entry to start with tool-kits/." >&2
  exit 1
fi

if ! grep -qx 'tool-kits/' <<<"$LISTING"; then
  echo "Missing root directory entry: tool-kits/" >&2
  exit 1
fi

if ! grep -qx 'tool-kits/tool-kits.php' <<<"$LISTING"; then
  echo "Missing main plugin file: tool-kits/tool-kits.php" >&2
  exit 1
fi

if ! grep -qx 'tool-kits/includes/classic-editor.php' <<<"$LISTING"; then
  echo "Missing required include: tool-kits/includes/classic-editor.php" >&2
  exit 1
fi

if ! grep -qx 'tool-kits/includes/classic-widgets.php' <<<"$LISTING"; then
  echo "Missing required include: tool-kits/includes/classic-widgets.php" >&2
  exit 1
fi

if ! grep -qx 'tool-kits/includes/general.php' <<<"$LISTING"; then
  echo "Missing required include: tool-kits/includes/general.php" >&2
  exit 1
fi

if grep -q '^__MACOSX/' <<<"$LISTING"; then
  echo "Archive contains unexpected __MACOSX metadata." >&2
  exit 1
fi

if grep -q '/\.DS_Store$' <<<"$LISTING"; then
  echo "Archive contains unexpected .DS_Store metadata." >&2
  exit 1
fi

echo "Release archive looks valid: $ZIP_PATH"
