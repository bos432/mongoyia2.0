#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
DELIVERY_ARCHIVE_PATH=${DELIVERY_ARCHIVE_PATH:-${1:-}}
WORK_DIR=${WORK_DIR:-}
BASE_URL=${BASE_URL:-}
PHP_BIN=${PHP_BIN:-php}
RUN_PREFLIGHT=${RUN_PREFLIGHT:-0}
SKIP_API=${SKIP_API:-0}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}

cd "$ROOT"

latest_delivery_archive() {
  find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-test-server-delivery-*.tar.gz' 2>/dev/null | sort | tail -n 1
}

sha256_value() {
  path=$1
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$path" | awk '{print tolower($1)}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$path" | awk '{print tolower($1)}'
  else
    echo "ERROR sha256sum or shasum is required." >&2
    exit 1
  fi
}

assert_sha256() {
  path=$1
  if [ ! -f "$path.sha256" ]; then
    echo "ERROR missing checksum: $path.sha256" >&2
    exit 1
  fi
  expected=$(awk '{print tolower($1)}' "$path.sha256")
  actual=$(sha256_value "$path")
  if [ "$expected" != "$actual" ]; then
    echo "ERROR checksum mismatch for $path. expected=$expected actual=$actual" >&2
    exit 1
  fi
}

if [ "$DELIVERY_ARCHIVE_PATH" = "" ]; then
  DELIVERY_ARCHIVE_PATH=$(latest_delivery_archive)
fi
if [ "$DELIVERY_ARCHIVE_PATH" = "" ] || [ ! -f "$DELIVERY_ARCHIVE_PATH" ]; then
  echo "ERROR test-server delivery archive is required." >&2
  exit 1
fi

assert_sha256 "$DELIVERY_ARCHIVE_PATH"

if [ "$WORK_DIR" = "" ]; then
  WORK_DIR="$ROOT/runtime/handover/receiver-$(date +%Y%m%d-%H%M%S)-$$"
fi
if [ -e "$WORK_DIR" ]; then
  echo "ERROR receiver work directory already exists: $WORK_DIR" >&2
  exit 1
fi
mkdir -p "$WORK_DIR/delivery"

tar -xzf "$DELIVERY_ARCHIVE_PATH" -C "$WORK_DIR/delivery"

entries=$(find "$WORK_DIR/delivery" -type f | sed "s#^$WORK_DIR/delivery/##")

require_match() {
  pattern=$1
  if ! printf '%s\n' "$entries" | grep -E "$pattern" >/dev/null; then
    echo "ERROR missing delivery entry matching: $pattern" >&2
    exit 1
  fi
}

require_match '^MANIFEST\.md$'
require_match '^RECEIVER_README\.md$'
require_match '^receiver/mongoyia-test-server-receiver\.ps1$'
require_match '^receiver/mongoyia-test-server-receiver\.sh$'
require_match '^receiver/mongoyia-test-server-restore-plan\.ps1$'
require_match '^receiver/mongoyia-test-server-restore-plan\.sh$'
require_match '^receiver/mongoyia-test-server-input-gate\.ps1$'
require_match '^receiver/mongoyia-test-server-input-gate\.sh$'
require_match '^receiver/mongoyia-test-server-input-gate-smoke\.ps1$'
require_match '^receiver/mongoyia-test-server-input-gate-smoke\.sh$'
require_match '^receiver/mongoyia-test-server-go-no-go\.ps1$'
require_match '^receiver/mongoyia-test-server-go-no-go\.sh$'
require_match '^archives/mongoyia-handover-.+\.zip$'
require_match '^archives/mongoyia-handover-.+\.zip\.sha256$'
require_match '^archives/mongoyia-source-handover-.+\.zip$'
require_match '^archives/mongoyia-source-handover-.+\.zip\.sha256$'
require_match '^reports/mongoyia-test-server-preflight-.+\.md$'
require_match '^reports/mongoyia-handover-verify-.+\.md$'

if printf '%s\n' "$entries" | grep -E '(^|/)\.env$|(^|/)vendor/|(^|/)node_modules/|(^|/)web/attachment/|(^|/)web/assets/|\.(sql|dump|bak|7z|rar)$' >/dev/null; then
  echo "ERROR forbidden entry found in delivery archive." >&2
  exit 1
