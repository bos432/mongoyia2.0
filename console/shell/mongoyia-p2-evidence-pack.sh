#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
EVIDENCE_DIR=${EVIDENCE_DIR:-runtime/handover}
ACCEPTANCE_DIR=${ACCEPTANCE_DIR:-runtime/acceptance}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
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

copy_if_present() {
  src=$1
  rel=$2
  if [ "$src" = "" ] || [ ! -f "$src" ]; then
    return 0
  fi
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$src" "$STAGE/$rel"
}

add_report() {
  gate=$1
  dir=$2
  pattern=$3
  owner=$4
  required=$5
  notes=$6
  path=$(latest_file "$dir" "$pattern" || true)
  status=$(read_result "$path")
  report=""
  if [ "$path" != "" ]; then
    report="reports/$(basename "$path")"
    copy_if_present "$path" "$report"
  fi

  case "$status" in
    FAIL) FAILURES=$((FAILURES + 1)) ;;
    WARN|UNKNOWN) WARNINGS=$((WARNINGS + 1)) ;;
    PENDING)
      if [ "$required" = "yes" ]; then PENDING=$((PENDING + 1)); fi
      ;;
  esac

  printf '| %s | %s | %s | %s | %s | %s |\n' "$gate" "$status" "$required" "$report" "$owner" "$notes" >> "$ROWS"
}

OUTPUT_ROOT=$(resolve_path "$OUTPUT_DIR")
STAGE="$OUTPUT_ROOT/mongoyia-p2-evidence-pack-$STAMP"
ARCHIVE_PATH="$OUTPUT_ROOT/mongoyia-p2-evidence-pack-$STAMP.tar.gz"
mkdir -p "$OUTPUT_ROOT"
rm -rf "$STAGE"
mkdir -p "$STAGE"

ROWS="$STAGE/rows.tmp"
: > "$ROWS"
FAILURES=0
WARNINGS=0
PENDING=0

add_report "External input gate" "$EVIDENCE_DIR" "mongoyia-test-server-input-gate-*.md" "Ops/engineering" yes "Must pass before restore apply."
add_report "P2 readiness" "$EVIDENCE_DIR" "mongoyia-p2-readiness-*.md" "Ops/engineering" yes "Confirms HTTPS/WSS/payment/test inputs are not placeholders."
add_report "Restore plan" "$EVIDENCE_DIR" "mongoyia-test-server-restore-plan-*.md" "Ops" yes "Command plan reviewed before apply."
add_report "Restore execution" "$EVIDENCE_DIR" "mongoyia-test-server-restore-*.md" "Ops" yes "Dry-run or apply report from restore orchestrator."
add_report "Go/no-go" "$EVIDENCE_DIR" "mongoyia-test-server-go-no-go-*.md" "Ops/QA" yes "NO-GO blocks restore apply."
add_report "Preflight" "$EVIDENCE_DIR" "mongoyia-test-server-preflight-*.md" "Engineering" yes "Strict test profile preflight."
add_report "Payment sandbox evidence" "$EVIDENCE_DIR" "mongoyia-payment-sandbox-evidence-*.md" "Payment/Ops" yes "QPay/LianLian sandbox signoff without secrets."
add_report "IM WSS evidence" "$EVIDENCE_DIR" "mongoyia-im-wss-evidence-*.md" "IM/Ops" yes "Public-domain WSS, reverse-proxy, TLS, and IM regression signoff."
add_report "Acceptance" "$ACCEPTANCE_DIR" "mongoyia-acceptance-*.md" "QA/business" yes "Full storefront/payment/IM/backend acceptance."
add_report "Signoff" "$ACCEPTANCE_DIR" "mongoyia-signoff-*.md" "QA/business" yes "Human-readable signoff summary."
add_report "Risk register" "$ACCEPTANCE_DIR" "mongoyia-risk-register-*.md" "Engineering/business" yes "Known risks and owner decisions."
add_report "Delivery index" "$ACCEPTANCE_DIR" "mongoyia-delivery-index-*.md" "Engineering" no "Final generated handover index."
add_report "Handoff status" "$EVIDENCE_DIR" "mongoyia-handoff-status-*.md" "Engineering" no "Latest artifact and missing-input summary."
add_report "Production evidence summary" "$EVIDENCE_DIR" "mongoyia-production-evidence-summary-*.md" "Ops/engineering" no "Production-readiness rehearsal index."

copy_if_present "$ROOT/docs/mongoyia-external-integration-inputs.md" "docs/mongoyia-external-integration-inputs.md"
copy_if_present "$ROOT/docs/mongoyia-acceptance-signoff-template.md" "docs/mongoyia-acceptance-signoff-template.md"
copy_if_present "$ROOT/docs/mongoyia-p2-evidence-pack.md" "docs/mongoyia-p2-evidence-pack.md"

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$PENDING" -gt 0 ] && [ "$FAIL_ON_PENDING" = "1" ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ] || [ "$PENDING" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia P2 Evidence Pack"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Pending required evidence: $PENDING"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Evidence dir: $(resolve_path "$EVIDENCE_DIR")"
  echo "- Acceptance dir: $(resolve_path "$ACCEPTANCE_DIR")"
  echo
  echo "This pack is read-only. It only copies existing reports and docs; it does not restore databases, create orders, call payment gateways, or connect to IM."
  echo
  echo "| Gate | Status | Required | Included report | Owner | Notes |"
  echo "|---|---:|---:|---|---|---|"
  cat "$ROWS"
  echo
  echo "## Manual Attachments"
  echo
  echo "- Test-server URL and account handoff record."
  echo "- Payment provider sandbox portal screenshots or ticket references."
  echo "- IM WSS reverse-proxy/TLS ticket references."
  echo "- Backup snapshot/archive reference and restore-drill note."
  echo "- Business acceptance owner and date."
  echo
  echo 'Do not add secrets, private keys, raw payment credentials, SSH keys, or real `.env` files to this evidence pack.'
} > "$STAGE/MANIFEST.md"
rm -f "$ROWS"

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

echo "Mongoyia P2 evidence pack: $RESULT"
echo "Folder: $STAGE"
echo "Archive: $ARCHIVE_PATH"
echo "Checksum: $ARCHIVE_PATH.sha256"
[ "$FAILURES" -eq 0 ] && { [ "$FAIL_ON_PENDING" != "1" ] || [ "$PENDING" -eq 0 ]; }
