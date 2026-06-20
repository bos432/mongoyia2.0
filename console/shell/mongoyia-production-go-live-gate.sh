#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
EVIDENCE_DIR=${EVIDENCE_DIR:-runtime/handover}
OUTPUT_PATH=${OUTPUT_PATH:-}
EVIDENCE_SUMMARY_PATH=${EVIDENCE_SUMMARY_PATH:-}
BUSINESS_SIGNOFF=${BUSINESS_SIGNOFF:-PENDING}
PAYMENT_PRODUCTION_SIGNOFF=${PAYMENT_PRODUCTION_SIGNOFF:-PENDING}
SETTLEMENT_SIGNOFF=${SETTLEMENT_SIGNOFF:-PENDING}
MONITORING_ALERT_SIGNOFF=${MONITORING_ALERT_SIGNOFF:-PENDING}
BACKUP_RESTORE_DRILL_SIGNOFF=${BACKUP_RESTORE_DRILL_SIGNOFF:-PENDING}
ROLLBACK_OWNER_SIGNOFF=${ROLLBACK_OWNER_SIGNOFF:-PENDING}
SECURITY_SIGNOFF=${SECURITY_SIGNOFF:-PENDING}
LAUNCH_WINDOW_SIGNOFF=${LAUNCH_WINDOW_SIGNOFF:-PENDING}
APPROVER_REFERENCE=${APPROVER_REFERENCE:-}
CHANGE_TICKET=${CHANGE_TICKET:-}
PAYMENT_PRODUCTION_REFERENCE=${PAYMENT_PRODUCTION_REFERENCE:-}
SETTLEMENT_REFERENCE=${SETTLEMENT_REFERENCE:-}
MONITORING_ALERT_REFERENCE=${MONITORING_ALERT_REFERENCE:-}
BACKUP_RESTORE_DRILL_REFERENCE=${BACKUP_RESTORE_DRILL_REFERENCE:-}
ROLLBACK_OWNER_REFERENCE=${ROLLBACK_OWNER_REFERENCE:-}
SECURITY_REFERENCE=${SECURITY_REFERENCE:-}
LAUNCH_WINDOW_REFERENCE=${LAUNCH_WINDOW_REFERENCE:-}
FAIL_ON_PENDING=${FAIL_ON_PENDING:-0}

cd "$ROOT"

