#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

ARCHIVE_PATH=${ARCHIVE_PATH:-}
CHECKSUM_PATH=${CHECKSUM_PATH:-}

cd "$ROOT"

if [ "$ARCHIVE_PATH" = "" ]; then
  ARCHIVE_PATH=$(find "$ROOT/runtime/handover" -maxdepth 1 -type f \( -name 'mongoyia-handover-*.tar.gz' -o -name 'mongoyia-handover-*.zip' \) 2>/dev/null | sort | tail -n 1)
fi

if [ "$ARCHIVE_PATH" = "" ] || [ ! -f "$ARCHIVE_PATH" ]; then
  echo "ERROR no handover archive found." >&2
  exit 1
fi

if [ "$CHECKSUM_PATH" = "" ] && [ -f "$ARCHIVE_PATH.sha256" ]; then
  CHECKSUM_PATH="$ARCHIVE_PATH.sha256"
fi

if [ "$CHECKSUM_PATH" != "" ]; then
  if [ ! -f "$CHECKSUM_PATH" ]; then
    echo "ERROR checksum file does not exist: $CHECKSUM_PATH" >&2
    exit 1
  fi
  EXPECTED_HASH=$(awk '{print tolower($1); exit}' "$CHECKSUM_PATH")
  if command -v sha256sum >/dev/null 2>&1; then
    ACTUAL_HASH=$(sha256sum "$ARCHIVE_PATH" | awk '{print tolower($1)}')
  elif command -v shasum >/dev/null 2>&1; then
    ACTUAL_HASH=$(shasum -a 256 "$ARCHIVE_PATH" | awk '{print tolower($1)}')
  else
    echo "ERROR sha256sum or shasum is required to validate checksum files." >&2
    exit 1
  fi
  if [ "$EXPECTED_HASH" != "$ACTUAL_HASH" ]; then
    echo "ERROR archive checksum mismatch. expected=$EXPECTED_HASH actual=$ACTUAL_HASH" >&2
    exit 1
  fi
fi

list_entries() {
  case "$ARCHIVE_PATH" in
    *.tar.gz|*.tgz)
      tar -tzf "$ARCHIVE_PATH" | sed 's#^\./##'
      ;;
    *.zip)
      if command -v unzip >/dev/null 2>&1; then
        unzip -Z1 "$ARCHIVE_PATH" | sed 's#\\#/#g' | sed 's#^\./##'
      else
        echo "ERROR unzip is required to validate zip archives." >&2
        exit 1
      fi
      ;;
    *)
      echo "ERROR unsupported archive type: $ARCHIVE_PATH" >&2
      exit 1
      ;;
  esac
}

ENTRIES=$(list_entries)

require_entry() {
  rel=$1
  if ! printf '%s\n' "$ENTRIES" | grep -Fx "$rel" >/dev/null; then
    echo "ERROR missing required archive entry: $rel" >&2
    exit 1
  fi
}

forbid_pattern() {
  pattern=$1
  label=$2
  match=$(printf '%s\n' "$ENTRIES" | grep -E "$pattern" | head -n 1 || true)
  if [ "$match" != "" ]; then
    echo "ERROR forbidden $label in archive: $match" >&2
    exit 1
  fi
}

REQUIRED_STATIC="
MANIFEST.md
MONGOYIA_README.md
.env.example
.env.test.example
docs/mongoyia-package-index.md
docs/mongoyia-test-server-runbook.md
docs/mongoyia-local-baseline.md
console/shell/mongoyia-test-profile-preflight.sh
console/shell/mongoyia-test-server-dry-run.sh
console/shell/mongoyia-final-handover.sh
console/shell/mongoyia-archive-handover.sh
console/shell/mongoyia-validate-handover-archive.sh
console/controllers/MongoyiaAcceptanceController.php
console/controllers/MongoyiaPackageCheckController.php
im-backend/main.py
im-backend/.env.example
im-backend/scripts/im-healthcheck.py
"

for rel in $REQUIRED_STATIC; do
  require_entry "$rel"
done

forbid_pattern '(^|/)\.env$' 'real env file'
forbid_pattern '(^|/)vendor/' 'vendor dependency'
forbid_pattern '(^|/)node_modules/' 'node_modules dependency'
forbid_pattern '(^|/)web/attachment/' 'uploaded attachment'
forbid_pattern '(^|/)web/assets/' 'generated asset'
forbid_pattern '\.(sql|dump|bak|7z|rar)$' 'dump/archive payload'

ACCEPTANCE_COUNT=$(printf '%s\n' "$ENTRIES" | grep -E '^runtime/acceptance/mongoyia-acceptance-.+\.md$' | wc -l | tr -d ' ')
SIGNOFF_COUNT=$(printf '%s\n' "$ENTRIES" | grep -E '^runtime/acceptance/mongoyia-signoff-.+\.md$' | wc -l | tr -d ' ')
RISK_COUNT=$(printf '%s\n' "$ENTRIES" | grep -E '^runtime/acceptance/mongoyia-risk-register-.+\.md$' | wc -l | tr -d ' ')
DELIVERY_COUNT=$(printf '%s\n' "$ENTRIES" | grep -E '^runtime/acceptance/mongoyia-delivery-index-.+\.md$' | wc -l | tr -d ' ')

if [ "$ACCEPTANCE_COUNT" -lt 1 ] || [ "$SIGNOFF_COUNT" -lt 1 ] || [ "$RISK_COUNT" -lt 1 ] || [ "$DELIVERY_COUNT" -lt 1 ]; then
  echo "ERROR archive must include latest acceptance, signoff, risk register, and delivery index reports." >&2
  exit 1
fi

ENTRY_COUNT=$(printf '%s\n' "$ENTRIES" | wc -l | tr -d ' ')
STATIC_COUNT=$(printf '%s\n' $REQUIRED_STATIC | wc -l | tr -d ' ')

echo "Handover archive validation: PASS"
echo "Archive: $ARCHIVE_PATH"
if [ "$CHECKSUM_PATH" != "" ]; then
  echo "Checksum: PASS ($CHECKSUM_PATH)"
fi
echo "Entries: $ENTRY_COUNT"
echo "Required static files: $STATIC_COUNT"
echo "Reports: acceptance=$ACCEPTANCE_COUNT signoff=$SIGNOFF_COUNT risk=$RISK_COUNT delivery=$DELIVERY_COUNT"
