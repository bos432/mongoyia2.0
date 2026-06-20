#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}
AUTO_RECEIVE_DAYS=${AUTO_RECEIVE_DAYS:-7}
FEE_LIMIT=${FEE_LIMIT:-100}
STORE_ID=${STORE_ID:-0}
APPLY_AUTO_RECEIVE=${APPLY_AUTO_RECEIVE:-0}
SKIP_FIXTURE=${SKIP_FIXTURE:-0}
STRICT=${STRICT:-0}

cd "$ROOT"

resolve_path() {
  case "$1" in
    /*|[A-Za-z]:*) printf '%s\n' "$1" ;;
    *) printf '%s\n' "$ROOT/$1" ;;
  esac
}

add_step() {
  name=$1
  status=$2
  code=$3
  detail=$4
  printf '| %s | %s | %s | %s |\n' "$name" "$status" "$code" "$detail" >> "$ROWS"
  [ "$status" = "FAIL" ] && FAILURES=$((FAILURES + 1))
  [ "$status" = "WARN" ] && WARNINGS=$((WARNINGS + 1))
}

run_yii() {
  name=$1
  shift
  if php yii "$@"; then
    add_step "$name" PASS 0 completed
  else
    code=$?
    add_step "$name" FAIL "$code" "command failed"
  fi
}

OUT_DIR=$(resolve_path "$OUTPUT_DIR")
mkdir -p "$OUT_DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
SUMMARY="$OUT_DIR/mongoyia-phase3-scheduled-ops-$STAMP.md"
ROWS="$SUMMARY.rows.tmp"
: > "$ROWS"
FAILURES=0
WARNINGS=0

if [ "$SKIP_FIXTURE" = "1" ]; then
  add_step "Fixture Checks" WARN 0 "skipped by operator"
else
  run_yii "Fee Deduction Fixture" mongoyia-logistics-fee-deduction-test/run --interactive=0
  run_yii "Fee Reconciliation Fixture" mongoyia-logistics-fee-reconciliation/run --fixture=1 --interactive=0
  run_yii "Status Batch Fixture" mongoyia-logistics-status-batch/run --fixture=1 --interactive=0
  run_yii "Port Review Fixture" mongoyia-logistics-port-review/run --fixture=1 --interactive=0
fi

fee_args="mongoyia-logistics-fee-reconciliation/run --limit=$FEE_LIMIT --interactive=0"
[ "$STORE_ID" -gt 0 ] && fee_args="$fee_args --storeId=$STORE_ID"
[ "$STRICT" = "1" ] && fee_args="$fee_args --strict=1"
# shellcheck disable=SC2086
run_yii "Current Fee Reconciliation" $fee_args

auto_args="mongoyia-auto-receive/run --days=$AUTO_RECEIVE_DAYS --interactive=0"
[ "$APPLY_AUTO_RECEIVE" = "1" ] && auto_args="$auto_args --apply=1"
# shellcheck disable=SC2086
run_yii "Auto Receive" $auto_args

run_yii "Generated Data Cleanup Verification" mongoyia-test-cleanup/run --failOnPending=1 --interactive=0

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia Phase 3 Scheduled Ops"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Auto receive days: $AUTO_RECEIVE_DAYS"
  echo "- Auto receive apply: $APPLY_AUTO_RECEIVE"
  echo "- Fee reconciliation limit: $FEE_LIMIT"
  echo "- Store id: $STORE_ID"
  echo
  echo "Default mode is safe/read-only for business data except rollback-clean fixtures. Set APPLY_AUTO_RECEIVE=1 only after reviewing the dry-run output."
  echo
  echo "| Step | Status | Exit Code | Detail |"
  echo "|---|---:|---:|---|"
  cat "$ROWS"
} > "$SUMMARY"
rm -f "$ROWS"

echo "Mongoyia Phase 3 scheduled ops: $RESULT"
echo "Report: $SUMMARY"
[ "$FAILURES" -eq 0 ]