fi

handover=$(find "$WORK_DIR/delivery/archives" -maxdepth 1 -type f -name 'mongoyia-handover-*.zip' | head -n 1)
source_handover=$(find "$WORK_DIR/delivery/archives" -maxdepth 1 -type f -name 'mongoyia-source-handover-*.zip' | head -n 1)
preflight=$(find "$WORK_DIR/delivery/reports" -maxdepth 1 -type f -name 'mongoyia-test-server-preflight-*.md' | head -n 1)

assert_sha256 "$handover"
assert_sha256 "$source_handover"

if ! grep -E '^- Result: PASS$' "$preflight" >/dev/null; then
  echo "ERROR preflight report inside delivery archive is not marked PASS." >&2
  exit 1
fi

status_path="$WORK_DIR/RECEIVER_STATUS.md"
cat > "$status_path" <<EOF
# Mongoyia Test Server Receiver Status

- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Project root: $ROOT
- Delivery archive: $DELIVERY_ARCHIVE_PATH
- Extracted to: $WORK_DIR/delivery
- Handover archive: $handover
- Source handover archive: $source_handover
- Included preflight report: $preflight
- Checksum validation: PASS
- Included preflight marker: PASS

## Next Required Receiver Steps

1. Copy the SQL dump and \`.sha256\` sidecar to this test server.
2. Create real PHP and Python IM \`.env\` files from \`.env.test.example\` and replace placeholders.
3. Generate a restore command plan with \`receiver/mongoyia-test-server-restore-plan\`.
4. Run the restore dry-run command from the generated plan.
5. Generate \`receiver/mongoyia-test-server-go-no-go\`; do not run Apply while it reports \`NO-GO\`.
6. Run apply mode only after backup/snapshot, input gate, dry-run, go/no-go, and external inputs are approved.
7. Run strict preflight and then full acceptance.

## Restore Plan Command Template

\`\`\`bash
DELIVERY_ARCHIVE_PATH='$DELIVERY_ARCHIVE_PATH' SQL_DUMP_PATH='<dump.sql>' SQL_CHECKSUM_PATH='runtime/handover/<dump.sql>.sha256' BASE_URL='https://<test-domain>' IM_URL='wss://<test-domain>/<im-path>' BACKUP_REFERENCE='snapshot-or-ticket-id' sh console/shell/mongoyia-test-server-restore-plan.sh
\`\`\`

## Apply Safety Reminder

- Restore apply automatically runs \`mongoyia-test-server-input-gate\` and \`mongoyia-test-server-go-no-go\` before database restore.
- If handoff status still reports external inputs as pending, include \`-ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED\` or \`EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED\` only after the real test-server values are supplied and approved.
- Emergency bypass requires the full apply-safety bypass confirmation \`SKIP_RESTORE_APPLY_SAFETY\`.
EOF

echo "Mongoyia test-server receiver validation: PASS"
echo "Delivery archive: $DELIVERY_ARCHIVE_PATH"
echo "Extracted to: $WORK_DIR/delivery"
echo "Status report: $status_path"
echo "Handover archive: $handover"
echo "Source handover archive: $source_handover"
echo ""
echo "Recommended next step: generate a restore command plan before dry-run/apply, then run go/no-go before Apply. Apply will run input-gate and go/no-go again before database restore:"
echo "DELIVERY_ARCHIVE_PATH='$DELIVERY_ARCHIVE_PATH' SQL_DUMP_PATH='<dump.sql>' SQL_CHECKSUM_PATH='runtime/handover/<dump.sql>.sha256' BASE_URL='https://<test-domain>' IM_URL='wss://<test-domain>/<im-path>' BACKUP_REFERENCE='snapshot-or-ticket-id' sh console/shell/mongoyia-test-server-restore-plan.sh"
echo "sh console/shell/mongoyia-test-server-go-no-go.sh"
echo "When real external inputs are approved, Apply commands must include: EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED"

if [ "$RUN_PREFLIGHT" = "1" ]; then
  BASE_URL="$BASE_URL" PHP="$PHP_BIN" SKIP_API="$SKIP_API" SKIP_CONNECTIVITY="$SKIP_CONNECTIVITY" sh "$SCRIPT_DIR/mongoyia-test-server-preflight-report.sh"
fi
