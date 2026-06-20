#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
BACKUP_ARCHIVE=${BACKUP_ARCHIVE:-}
UPLOAD_ARCHIVE=${UPLOAD_ARCHIVE:-}
BASE_URL=${BASE_URL:-}
IM_URL=${IM_URL:-}
STRICT_HEALTH=${STRICT_HEALTH:-0}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}
SKIP_MONITOR=${SKIP_MONITOR:-0}
SKIP_HEALTH=${SKIP_HEALTH:-0}
SKIP_BACKUP_VERIFY=${SKIP_BACKUP_VERIFY:-0}
SKIP_LOAD_SMOKE=${SKIP_LOAD_SMOKE:-0}
SKIP_IM_PORT=${SKIP_IM_PORT:-0}
SKIP_LOAD_SMOKE_IM=${SKIP_LOAD_SMOKE_IM:-0}

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
  report=$4
  detail=$5
  printf '| %s | %s | %s | %s | %s |\n' "$name" "$status" "$code" "$report" "$detail" >> "$ROWS"
  [ "$status" = "FAIL" ] && FAILURES=$((FAILURES + 1))
  [ "$status" = "WARN" ] && WARNINGS=$((WARNINGS + 1))
}

run_step() {
  name=$1
  report=$2
  shift 2
  if "$@"; then
    add_step "$name" PASS 0 "$report" completed
  else
    code=$?
    add_step "$name" FAIL "$code" "$report" "command failed"
  fi
}

OUT_DIR=$(resolve_path "$OUTPUT_DIR")
mkdir -p "$OUT_DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
SUMMARY="$OUT_DIR/mongoyia-production-scheduled-check-$STAMP.md"
ROWS="$SUMMARY.rows.tmp"
: > "$ROWS"
FAILURES=0
WARNINGS=0

if [ "$SKIP_MONITOR" = "1" ]; then
  add_step Monitor WARN 0 "" "skipped by operator"
else
  report="$OUT_DIR/mongoyia-production-monitor-$STAMP.md"
  run_step Monitor "$report" env OUTPUT_PATH="$report" PHP_ENV="$PHP_ENV" IM_ENV="$IM_ENV" SKIP_IM_PORT="$SKIP_IM_PORT" sh "$SCRIPT_DIR/mongoyia-production-monitor.sh"
fi

if [ "$SKIP_HEALTH" = "1" ]; then
  add_step Health WARN 0 "" "skipped by operator"
else
  report="$OUT_DIR/mongoyia-production-health-$STAMP.md"
  run_step Health "$report" env OUTPUT_PATH="$report" PHP_ENV="$PHP_ENV" IM_ENV="$IM_ENV" STRICT="$STRICT_HEALTH" SKIP_CONNECTIVITY="$SKIP_CONNECTIVITY" sh "$SCRIPT_DIR/mongoyia-production-health.sh"
fi

if [ "$SKIP_BACKUP_VERIFY" = "1" ]; then
  add_step "Backup Verify" WARN 0 "" "skipped by operator"
else
  report="$OUT_DIR/mongoyia-production-backup-verify-$STAMP.md"
  run_step "Backup Verify" "$report" env REPORT_PATH="$report" BACKUP_ARCHIVE="$BACKUP_ARCHIVE" UPLOAD_ARCHIVE="$UPLOAD_ARCHIVE" sh "$SCRIPT_DIR/mongoyia-production-backup-verify.sh"
fi

if [ "$SKIP_LOAD_SMOKE" = "1" ]; then
  add_step "Load Smoke" WARN 0 "" "skipped by operator"
elif [ "$BASE_URL" = "" ]; then
  add_step "Load Smoke" WARN 0 "" "skipped because BASE_URL was not provided"
else
  report="$OUT_DIR/mongoyia-production-load-smoke-$STAMP.md"
  run_step "Load Smoke" "$report" env OUTPUT_PATH="$report" BASE_URL="$BASE_URL" IM_URL="$IM_URL" SKIP_IM="$SKIP_LOAD_SMOKE_IM" sh "$SCRIPT_DIR/mongoyia-production-load-smoke.sh"
fi

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia Production Scheduled Check"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- PHP env: $PHP_ENV"
  echo "- IM env: $IM_ENV"
  echo "- Base URL: $BASE_URL"
  echo "- IM URL: $IM_URL"
  echo
  echo "A scheduler or alerting system should alert on non-zero exit code or Result=FAIL."
  echo
  echo "| Step | Status | Exit Code | Report | Detail |"
  echo "|---|---:|---:|---|---|"
  cat "$ROWS"
} > "$SUMMARY"
rm -f "$ROWS"

echo "Mongoyia production scheduled check: $RESULT"
echo "Report: $SUMMARY"
[ "$FAILURES" -eq 0 ]
