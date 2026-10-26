#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -f "$ROOT_DIR/../../artisan" ]]; then
  DEFAULT_DIST="$ROOT_DIR/../../dist/tool-kits"
else
  DEFAULT_DIST="$ROOT_DIR/dist"
fi
ZIP_PATH="${1:-${RELEASE_DIST_DIR:-$DEFAULT_DIST}/tool-kits.zip}"

if ! command -v unzip >/dev/null 2>&1; then
  echo "unzip is required to validate the release archive." >&2
  exit 1
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "Archive not found: $ZIP_PATH" >&2
  exit 1
fi

unzip -tq "$ZIP_PATH" >/dev/null
LISTING="$(unzip -Z1 "$ZIP_PATH")"

if grep -vq '^tool-kits/' <<<"$LISTING" || grep -Eq '(^|/)\.\.(/|$)|\\' <<<"$LISTING"; then
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

if ! grep -qx 'tool-kits/includes/role-management.php' <<<"$LISTING"; then
  echo "Missing required include: tool-kits/includes/role-management.php" >&2
  exit 1
fi

if ! grep -qx 'tool-kits/includes/security-firewall.php' <<<"$LISTING"; then
  echo "Missing required include: tool-kits/includes/security-firewall.php" >&2
  exit 1
fi

if ! grep -qx 'tool-kits/includes/malware-scanner.php' <<<"$LISTING"; then
  echo "Missing required include: tool-kits/includes/malware-scanner.php" >&2
  exit 1
fi

for file in health-center.php health-center-engine.php health-center-view.php health-center-summary.php health-center-policy.php health-center-recheck.php health-center-history.php health-center-probe.php; do
  if ! grep -Fqx "tool-kits/includes/$file" <<<"$LISTING"; then
    echo "Missing required Health Center include: $file" >&2
    exit 1
  fi
done

if grep -q '^__MACOSX/' <<<"$LISTING"; then
  echo "Archive contains unexpected __MACOSX metadata." >&2
  exit 1
fi

if grep -q '^tool-kits/\.git' <<<"$LISTING"; then
  echo "Archive contains unexpected Git metadata." >&2
  exit 1
fi

if grep -q '/\.DS_Store$' <<<"$LISTING"; then
  echo "Archive contains unexpected .DS_Store metadata." >&2
  exit 1
fi

if grep -qx 'tool-kits/README.md' <<<"$LISTING"; then
  echo "Archive contains development README.md. Use readme.txt for the plugin package." >&2
  exit 1
fi

if grep -qx 'tool-kits/ROADMAP.md' <<<"$LISTING"; then
  echo "Archive contains development ROADMAP.md." >&2
  exit 1
fi

if grep -q '^tool-kits/scripts/' <<<"$LISTING"; then
  echo "Archive contains development scripts/ files." >&2
  exit 1
fi

if grep -qx 'tool-kits/tool-kits.zip' <<<"$LISTING"; then
  echo "Archive contains nested build artifact tool-kits.zip." >&2
  exit 1
fi

echo "Release archive looks valid: $ZIP_PATH"
