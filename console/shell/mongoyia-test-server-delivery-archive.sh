#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}

cd "$ROOT"

OUTPUT_ROOT="$ROOT/$OUTPUT_DIR"
STAGE="$OUTPUT_ROOT/mongoyia-test-server-delivery-$STAMP"
ARCHIVE_PATH="$OUTPUT_ROOT/mongoyia-test-server-delivery-$STAMP.tar.gz"

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
    echo "ERROR missing delivery artifact: $src" >&2
    exit 1
  fi
  mkdir -p "$STAGE/$folder"
  cp "$src" "$STAGE/$folder/$(basename "$src")"
}

mkdir -p "$OUTPUT_ROOT"
HANDOVER_ARCHIVE_PATH=${HANDOVER_ARCHIVE_PATH:-$(latest_file 'mongoyia-handover-*.zip')}
SOURCE_HANDOVER_ARCHIVE_PATH=${SOURCE_HANDOVER_ARCHIVE_PATH:-$(latest_file 'mongoyia-source-handover-*.zip')}
PREFLIGHT_REPORT_PATH=${PREFLIGHT_REPORT_PATH:-$(latest_file 'mongoyia-test-server-preflight-*.md')}
HANDOVER_VERIFY_REPORT_PATH=${HANDOVER_VERIFY_REPORT_PATH:-$(latest_file 'mongoyia-handover-verify-*.md')}

rm -rf "$STAGE"
mkdir -p "$STAGE"
copy_to_stage "$HANDOVER_ARCHIVE_PATH" archives
copy_to_stage "$HANDOVER_ARCHIVE_PATH.sha256" archives
copy_to_stage "$SOURCE_HANDOVER_ARCHIVE_PATH" archives
copy_to_stage "$SOURCE_HANDOVER_ARCHIVE_PATH.sha256" archives
copy_to_stage "$PREFLIGHT_REPORT_PATH" reports
copy_to_stage "$HANDOVER_VERIFY_REPORT_PATH" reports
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-receiver.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-receiver.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-restore.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-restore.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-restore-plan.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-restore-plan.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-go-no-go.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-go-no-go.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-go-no-go-smoke.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-go-no-go-smoke.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-input-gate.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-input-gate.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-input-gate-smoke.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-test-server-input-gate-smoke.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-sql-dump-manifest.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-sql-dump-manifest.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-env-redacted-report.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-env-redacted-report.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-handoff-status.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-handoff-status.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-p2-readiness.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-p2-readiness.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-p2-evidence-pack.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-p2-evidence-pack.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-payment-sandbox-evidence.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-payment-sandbox-evidence.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-im-wss-evidence.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-im-wss-evidence.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-mongolian-review-evidence.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-mongolian-review-evidence.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-backup.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-backup.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-backup-verify.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-backup-verify.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-health.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-health.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-monitor.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-monitor.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-load-smoke.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-load-smoke.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-load-test-evidence.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-load-test-evidence.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-scheduled-check.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-scheduled-check.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-evidence-summary.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-evidence-summary.sh" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-go-live-gate.ps1" receiver
copy_to_stage "$ROOT/console/shell/mongoyia-production-go-live-gate.sh" receiver
cp "$ROOT/docs/mongoyia-test-server-receiver.md" "$STAGE/RECEIVER_README.md"
cp "$ROOT/docs/mongoyia-test-server-inputs.md" "$STAGE/mongoyia-test-server-inputs.md"
cp "$ROOT/docs/mongoyia-external-integration-inputs.md" "$STAGE/mongoyia-external-integration-inputs.md"
cp "$ROOT/docs/mongoyia-mongolian-review-workflow.md" "$STAGE/mongoyia-mongolian-review-workflow.md"
cp "$ROOT/docs/mongoyia-mongolian-review-evidence.md" "$STAGE/mongoyia-mongolian-review-evidence.md"
cp "$ROOT/docs/mongoyia-p2-evidence-pack.md" "$STAGE/mongoyia-p2-evidence-pack.md"
cp "$ROOT/docs/mongoyia-payment-sandbox-evidence.md" "$STAGE/mongoyia-payment-sandbox-evidence.md"
cp "$ROOT/docs/mongoyia-im-wss-evidence.md" "$STAGE/mongoyia-im-wss-evidence.md"
cp "$ROOT/docs/mongoyia-production-readiness.md" "$STAGE/mongoyia-production-readiness.md"
cp "$ROOT/docs/mongoyia-production-scheduled-monitoring.md" "$STAGE/mongoyia-production-scheduled-monitoring.md"
cp "$ROOT/docs/mongoyia-production-load-test-evidence.md" "$STAGE/mongoyia-production-load-test-evidence.md"
cp "$ROOT/docs/mongoyia-production-evidence-summary.md" "$STAGE/mongoyia-production-evidence-summary.md"
cp "$ROOT/docs/mongoyia-production-go-live-gate.md" "$STAGE/mongoyia-production-go-live-gate.md"
cp "$ROOT/docs/mongoyia-production-rollout-rollback.md" "$STAGE/mongoyia-production-rollout-rollback.md"

