#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
ARCHIVE_PATH=${1:-${ARCHIVE_PATH:-}}
PATCH_MODE=${PATCH_MODE:-reverse}

cd "$ROOT"

if [ "$ARCHIVE_PATH" = "" ]; then
  ARCHIVE_PATH=$(find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-source-handover-*.tar.gz' 2>/dev/null | sort | tail -n 1)
fi
if [ "$ARCHIVE_PATH" = "" ] || [ ! -f "$ARCHIVE_PATH" ]; then
  echo "ERROR source handover archive is required." >&2
  exit 1
fi
if [ ! -f "$ARCHIVE_PATH.sha256" ]; then
  echo "ERROR missing source handover checksum: $ARCHIVE_PATH.sha256" >&2
  exit 1
fi

expected=$(awk '{print tolower($1)}' "$ARCHIVE_PATH.sha256")
if command -v sha256sum >/dev/null 2>&1; then
  actual=$(sha256sum "$ARCHIVE_PATH" | awk '{print tolower($1)}')
elif command -v shasum >/dev/null 2>&1; then
  actual=$(shasum -a 256 "$ARCHIVE_PATH" | awk '{print tolower($1)}')
else
  echo "ERROR sha256sum or shasum is required." >&2
  exit 1
fi
if [ "$expected" != "$actual" ]; then
  echo "ERROR source handover checksum mismatch. expected=$expected actual=$actual" >&2
  exit 1
fi

TMP="$ROOT/runtime/handover/source-handover-verify-sh-$$-$(date +%s)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP"
tar -xzf "$ARCHIVE_PATH" -C "$TMP"

require_match() {
  pattern=$1
  if ! find "$TMP" -type f | sed "s#^$TMP/##" | grep -E "$pattern" >/dev/null; then
    echo "ERROR missing source handover entry matching: $pattern" >&2
    exit 1
  fi
}

require_match '^MANIFEST\.md$'
require_match '^tracked/mongoyia-source-tracked-diff-.+\.patch$'
require_match '^tracked/mongoyia-source-tracked-diff-.+\.patch\.sha256$'
require_match '^untracked/mongoyia-untracked-source-.+\.zip$'
require_match '^untracked/mongoyia-untracked-source-.+\.zip\.sha256$'
require_match '^reports/mongoyia-source-diff-export-.+\.md$'
require_match '^reports/mongoyia-untracked-source-export-.+\.md$'
require_match '^reports/mongoyia-worktree-inventory-.+\.md$'

if find "$TMP" -type f | sed "s#^$TMP/##" | grep -E '(^|/)\.env$|(^|/)vendor/|(^|/)node_modules/|(^|/)web/attachment/|(^|/)web/assets/|\.(sql|dump|bak|7z|rar)$' >/dev/null; then
  echo "ERROR forbidden entry found in source handover archive." >&2
  exit 1
fi

patch=$(find "$TMP/tracked" -maxdepth 1 -type f -name 'mongoyia-source-tracked-diff-*.patch' | head -n 1)
if [ "$PATCH_MODE" = "reverse" ]; then
  git apply --reverse --check "$patch"
elif [ "$PATCH_MODE" = "apply" ]; then
  git apply --check "$patch"
elif [ "$PATCH_MODE" = "skip" ]; then
  :
else
  echo "ERROR invalid PATCH_MODE: $PATCH_MODE" >&2
  exit 1
fi

bundle=$(find "$TMP/untracked" -maxdepth 1 -type f -name 'mongoyia-untracked-source-*.zip' | head -n 1)
"$SCRIPT_DIR/mongoyia-validate-untracked-source.sh" "$bundle"

entries=$(find "$TMP" -type f | wc -l | tr -d ' ')

echo "Source handover validation: PASS"
echo "Archive: $ARCHIVE_PATH"
echo "Checksum: PASS ($ARCHIVE_PATH.sha256)"
echo "Entries: $entries"
echo "Patch mode: $PATCH_MODE"
