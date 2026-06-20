#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

PHP_BIN=${PHP_BIN:-php}
ARCHIVE_PATH=${ARCHIVE_PATH:-}
SKIP_ARCHIVE=${SKIP_ARCHIVE:-0}
STAMP=$(date +%Y%m%d-%H%M%S)
REPORT_PATH=${REPORT_PATH:-runtime/handover/mongoyia-handover-verify-$STAMP.md}
COMPLETED_STEPS=""
ARCHIVE_VALIDATION_OUTPUT=""

cd "$ROOT"

run_php() {
  name=$1
  shift
  echo ""
  echo "== $name =="
  echo "$PHP_BIN $*"
  "$PHP_BIN" "$@"
  COMPLETED_STEPS="${COMPLETED_STEPS}
$name"
}

run_script() {
  name=$1
  shift
  echo ""
  echo "== $name =="
  echo "$*"
  "$@"
  COMPLETED_STEPS="${COMPLETED_STEPS}
$name"
}

latest_archive() {
  find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-handover-*.zip' 2>/dev/null | sort | tail -n 1
}

write_report() {
  report_full="$ROOT/$REPORT_PATH"
  mkdir -p "$(dirname "$report_full")"

  archive_line="Skipped"
  checksum_line="Skipped"
  archive_size_line="Skipped"
  if [ "$SKIP_ARCHIVE" != "1" ]; then
    resolved_archive="$ARCHIVE_PATH"
    if [ "$resolved_archive" = "" ]; then
      resolved_archive=$(latest_archive)
    fi
    archive_line="$resolved_archive"
    archive_size_line="$(wc -c < "$resolved_archive" | tr -d ' ') bytes"
    if [ -f "$resolved_archive.sha256" ]; then
      checksum_hash=$(awk '{print $1; exit}' "$resolved_archive.sha256")
      checksum_line="$resolved_archive.sha256 (hash=$checksum_hash)"
    else
      checksum_line="Not found"
    fi
  fi

  {
    echo "# Mongoyia Handover Verification Report"
    echo ""
    echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "- Source root: $ROOT"
    echo "- Result: PASS"
    echo "- Archive: $archive_line"
    echo "- Archive size: $archive_size_line"
    echo "- Checksum: $checksum_line"
    echo ""
    echo "## Completed Steps"
    printf '%s\n' "$COMPLETED_STEPS" | sed '/^$/d' | while IFS= read -r step; do
      echo "- PASS: $step"
    done
    if [ "$SKIP_ARCHIVE" != "1" ]; then
      echo "- PASS: handover archive validation"
    fi
    echo ""
    if [ "$SKIP_ARCHIVE" != "1" ]; then
      echo "## Archive Validation Output"
      echo ""
      echo '```text'
      printf '%s\n' "$ARCHIVE_VALIDATION_OUTPUT"
      echo '```'
      echo ""
    fi
    echo "## Receiver Next Commands"
    echo ""
    echo '```bash'
    echo "ARCHIVE_PATH=$archive_line sh console/shell/mongoyia-validate-handover-archive.sh"
    echo "sh console/shell/mongoyia-test-profile-preflight.sh"
    echo "BASE_URL=https://<test-domain> sh console/shell/mongoyia-test-server-dry-run.sh"
    echo '```'
    echo ""
    echo 'Generated test-data cleanup was run with `--failOnPending=1`.'
  } > "$report_full"

  echo "Verification report: $report_full"
}

echo "Running Mongoyia handover verification from $ROOT"

run_php "handover package check" yii mongoyia-package-check/run "--interactive=0"
run_php "security hardcode scan" yii mongoyia-security-scan/run "--interactive=0"
run_script "input-gate smoke" sh console/shell/mongoyia-test-server-input-gate-smoke.sh
run_script "go/no-go smoke" sh console/shell/mongoyia-test-server-go-no-go-smoke.sh
run_php "generated test-data cleanup verification" yii mongoyia-test-cleanup/run "--failOnPending=1" "--interactive=0"

if [ "$SKIP_ARCHIVE" != "1" ]; then
  echo ""
  echo "== handover archive validation =="
  if [ "$ARCHIVE_PATH" = "" ]; then
    ARCHIVE_PATH=$(latest_archive)
    ARCHIVE_VALIDATION_OUTPUT=$(ARCHIVE_PATH=$ARCHIVE_PATH sh console/shell/mongoyia-validate-handover-archive.sh)
  else
    ARCHIVE_VALIDATION_OUTPUT=$(ARCHIVE_PATH=$ARCHIVE_PATH sh console/shell/mongoyia-validate-handover-archive.sh)
  fi
  printf '%s\n' "$ARCHIVE_VALIDATION_OUTPUT"
fi

write_report

echo ""
echo "Mongoyia handover verification: PASS"