cat > "$STAGE/MANIFEST.md" <<EOF
# Mongoyia Test Server Delivery Manifest

- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Source root: $ROOT
- Handover archive: archives/$(basename "$HANDOVER_ARCHIVE_PATH")
- Source handover archive: archives/$(basename "$SOURCE_HANDOVER_ARCHIVE_PATH")
- Preflight report: reports/$(basename "$PREFLIGHT_REPORT_PATH")
- Handover verification report: reports/$(basename "$HANDOVER_VERIFY_REPORT_PATH")

This delivery archive is for test-server handover. It excludes database dumps, real .env files, uploads, vendor dependencies, generated web assets, and production secrets.

Read RECEIVER_README.md first. Use receiver/mongoyia-test-server-restore-plan before switching restore to apply mode, then run receiver/mongoyia-test-server-go-no-go before Apply. NO-GO means do not apply. Run receiver/mongoyia-test-server-go-no-go-smoke to verify local go/no-go rules with synthetic reports. Fill mongoyia-external-integration-inputs.md with non-sensitive server, payment sandbox, IM WSS, backup, monitoring, and load-test ownership before confirming EXTERNAL_TEST_INPUTS_CONFIRMED. Restore Apply runs input-gate and go/no-go again before database restore; external inputs must be confirmed with EXTERNAL_TEST_INPUTS_CONFIRMED after real values are supplied. The receiver scripts under receiver/ verify checksums, extract the package, and can run strict preflight after real test-server .env files are ready. After restore, strict preflight, full acceptance, payment sandbox checks, and IM WSS checks, run receiver/mongoyia-payment-sandbox-evidence, receiver/mongoyia-im-wss-evidence, then receiver/mongoyia-p2-evidence-pack to collect the latest non-sensitive P2 review archive. After P2 acceptance, use mongoyia-mongolian-review-workflow.md for Mongolian human-review export/import rehearsal, run receiver/mongoyia-mongolian-review-evidence to record non-sensitive reviewer signoff evidence, then use receiver/mongoyia-production-backup, receiver/mongoyia-production-backup-verify, receiver/mongoyia-production-health, receiver/mongoyia-production-monitor, receiver/mongoyia-production-load-smoke, receiver/mongoyia-production-load-test-evidence, receiver/mongoyia-production-scheduled-check, receiver/mongoyia-production-evidence-summary, and receiver/mongoyia-production-go-live-gate as production-readiness rehearsal tools.
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

echo "Test-server delivery folder: $STAGE"
echo "Test-server delivery archive: $ARCHIVE_PATH"
echo "Test-server delivery checksum: $ARCHIVE_PATH.sha256"
