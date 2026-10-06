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

plugin_header_php="$(
  sed -n 's/^ \* Requires PHP: //p' "$PLUGIN_FILE" | head -n1
)"

plugin_constant_version="$(
  sed -n "s/^define('TK_VERSION', '\([^']*\)');$/\1/p" "$PLUGIN_FILE" | head -n1
)"

plugin_constant_php="$(
  sed -n "s/^define('TK_MINIMUM_PHP', '\([^']*\)');$/\1/p" "$PLUGIN_FILE" | head -n1
)"

stable_tag_version="$(
  sed -n 's/^Stable tag: //p' "$README_FILE" | head -n1
)"

readme_php="$(
  sed -n 's/^Requires PHP: //p' "$README_FILE" | head -n1
)"

if [[ -z "$plugin_header_version" || -z "$plugin_constant_version" || -z "$stable_tag_version" || -z "$plugin_header_php" || -z "$plugin_constant_php" || -z "$readme_php" ]]; then
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

if [[ "$plugin_header_php" != "$plugin_constant_php" || "$plugin_header_php" != "$readme_php" ]]; then
  echo "PHP requirement mismatch: plugin header=$plugin_header_php, TK_MINIMUM_PHP=$plugin_constant_php, readme=$readme_php" >&2
  exit 1
fi

echo "Release metadata looks consistent: version $plugin_header_version, PHP >= $plugin_header_php"
