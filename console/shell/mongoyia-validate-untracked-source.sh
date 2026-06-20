#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
BUNDLE_PATH=${1:-${BUNDLE_PATH:-}}

cd "$ROOT"

if [ "$BUNDLE_PATH" = "" ]; then
  BUNDLE_PATH=$(find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-untracked-source-*.tar.gz' 2>/dev/null | sort | tail -n 1)
fi
if [ "$BUNDLE_PATH" = "" ] || [ ! -f "$BUNDLE_PATH" ]; then
  echo "ERROR bundle path is required or no mongoyia-untracked-source-*.tar.gz bundle was found." >&2
  exit 1
fi

LIST_FILE="$ROOT/runtime/handover/mongoyia-untracked-source-validate-entries.txt"
tar -tzf "$BUNDLE_PATH" | sed 's#^\./##; s#\\#/#g' | grep -v '/$' > "$LIST_FILE"

failures=0
require_entry() {
  rel=$1
  if ! grep -Fx "$rel" "$LIST_FILE" >/dev/null; then
    echo "FAIL missing required untracked source bundle entry: $rel" >&2
    failures=$((failures + 1))
  fi
}

require_entry "MANIFEST.md"
require_entry ".env.example"
require_entry ".env.test.example"
require_entry "MONGOYIA_README.md"
require_entry "backend/modules/mall/controllers/PaymentAttemptController.php"
require_entry "common/helpers/MallPlatformHelper.php"
require_entry "common/messages/mn/mall.php"
require_entry "common/models/mall/PaymentAttempt.php"
require_entry "console/controllers/MongoyiaPackageCheckController.php"
require_entry "console/migrations/m260608_180000_mongoyia_payment_attempt.php"
require_entry "console/shell/mongoyia-untracked-source-export.sh"
require_entry "console/shell/mongoyia-validate-untracked-source.sh"
require_entry "docs/mongoyia-local-baseline.md"
require_entry "frontend/modules/mall/controllers/ChatController.php"
require_entry "web/resources/mall/default/views/chat/index.php"

if grep -E '(^194\.sql$|^petever1\.jpg$|^\.well-known/|^demo/|^runtime/|^web/\.well-known/|^web/log\.txt$|^web/success\.php$|(^|/)[^/]+-0\.php$|\.(sql|jpg|jpeg|png|gif|webp|zip|tar|gz|rar|7z|log)$)' "$LIST_FILE" >/dev/null; then
  echo "FAIL forbidden entries found in untracked source bundle:" >&2
  grep -E '(^194\.sql$|^petever1\.jpg$|^\.well-known/|^demo/|^runtime/|^web/\.well-known/|^web/log\.txt$|^web/success\.php$|(^|/)[^/]+-0\.php$|\.(sql|jpg|jpeg|png|gif|webp|zip|tar|gz|rar|7z|log)$)' "$LIST_FILE" >&2
  failures=$((failures + 1))
fi

entry_count=$(wc -l < "$LIST_FILE" | tr -d ' ')
if [ "$entry_count" -lt 30 ]; then
  echo "FAIL unexpectedly small untracked source bundle: $entry_count entries" >&2
  failures=$((failures + 1))
fi

if [ -f "$BUNDLE_PATH.sha256" ]; then
  if command -v sha256sum >/dev/null 2>&1; then
    expected=$(awk '{print tolower($1)}' "$BUNDLE_PATH.sha256")
    actual=$(sha256sum "$BUNDLE_PATH" | awk '{print tolower($1)}')
  elif command -v shasum >/dev/null 2>&1; then
    expected=$(awk '{print tolower($1)}' "$BUNDLE_PATH.sha256")
    actual=$(shasum -a 256 "$BUNDLE_PATH" | awk '{print tolower($1)}')
  else
    echo "FAIL sha256sum or shasum is required." >&2
    failures=$((failures + 1))
    expected=""
    actual=""
  fi
  if [ "$expected" != "$actual" ]; then
    echo "FAIL checksum mismatch: expected $expected, got $actual" >&2
    failures=$((failures + 1))
  fi
else
  echo "FAIL missing checksum file: $BUNDLE_PATH.sha256" >&2
  failures=$((failures + 1))
fi

rm -f "$LIST_FILE"

if [ "$failures" -gt 0 ]; then
  echo "Untracked source bundle validation failed: $failures failure(s)." >&2
  exit 1
fi

echo "Untracked source bundle validation: PASS"
echo "Bundle: $BUNDLE_PATH"
echo "Entries: $entry_count"
