#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=$(date +%Y%m%d-%H%M%S)
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-worktree-inventory-$STAMP.md}

cd "$ROOT"

STATUS=$(git status --short)
OUTPUT_FULL="$ROOT/$OUTPUT_PATH"
mkdir -p "$(dirname "$OUTPUT_FULL")"

tracked_modified=$(printf '%s\n' "$STATUS" | awk 'length($0) >= 4 && substr($0,1,2) != "??" && substr($0,4) !~ /^runtime[\/\\]/ { print }')
untracked=$(printf '%s\n' "$STATUS" | awk 'length($0) >= 4 && substr($0,1,2) == "??" && substr($0,4) !~ /^runtime[\/\\]/ { print }')
runtime_generated=$(printf '%s\n' "$STATUS" | awk 'length($0) >= 4 && substr($0,4) ~ /^runtime[\/\\]/ { print }')
handover_generated=$(printf '%s\n' "$STATUS" | awk 'length($0) >= 4 && substr($0,4) ~ /^runtime[\/\\]handover[\/\\]/ { print }')

count_lines() {
  sed '/^$/d' | wc -l | tr -d ' '
}

{
  echo "# Mongoyia Worktree Inventory"
  echo ""
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Source root: $ROOT"
  echo "- Tracked modified/deleted/renamed files: $(printf '%s\n' "$tracked_modified" | count_lines)"
  echo "- Untracked non-runtime files/directories: $(printf '%s\n' "$untracked" | count_lines)"
  echo "- Runtime/generated entries: $(printf '%s\n' "$runtime_generated" | count_lines)"
  echo "- Handover generated entries: $(printf '%s\n' "$handover_generated" | count_lines)"
  echo ""
  echo "## Important Scope Note"
  echo ""
  echo "The \`runtime/handover/mongoyia-handover-*.zip\` archive is a handover documentation, scripts, templates, and report bundle. It is not a complete deployable source archive."
  echo ""
  echo "For source-code handover, pass the full working tree or create a proper Git commit/patch after reviewing this inventory. The worktree was already dirty before the later handover packaging work, so treat this report as an inventory for review rather than proof that every listed file was changed in the final packaging phase."
  echo ""
  echo "## Tracked Modified Files"
  echo ""
  echo '```text'
  printf '%s\n' "$tracked_modified"
  echo '```'
  echo ""
  echo "## Untracked Non-Runtime Files And Directories"
  echo ""
  echo '```text'
  printf '%s\n' "$untracked"
  echo '```'
  echo ""
  echo "## Runtime And Generated Entries"
  echo ""
  echo '```text'
  printf '%s\n' "$runtime_generated"
  echo '```'
  echo ""
  echo "## Suggested Receiver Review Order"
  echo ""
  echo "1. Read \`docs/mongoyia-change-index.md\` for the functional map."
  echo "2. Review tracked modified files first because they patch existing application behavior."
  echo "3. Review untracked controllers, migrations, helper/model files, docs, and shell scripts that are part of Mongoyia delivery."
  echo "4. Treat \`runtime/handover/*\` and \`runtime/acceptance/*\` as generated evidence, not deploy source."
  echo "5. Do not commit real \`.env\`, database dumps, uploaded files, or generated web assets."
} > "$OUTPUT_FULL"

echo "Worktree inventory report: $OUTPUT_FULL"
