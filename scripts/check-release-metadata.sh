#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT_DIR/tool-kits.php"
README_FILE="$ROOT_DIR/readme.txt"

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "Main plugin file not found: $PLUGIN_FILE" >&2
  exit 1
fi

if [[ ! -f "$README_FILE" ]]; then
  echo "readme.txt not found: $README_FILE" >&2
  exit 1
fi

plugin_header_version="$(
  sed -n 's/^ \* Version: //p' "$PLUGIN_FILE" | head -n1
)"

plugin_constant_version="$(
  sed -n "s/^define('TK_VERSION', '\([^']*\)');$/\1/p" "$PLUGIN_FILE" | head -n1
)"

stable_tag_version="$(
  sed -n 's/^Stable tag: //p' "$README_FILE" | head -n1
)"

if [[ -z "$plugin_header_version" || -z "$plugin_constant_version" || -z "$stable_tag_version" ]]; then
  echo "Failed to read release metadata from plugin header or readme.txt." >&2
  exit 1
fi

if [[ "$plugin_header_version" != "$plugin_constant_version" ]]; then
  echo "Version mismatch: plugin header=$plugin_header_version, TK_VERSION=$plugin_constant_version" >&2
  exit 1
fi

if [[ "$plugin_header_version" != "$stable_tag_version" ]]; then
  echo "Version mismatch: plugin header=$plugin_header_version, Stable tag=$stable_tag_version" >&2
  exit 1
fi

echo "Release metadata looks consistent: version $plugin_header_version"
