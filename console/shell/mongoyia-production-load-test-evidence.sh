#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
EVIDENCE_DIR=${EVIDENCE_DIR:-runtime/handover}
OUTPUT_PATH=${OUTPUT_PATH:-}
LOAD_SMOKE_PATH=${LOAD_SMOKE_PATH:-}
LOAD_TEST_REFERENCE=${LOAD_TEST_REFERENCE:-}
BROWSING_SIGNOFF=${BROWSING_SIGNOFF:-PENDING}
CHECKOUT_SIGNOFF=${CHECKOUT_SIGNOFF:-PENDING}
PAYMENT_CALLBACK_SIGNOFF=${PAYMENT_CALLBACK_SIGNOFF:-PENDING}
IM_CONCURRENCY_SIGNOFF=${IM_CONCURRENCY_SIGNOFF:-PENDING}
PEAK_USERS=${PEAK_USERS:-}
DURATION_MINUTES=${DURATION_MINUTES:-}
P95_MS=${P95_MS:-}
ERROR_RATE=${ERROR_RATE:-}
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

reference_status() {
  [ "$(printf '%s' "$1" | tr -d '[:space:]')" != "" ] && printf '%s\n' PASS || printf '%s\n' PENDING
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
  OUTPUT_PATH="runtime/handover/mongoyia-production-load-test-evidence-$(date +%Y%m%d-%H%M%S).md"
fi
OUT=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUT")"
ROWS="$OUT.rows.tmp"
: > "$ROWS"

if [ "$LOAD_SMOKE_PATH" = "" ]; then
  LOAD_SMOKE_PATH=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-load-smoke-*.md' || true)
else
  LOAD_SMOKE_PATH=$(resolve_path "$LOAD_SMOKE_PATH")
fi

FAILURES=0
WARNINGS=0
PENDING=0

add_row "Load smoke baseline" "$(read_result "$LOAD_SMOKE_PATH")" "Latest non-destructive load-smoke report" "$LOAD_SMOKE_PATH" "Local baseline before formal load testing."
add_row "Formal load-test report" "$(reference_status "$LOAD_TEST_REFERENCE")" "External load-test report reviewed" "$LOAD_TEST_REFERENCE" "Store report/ticket/sheet reference only."
add_row "Browsing scenario" "$(normalize_status "$BROWSING_SIGNOFF")" "Homepage/category/product browsing met agreed thresholds" "$LOAD_TEST_REFERENCE" "Include HTTP status, latency, and error-rate evidence."
add_row "Checkout scenario" "$(normalize_status "$CHECKOUT_SIGNOFF")" "Cart/checkout/order creation path met agreed thresholds" "$LOAD_TEST_REFERENCE" "Use sandbox or controlled non-production data."
add_row "Payment callback scenario" "$(normalize_status "$PAYMENT_CALLBACK_SIGNOFF")" "Payment callback throughput and idempotency under load reviewed" "$LOAD_TEST_REFERENCE" "Do not store provider secrets or raw callbacks."
add_row "IM concurrency scenario" "$(normalize_status "$IM_CONCURRENCY_SIGNOFF")" "IM WSS concurrency and reconnect behavior reviewed" "$LOAD_TEST_REFERENCE" "Use public WSS test domain evidence."

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$PENDING" -gt 0 ] && [ "$FAIL_ON_PENDING" = "1" ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ] || [ "$PENDING" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia Production Load-Test Evidence"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Pending: $PENDING"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Tester: $TESTER"
  echo "- Evidence dir: $(resolve_path "$EVIDENCE_DIR")"
  echo "- Load-test reference: $LOAD_TEST_REFERENCE"
  echo "- Peak users: $PEAK_USERS"
  echo "- Duration minutes: $DURATION_MINUTES"
  echo "- P95 ms: $P95_MS"
  echo "- Error rate: $ERROR_RATE"
  echo
  echo "This report is read-only. It records external load-test evidence and does not generate traffic, create orders, trigger callbacks, or connect to IM."
  echo
  echo "| Area | Status | Evidence | Reference | Notes |"
  echo "|---|---:|---|---|---|"
  cat "$ROWS"
  echo
  echo "## Required Formal Load Scope"
  echo
  echo "- Storefront browsing: homepage, category, product detail, cart page."
  echo "- Checkout flow: cart, address, order creation, order state verification."
  echo "- Payment callback flow: success, duplicate success, amount mismatch, invalid signature, expired timestamp."
  echo "- IM WSS flow: connect, send, receive, reconnect, history load, concurrent users."
  echo
  echo 'For final production signoff, rerun with `FAIL_ON_PENDING=1` and set every scenario signoff to `PASS` only after the formal load-test owner approves the evidence.'
} > "$OUT"
rm -f "$ROWS"

echo "Mongoyia production load-test evidence: $RESULT"
echo "Report: $OUT"
[ "$FAILURES" -eq 0 ] && { [ "$FAIL_ON_PENDING" != "1" ] || [ "$PENDING" -eq 0 ]; }
