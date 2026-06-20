#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
ACCEPTANCE_DIR=${ACCEPTANCE_DIR:-runtime/acceptance}
OUTPUT_PATH=${OUTPUT_PATH:-}
BASE_URL=${BASE_URL:-}
QPAY_SIGNOFF=${QPAY_SIGNOFF:-PENDING}
LIANLIAN_SIGNOFF=${LIANLIAN_SIGNOFF:-PENDING}
QPAY_REFERENCE=${QPAY_REFERENCE:-}
LIANLIAN_REFERENCE=${LIANLIAN_REFERENCE:-}
TESTER=${TESTER:-}
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
  result=$(sed -n -E 's/^- Result: (PASS|WARN|FAIL)[[:space:]]*$/\1/p' "$path" | head -n 1)
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

add_row() {
  area=$1
  status=$2
  evidence=$3
  reference=$4
  notes=$5
  printf '| %s | %s | %s | %s | %s |\n' "$area" "$status" "$evidence" "$reference" "$notes" >> "$ROWS"
  case "$status" in
    FAIL|UNKNOWN) FAILURES=$((FAILURES + 1)) ;;
    WARN|BLOCKED) WARNINGS=$((WARNINGS + 1)) ;;
    PENDING) PENDING=$((PENDING + 1)) ;;
  esac
}

if [ "$OUTPUT_PATH" = "" ]; then
  OUTPUT_PATH="runtime/handover/mongoyia-payment-sandbox-evidence-$(date +%Y%m%d-%H%M%S).md"
fi
OUT=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUT")"
ROWS="$OUT.rows.tmp"
: > "$ROWS"

FAILURES=0
WARNINGS=0
PENDING=0

acceptance=$(latest_file "$ACCEPTANCE_DIR" 'mongoyia-acceptance-*.md' || true)
acceptance_result=$(read_result "$acceptance")
payment_regression_status=PENDING
if [ "$acceptance" != "" ] && [ -f "$acceptance" ]; then
  if awk '/^### payment regression/{seen=1} seen && /^- Exit code: 0/{found=1} /^### / && seen && $0 !~ /^### payment regression/{seen=0} END{exit found ? 0 : 1}' "$acceptance"; then
    payment_regression_status=PASS
  elif awk '/^### payment regression/{seen=1} seen && /^- Exit code: [1-9]/{found=1} /^### / && seen && $0 !~ /^### payment regression/{seen=0} END{exit found ? 0 : 1}' "$acceptance"; then
    payment_regression_status=FAIL
  elif [ "$acceptance_result" = "PASS" ]; then
    payment_regression_status=WARN
  fi
fi

add_row "Acceptance report" "$acceptance_result" "Latest acceptance report" "$acceptance" "Must be test profile strict acceptance for final P2 signoff."
add_row "Automated payment regression" "$payment_regression_status" "Acceptance step: payment regression" "$acceptance" "Covers local callback success, duplicate, amount mismatch, HMAC, timestamp, refund, and shipment paths."
add_row "QPay sandbox portal" "$(normalize_status "$QPAY_SIGNOFF")" "Provider sandbox callback/invoice flow reviewed" "$QPAY_REFERENCE" "Use ticket ID or screenshot reference only; do not store credentials."
add_row "LianLian sandbox portal" "$(normalize_status "$LIANLIAN_SIGNOFF")" "Provider sandbox callback flow reviewed" "$LIANLIAN_REFERENCE" "Use ticket ID or screenshot reference only; do not store keys."

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$PENDING" -gt 0 ] && [ "$FAIL_ON_PENDING" = "1" ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ] || [ "$PENDING" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia Payment Sandbox Evidence"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Pending: $PENDING"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Tester: $TESTER"
  echo "- Base URL: $BASE_URL"
  echo "- Acceptance report: $acceptance"
  echo
  echo "This report is non-sensitive. It must not contain real payment credentials, private keys, callback HMAC secrets, auth headers, or raw provider secrets."
  echo
  echo "| Area | Status | Evidence | Reference | Notes |"
  echo "|---|---:|---|---|---|"
  cat "$ROWS"
  echo
  echo "## Required Sandbox Cases"
  echo
  echo "| Provider | Case | Expected Result | Actual/Reference |"
  echo "|---|---|---|---|"
  echo "| QPay | Create sandbox invoice/payment | Provider accepts sandbox merchant config |  |"
  echo "| QPay | Success callback | Parent and seller child orders become paid once |  |"
  echo "| QPay | Duplicate success callback | No duplicate stock deduction or duplicate success side effects |  |"
  echo "| QPay | Amount mismatch | Callback rejected and audited |  |"
  echo "| QPay | Bad/missing signature | Callback rejected and audited when HMAC is enabled |  |"
  echo "| QPay | Expired timestamp | Callback rejected and audited when max-age is enabled |  |"
  echo "| LianLian | Create sandbox payment | Provider accepts sandbox merchant config |  |"
  echo "| LianLian | Success callback | Parent and seller child orders become paid once |  |"
  echo "| LianLian | Duplicate success callback | No duplicate stock deduction or duplicate success side effects |  |"
  echo "| LianLian | Amount mismatch | Callback rejected and audited |  |"
  echo "| LianLian | Bad/missing signature | Callback rejected and audited when HMAC is enabled |  |"
  echo "| LianLian | Expired timestamp | Callback rejected and audited when max-age is enabled |  |"
  echo
  echo "## Suggested Commands"
  echo
  echo '```bash'
  echo 'php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --interactive=0'
  echo 'php yii mongoyia-payment-audit/run --strict=1 --interactive=0'
  echo 'php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0'
  echo '```'
  echo
  echo 'For final P2 signoff, rerun this script with `FAIL_ON_PENDING=1` and set `QPAY_SIGNOFF=PASS` / `LIANLIAN_SIGNOFF=PASS` only after provider sandbox evidence is reviewed.'
} > "$OUT"
rm -f "$ROWS"

echo "Mongoyia payment sandbox evidence: $RESULT"
echo "Report: $OUT"
[ "$FAILURES" -eq 0 ] && { [ "$FAIL_ON_PENDING" != "1" ] || [ "$PENDING" -eq 0 ]; }