resolve_path() {
  case "$1" in
    /*|[A-Za-z]:*) printf '%s\n' "$1" ;;
    *) printf '%s\n' "$ROOT/$1" ;;
  esac
}

latest_file() {
  dir=$(resolve_path "$1")
  pattern=$2
  [ -d "$dir" ] || return 0
  find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1
}

read_result() {
  path=$1
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf '%s\n' PENDING
    return
  fi
  result=$(sed -n -E 's/^- (Result|Status): (PASS|WARN|FAIL)[[:space:]]*$/\2/p' "$path" | head -n 1)
  [ "$result" != "" ] && printf '%s\n' "$result" || printf '%s\n' UNKNOWN
}

normalize_status() {
  value=$(printf '%s' "$1" | tr '[:lower:]' '[:upper:]')
  case "$value" in
    PASS|WARN|FAIL|PENDING|BLOCKED) printf '%s\n' "$value" ;;
    "") printf '%s\n' PENDING ;;
    *) printf '%s\n' WARN ;;
  esac
}

reference_or_fallback() {
  if [ "$1" != "" ]; then printf '%s\n' "$1"; else printf '%s\n' "$CHANGE_TICKET"; fi
}

manual_status() {
  status=$(normalize_status "$1")
  reference=$2
  if [ "$status" = PASS ] && [ "$reference" = "" ]; then
    printf '%s\n' FAIL
  else
    printf '%s\n' "$status"
  fi
}

add_row() {
  gate=$1
  status=$2
  evidence=$3
  reference=$4
  notes=$5
  printf '| %s | %s | %s | %s | %s |\n' "$gate" "$status" "$evidence" "$reference" "$notes" >> "$ROWS"
  case "$status" in
    FAIL|UNKNOWN) FAILURES=$((FAILURES + 1)) ;;
    WARN|BLOCKED) WARNINGS=$((WARNINGS + 1)) ;;
    PENDING) PENDING=$((PENDING + 1)) ;;
  esac
}

if [ "$OUTPUT_PATH" = "" ]; then
  OUTPUT_PATH="runtime/handover/mongoyia-production-go-live-gate-$(date +%Y%m%d-%H%M%S).md"
fi
OUT=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUT")"
ROWS="$OUT.rows.tmp"
: > "$ROWS"

if [ "$EVIDENCE_SUMMARY_PATH" = "" ]; then
  EVIDENCE_SUMMARY_PATH=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-evidence-summary-*.md' || true)
else
  EVIDENCE_SUMMARY_PATH=$(resolve_path "$EVIDENCE_SUMMARY_PATH")
fi
load_test=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-load-test-evidence-*.md' || true)

FAILURES=0
WARNINGS=0
PENDING=0

add_row "Production evidence summary" "$(read_result "$EVIDENCE_SUMMARY_PATH")" "Latest generated production evidence summary" "$EVIDENCE_SUMMARY_PATH" "Must be PASS or explicitly accepted before launch."
add_row "Formal load test" "$(read_result "$load_test")" "Latest formal load-test evidence report" "$load_test" "Required before production traffic."
add_row "Business launch approval" "$(normalize_status "$BUSINESS_SIGNOFF")" "Business owner approved launch window" "$APPROVER_REFERENCE" "Record owner/ticket reference only."
payment_reference=$(reference_or_fallback "$PAYMENT_PRODUCTION_REFERENCE")
settlement_reference=$(reference_or_fallback "$SETTLEMENT_REFERENCE")
monitoring_reference=$(reference_or_fallback "$MONITORING_ALERT_REFERENCE")
backup_reference=$(reference_or_fallback "$BACKUP_RESTORE_DRILL_REFERENCE")
rollback_reference=$(reference_or_fallback "$ROLLBACK_OWNER_REFERENCE")
security_reference=$(reference_or_fallback "$SECURITY_REFERENCE")
launch_reference=$(reference_or_fallback "$LAUNCH_WINDOW_REFERENCE")
add_row "Payment production readiness" "$(manual_status "$PAYMENT_PRODUCTION_SIGNOFF" "$payment_reference")" "QPay/LianLian production credentials, callbacks, and provider portal reviewed" "$payment_reference" "PASS requires a non-sensitive provider/ticket reference."
add_row "Settlement and reconciliation" "$(manual_status "$SETTLEMENT_SIGNOFF" "$settlement_reference")" "Platform/seller settlement, refund reconciliation, and accounting owner confirmed" "$settlement_reference" "PASS requires an accounting/settlement owner reference."
add_row "Monitoring and alerting" "$(manual_status "$MONITORING_ALERT_SIGNOFF" "$monitoring_reference")" "Scheduler/monitoring alerts wired for production checks" "$monitoring_reference" "PASS requires an alerting runbook or ticket reference."
add_row "Backup restore drill" "$(manual_status "$BACKUP_RESTORE_DRILL_SIGNOFF" "$backup_reference")" "Backup restored to disposable database and verified" "$backup_reference" "PASS requires a restore-drill report reference."
add_row "Rollback ownership" "$(manual_status "$ROLLBACK_OWNER_SIGNOFF" "$rollback_reference")" "Rollback owner and database rollback rule confirmed" "$rollback_reference" "PASS requires a rollback owner/rule reference."
add_row "Security signoff" "$(manual_status "$SECURITY_SIGNOFF" "$security_reference")" "Secrets, TLS/WSS, callback signatures, upload limits, and access controls reviewed" "$security_reference" "PASS requires a hardening review reference."
add_row "Launch-window approval" "$(manual_status "$LAUNCH_WINDOW_SIGNOFF" "$launch_reference")" "Operator coverage and launch window approved" "$launch_reference" "PASS requires a launch/change-window reference."

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$PENDING" -gt 0 ] && [ "$FAIL_ON_PENDING" = "1" ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ] || [ "$PENDING" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia Production Go-Live Gate"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Pending: $PENDING"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Evidence dir: $(resolve_path "$EVIDENCE_DIR")"
  echo "- Evidence summary: $EVIDENCE_SUMMARY_PATH"
  echo "- Approver reference: $APPROVER_REFERENCE"
  echo "- Change ticket: $CHANGE_TICKET"
  echo
  echo "This gate is read-only. It does not run checks, switch traffic, restore databases, create orders, or trigger payment callbacks."
  echo
  echo "| Gate | Status | Evidence | Reference | Notes |"
  echo "|---|---:|---|---|---|"
  cat "$ROWS"
  echo
  echo "## Production Boundary"
  echo
  echo "A PASS report means the recorded evidence is complete enough for a production launch review. It is not a substitute for provider contracts, legal/compliance review, or business owner approval outside this repository."
  echo
  echo 'For final launch review, rerun with `FAIL_ON_PENDING=1` and set every signoff variable to `PASS` only after the responsible owner has approved it.'
} > "$OUT"
rm -f "$ROWS"

echo "Mongoyia production go-live gate: $RESULT"
echo "Report: $OUT"
[ "$FAILURES" -eq 0 ] && { [ "$FAIL_ON_PENDING" != "1" ] || [ "$PENDING" -eq 0 ]; }
