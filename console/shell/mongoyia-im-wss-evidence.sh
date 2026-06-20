#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
ACCEPTANCE_DIR=${ACCEPTANCE_DIR:-runtime/acceptance}
OUTPUT_PATH=${OUTPUT_PATH:-}
IM_URL=${IM_URL:-}
WSS_SIGNOFF=${WSS_SIGNOFF:-PENDING}
REVERSE_PROXY_REFERENCE=${REVERSE_PROXY_REFERENCE:-}
TLS_REFERENCE=${TLS_REFERENCE:-}
SERVICE_MANAGER_REFERENCE=${SERVICE_MANAGER_REFERENCE:-}
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

step_status() {
  path=$1
  step=$2
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf '%s\n' PENDING
    return
  fi
  if awk -v step="### $step" '$0 == step {seen=1; next} /^### / && seen {seen=0} seen && /^- Exit code: 0/ {found=1} END{exit found ? 0 : 1}' "$path"; then
    printf '%s\n' PASS
  elif awk -v step="### $step" '$0 == step {seen=1; next} /^### / && seen {seen=0} seen && /^- Exit code: [1-9]/ {found=1} END{exit found ? 0 : 1}' "$path"; then
    printf '%s\n' FAIL
  else
    printf '%s\n' PENDING
  fi
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
  [ "$1" != "" ] && printf '%s\n' PASS || printf '%s\n' PENDING
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
  OUTPUT_PATH="runtime/handover/mongoyia-im-wss-evidence-$(date +%Y%m%d-%H%M%S).md"
fi
OUT=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUT")"
ROWS="$OUT.rows.tmp"
: > "$ROWS"

FAILURES=0
WARNINGS=0
PENDING=0
acceptance=$(latest_file "$ACCEPTANCE_DIR" 'mongoyia-acceptance-*.md' || true)

add_row "Acceptance report" "$(read_result "$acceptance")" "Latest acceptance report" "$acceptance" "Must be test profile strict acceptance for final P2 signoff."
add_row "IM healthcheck" "$(step_status "$acceptance" "IM healthcheck")" "Acceptance step: IM healthcheck" "$acceptance" "Opens WSS, registers a temporary user, and verifies DB-backed chat_list."
add_row "IM chat regression" "$(step_status "$acceptance" "IM chat regression")" "Acceptance step: IM chat regression" "$acceptance" "Verifies user/merchant send, history, auth rejection, scope rejection, and payload rejection."
add_row "IM concurrency regression" "$(step_status "$acceptance" "IM concurrency regression")" "Acceptance step: IM concurrency regression" "$acceptance" "Lightweight concurrent WSS users."
add_row "Public WSS signoff" "$(normalize_status "$WSS_SIGNOFF")" "Public WSS URL reviewed" "$IM_URL" "Must use wss:// and the real test domain."
add_row "Reverse proxy" "$(reference_status "$REVERSE_PROXY_REFERENCE")" "Proxy route forwards upgrade traffic to Python IM" "$REVERSE_PROXY_REFERENCE" "Store ticket/config reference only."
add_row "TLS certificate" "$(reference_status "$TLS_REFERENCE")" "Valid certificate and renewal owner reviewed" "$TLS_REFERENCE" "Store certificate/ticket reference only."
add_row "Service manager" "$(reference_status "$SERVICE_MANAGER_REFERENCE")" "systemd/Supervisor/Windows service guard reviewed" "$SERVICE_MANAGER_REFERENCE" "Store unit/process-manager reference only."

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$PENDING" -gt 0 ] && [ "$FAIL_ON_PENDING" = "1" ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ] || [ "$PENDING" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia IM WSS Evidence"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Pending: $PENDING"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Tester: $TESTER"
  echo "- IM URL: $IM_URL"
  echo "- Acceptance report: $acceptance"
  echo
  echo 'This report is non-sensitive. It must not contain real `.env` files, IM auth secrets, database passwords, SSH keys, or private network credentials.'
  echo
  echo "| Area | Status | Evidence | Reference | Notes |"
  echo "|---|---:|---|---|---|"
  cat "$ROWS"
  echo
  echo "## Required WSS Cases"
  echo
  echo "| Case | Expected Result | Actual/Reference |"
  echo "|---|---|---|"
  echo '| Public WSS URL | Browser-facing URL uses `wss://<test-domain>/<im-path>` |  |'
  echo '| Reverse proxy upgrade | Proxy preserves `Upgrade` and `Connection` headers |  |'
  echo '| Python IM bind | `IM_HOST` is a bind host and `IM_PORT` is reachable from proxy |  |'
  echo '| Shared auth secret | PHP and Python IM use the same secret on the target server |  |'
  echo '| Healthcheck | `im-healthcheck.py` passes against public WSS URL |  |'
  echo '| Chat regression | user/merchant send, history, auth, scope, and payload checks pass |  |'
  echo '| Concurrency | lightweight concurrent WSS users pass |  |'
  echo '| Persistence | refresh/reconnect can load prior chat history |  |'
  echo '| Service guard | systemd/Supervisor/Windows service is enabled and restart policy is set |  |'
  echo
  echo "## Suggested Commands"
  echo
  echo '```bash'
  echo 'python ../../im*/im*/scripts/im-healthcheck.py --url wss://<test-domain>/<im-path>'
  echo 'python ../../im*/im*/scripts/im-regression.py --url wss://<test-domain>/<im-path> --merchant-uid 37 --product-id 102 --store-id 9'
  echo 'python ../../im*/im*/scripts/im-concurrency.py --url wss://<test-domain>/<im-path> --merchant-uid 37 --product-id 102 --store-id 9 --users 5'
  echo 'php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --imUrl=wss://<test-domain>/<im-path> --interactive=0'
  echo '```'
  echo
  echo 'For final P2 signoff, rerun this script with `FAIL_ON_PENDING=1` and set `WSS_SIGNOFF=PASS` only after public-domain WSS, reverse proxy, TLS, and service-manager evidence is reviewed.'
} > "$OUT"
rm -f "$ROWS"

echo "Mongoyia IM WSS evidence: $RESULT"
echo "Report: $OUT"
[ "$FAILURES" -eq 0 ] && { [ "$FAIL_ON_PENDING" != "1" ] || [ "$PENDING" -eq 0 ]; }
