#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}

cd "$ROOT"

OUTPUT_ROOT="$ROOT/$OUTPUT_DIR"
PATCH_PATH=${PATCH_PATH:-$OUTPUT_ROOT/mongoyia-source-tracked-diff-$STAMP.patch}
REPORT_PATH=${REPORT_PATH:-$OUTPUT_ROOT/mongoyia-source-diff-export-$STAMP.md}
mkdir -p "$(dirname "$PATCH_PATH")" "$(dirname "$REPORT_PATH")"

git diff --binary -- . > "$PATCH_PATH"
git diff --stat -- . > "$PATCH_PATH.stat"
git status --short > "$PATCH_PATH.status"

if command -v sha256sum >/dev/null 2>&1; then
  PATCH_HASH=$(sha256sum "$PATCH_PATH" | awk '{print tolower($1)}')
elif command -v shasum >/dev/null 2>&1; then
  PATCH_HASH=$(shasum -a 256 "$PATCH_PATH" | awk '{print tolower($1)}')
else
  echo "ERROR sha256sum or shasum is required." >&2
  exit 1
fi
printf '%s  %s\n' "$PATCH_HASH" "$(basename "$PATCH_PATH")" > "$PATCH_PATH.sha256"

TRACKED_COUNT=$(awk 'length($0) >= 4 && substr($0,1,2) != "??" { count++ } END { print count + 0 }' "$PATCH_PATH.status")
UNTRACKED_COUNT=$(awk 'length($0) >= 4 && substr($0,1,2) == "??" && substr($0,4) !~ /^runtime[\/\\]/ { count++ } END { print count + 0 }' "$PATCH_PATH.status")
PATCH_SIZE=$(wc -c < "$PATCH_PATH" | tr -d ' ')

{
  echo "# Mongoyia Source Diff Export"
  echo ""
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Source root: $ROOT"
  echo "- Patch: $PATCH_PATH"
  echo "- Patch SHA256: $PATCH_HASH"
  echo "- Patch size: $PATCH_SIZE bytes"
  echo "- Tracked changed files in git status: $TRACKED_COUNT"
  echo "- Untracked non-runtime entries in git status: $UNTRACKED_COUNT"
  echo ""
  echo "## Scope"
  echo ""
  echo "This patch is generated from \`git diff --binary -- .\` and only includes modifications to already tracked files."
  echo ""
  echo "It does not include untracked delivery files such as new controllers, migrations, docs, shell scripts, language folders, or generated handover reports. Review \`runtime/handover/mongoyia-worktree-inventory-*.md\` before creating a final source commit or deployment bundle."
  echo ""
  echo "## Git Diff Stat"
  echo ""
  echo '```text'
  cat "$PATCH_PATH.stat"
  echo '```'
  echo ""
  echo "## Receiver Notes"
  echo ""
  echo "1. Apply this patch only to the same source baseline it was generated from."
  echo "2. Review and add required untracked Mongoyia files separately."
  echo "3. Do not include real \`.env\`, SQL dumps, uploaded files, generated \`runtime\`, or generated \`web/assets\` content in a source commit."
  echo "4. After applying source changes and untracked delivery files, run \`php yii mongoyia-package-check/run --interactive=0\` and the test-server dry-run."
} > "$REPORT_PATH"

rm -f "$PATCH_PATH.stat" "$PATCH_PATH.status"

echo "Source tracked diff patch: $PATCH_PATH"
echo "Source tracked diff checksum: $PATCH_PATH.sha256"
echo "Source diff export report: $REPORT_PATH"
