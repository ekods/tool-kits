#!/usr/bin/env bash
set -euo pipefail

# Copy plugin source from the working folder into the git/release repo.
#
# Working folder : ~/Herd/nexamonitor/plugins/tool-kits   (edited, no .git)
# Release repo   : ~/Sites/localhost/ekodwis/wp-content/plugins/tool-kits
#
# Override the destination with TK_RELEASE_REPO.
# Pass --dry-run to preview without writing anything.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
TARGET_DIR="${TK_RELEASE_REPO:-$HOME/Sites/localhost/ekodwis/wp-content/plugins/tool-kits}"

# Plain scalars rather than an array: bash 3.2 (the macOS default) aborts on an
# empty array expansion while `set -u` is active.
DRY_RUN=0
DRY_FLAGS=""
if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN=1
  # --itemize-changes so the preview actually lists what would change.
  DRY_FLAGS="--dry-run --itemize-changes"
  echo "Dry run: no files will be written."
fi

if ! command -v rsync >/dev/null 2>&1; then
  echo "rsync is required to sync the release repo." >&2
  exit 1
fi

if [[ ! -d "$TARGET_DIR" ]]; then
  echo "Release repo not found: $TARGET_DIR" >&2
  echo "Set TK_RELEASE_REPO to point at it." >&2
  exit 1
fi

TARGET_DIR="$(cd "$TARGET_DIR" && pwd -P)"

if [[ "$ROOT_DIR" == "$TARGET_DIR" ]]; then
  echo "Already running inside the release repo; nothing to sync."
  exit 0
fi

# Guard against syncing into the wrong directory: the destination must be the
# git repo, otherwise a --delete run could wipe an unrelated folder.
if [[ ! -d "$TARGET_DIR/.git" ]]; then
  echo "Refusing to sync: $TARGET_DIR is not a git repository." >&2
  exit 1
fi

if [[ ! -f "$TARGET_DIR/tool-kits.php" ]]; then
  echo "Refusing to sync: $TARGET_DIR does not look like the Tool Kits plugin." >&2
  exit 1
fi

# .git stays put, and CLAUDE.md is working-folder documentation that must not
# reach the public repo. Excluded paths are never removed by --delete.
# shellcheck disable=SC2086
rsync -a --delete $DRY_FLAGS \
  --exclude '.git' \
  --exclude '.DS_Store' \
  --exclude 'CLAUDE.md' \
  --exclude 'tool-kits.zip' \
  "$ROOT_DIR/" "$TARGET_DIR/"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "(no output above means both folders are already identical)"
  exit 0
fi

echo "Synced source to $TARGET_DIR"
echo
echo "Pending changes in the release repo:"
git -C "$TARGET_DIR" status --short
