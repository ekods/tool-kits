#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
if [[ -f "$ROOT_DIR/tool-kits.php" ]]; then
  PLUGIN_SLUG="tool-kits"
  SKIP_SYNC="${RELEASE_SKIP_SYNC:-${TK_SKIP_SYNC:-0}}"
elif [[ -f "$ROOT_DIR/custom-fields-framework-pro.php" ]]; then
  PLUGIN_SLUG="custom-fields-framework-pro"
  SKIP_SYNC="${RELEASE_SKIP_SYNC:-${CFFP_SKIP_SYNC:-0}}"
else
  echo 'Main plugin file not found.' >&2
  exit 1
fi

# The workspace shares dist/<slug>; an independent checkout uses its own dist/.
if [[ -f "$ROOT_DIR/../../artisan" ]]; then
  DEFAULT_DIST="$ROOT_DIR/../../dist/$PLUGIN_SLUG"
else
  DEFAULT_DIST="$ROOT_DIR/dist"
fi
DIST_DIR="${RELEASE_DIST_DIR:-$DEFAULT_DIST}"
OUTPUT_PATH="${1:-$DIST_DIR/$PLUGIN_SLUG.zip}"
[[ "$OUTPUT_PATH" == /* ]] || OUTPUT_PATH="$(pwd)/$OUTPUT_PATH"
OUTPUT_DIR="$(dirname "$OUTPUT_PATH")"
OUTPUT_NAME="$(basename "$OUTPUT_PATH")"
[[ "$OUTPUT_NAME" == *.zip ]] || { echo 'Output must be a .zip file.' >&2; exit 1; }

for tool in zip unzip rsync shasum; do
  command -v "$tool" >/dev/null 2>&1 || { echo "$tool is required to build the release package." >&2; exit 1; }
done
bash "$ROOT_DIR/scripts/check-release-metadata.sh"
VERSION="$(sed -n 's/^Stable tag: //p' "$ROOT_DIR/readme.txt" | head -n1)"

if [[ "$SKIP_SYNC" != 1 ]]; then
  bash "$ROOT_DIR/scripts/sync-to-release-repo.sh"
fi

mkdir -p "$OUTPUT_DIR"
TEMP_DIR="$(mktemp -d "$OUTPUT_DIR/.build-$PLUGIN_SLUG.XXXXXX")"
trap 'rm -rf "$TEMP_DIR"' EXIT
mkdir -p "$TEMP_DIR/$PLUGIN_SLUG"

rsync -a "$ROOT_DIR/" "$TEMP_DIR/$PLUGIN_SLUG/" \
  --exclude '.git' --exclude '.gitattributes' --exclude '.github' --exclude '.gitignore' \
  --exclude '.DS_Store' --exclude '__MACOSX' --exclude '.env*' \
  --exclude '.idea' --exclude '.vscode' --exclude '.agents' --exclude '.codex' \
  --exclude '.phpunit.cache' --exclude '.phpunit.result.cache' \
  --exclude 'composer.json' --exclude 'composer.lock' --exclude 'node_modules' \
  --exclude 'package.json' --exclude 'package-lock.json' --exclude 'phpunit.xml*' \
  --exclude 'scripts' --exclude 'tests' --exclude '/vendor' --exclude '/dist' \
  --exclude '*.zip' --exclude '*.md' --exclude '*.log'

(cd "$TEMP_DIR" && zip -qr "$OUTPUT_NAME" "$PLUGIN_SLUG")
bash "$ROOT_DIR/scripts/validate-release-zip.sh" "$TEMP_DIR/$OUTPUT_NAME"
(cd "$TEMP_DIR" && shasum -a 256 "$OUTPUT_NAME") > "$TEMP_DIR/SHA256SUMS"
printf '%s\n' "$VERSION" > "$TEMP_DIR/VERSION"
awk -v version="$VERSION" '
  $0 == "= " version " =" { active = 1; next }
  active && /^=/ { exit }
  active { print }
' "$ROOT_DIR/readme.txt" > "$TEMP_DIR/RELEASE_NOTES.md"

# Replace the old ZIP only after validation succeeds. Custom CI/test output paths
# get their own checksum without touching a shared VERSION or release notes.
mv -f "$TEMP_DIR/$OUTPUT_NAME" "$OUTPUT_PATH"
if [[ $# -eq 0 ]]; then
  mv -f "$TEMP_DIR/SHA256SUMS" "$OUTPUT_DIR/SHA256SUMS"
  mv -f "$TEMP_DIR/VERSION" "$OUTPUT_DIR/VERSION"
  mv -f "$TEMP_DIR/RELEASE_NOTES.md" "$OUTPUT_DIR/RELEASE_NOTES.md"
  rm -f "$OUTPUT_DIR/published-release.json"
else
  mv -f "$TEMP_DIR/SHA256SUMS" "$OUTPUT_PATH.sha256"
fi

echo "Created $OUTPUT_PATH (version $VERSION; replaces the previous build)"
