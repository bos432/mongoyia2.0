#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}

cd "$ROOT"

OUTPUT_ROOT="$ROOT/$OUTPUT_DIR"
STAGE="$OUTPUT_ROOT/mongoyia-source-handover-$STAMP"
ARCHIVE_PATH="$OUTPUT_ROOT/mongoyia-source-handover-$STAMP.tar.gz"

latest_file() {
  pattern=$1
  file=$(find "$OUTPUT_ROOT" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1)
  if [ "$file" = "" ]; then
    echo "ERROR no file found for pattern $pattern under $OUTPUT_ROOT" >&2
    exit 1
  fi
  printf '%s\n' "$file"
}

copy_to_stage() {
  src=$1
  folder=$2
  if [ ! -f "$src" ]; then
    echo "ERROR missing source handover artifact: $src" >&2
    exit 1
  fi
  mkdir -p "$STAGE/$folder"
  cp "$src" "$STAGE/$folder/$(basename "$src")"
}

mkdir -p "$OUTPUT_ROOT"
PATCH_PATH=${PATCH_PATH:-$(latest_file 'mongoyia-source-tracked-diff-*.patch')}
UNTRACKED_BUNDLE_PATH=${UNTRACKED_BUNDLE_PATH:-$(latest_file 'mongoyia-untracked-source-*.zip')}
INVENTORY_PATH=${INVENTORY_PATH:-$(latest_file 'mongoyia-worktree-inventory-*.md')}
PATCH_REPORT_PATH="$(dirname "$PATCH_PATH")/$(basename "$PATCH_PATH" | sed 's#^mongoyia-source-tracked-diff-#mongoyia-source-diff-export-#; s#\.patch$#.md#')"
UNTRACKED_REPORT_PATH="$(dirname "$UNTRACKED_BUNDLE_PATH")/$(basename "$UNTRACKED_BUNDLE_PATH" | sed 's#^mongoyia-untracked-source-#mongoyia-untracked-source-export-#; s#\.zip$#.md#')"

rm -rf "$STAGE"
mkdir -p "$STAGE"
copy_to_stage "$PATCH_PATH" tracked
copy_to_stage "$PATCH_PATH.sha256" tracked
copy_to_stage "$UNTRACKED_BUNDLE_PATH" untracked
copy_to_stage "$UNTRACKED_BUNDLE_PATH.sha256" untracked
copy_to_stage "$PATCH_REPORT_PATH" reports
copy_to_stage "$UNTRACKED_REPORT_PATH" reports
copy_to_stage "$INVENTORY_PATH" reports

cat > "$STAGE/MANIFEST.md" <<EOF
# Mongoyia Source Handover Manifest

- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Source root: $ROOT
- Tracked patch: tracked/$(basename "$PATCH_PATH")
- Untracked source bundle: untracked/$(basename "$UNTRACKED_BUNDLE_PATH")
- Worktree inventory: reports/$(basename "$INVENTORY_PATH")

This source handover archive intentionally contains patch files, reviewed untracked source bundle, checksums, and reports only. It does not contain database dumps, real .env, uploads, vendor dependencies, generated runtime output, or generated web assets.
EOF

rm -f "$ARCHIVE_PATH" "$ARCHIVE_PATH.sha256"
tar -czf "$ARCHIVE_PATH" -C "$STAGE" .
if command -v sha256sum >/dev/null 2>&1; then
  (cd "$OUTPUT_ROOT" && sha256sum "$(basename "$ARCHIVE_PATH")" > "$(basename "$ARCHIVE_PATH").sha256")
elif command -v shasum >/dev/null 2>&1; then
  (cd "$OUTPUT_ROOT" && shasum -a 256 "$(basename "$ARCHIVE_PATH")" > "$(basename "$ARCHIVE_PATH").sha256")
else
  echo "ERROR sha256sum or shasum is required." >&2
  exit 1
fi

echo "Source handover folder: $STAGE"
echo "Source handover archive: $ARCHIVE_PATH"
echo "Source handover checksum: $ARCHIVE_PATH.sha256"
