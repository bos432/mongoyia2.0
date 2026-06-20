#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
EVIDENCE_DIR=${EVIDENCE_DIR:-runtime/handover}
ACCEPTANCE_DIR=${ACCEPTANCE_DIR:-runtime/acceptance}
OUTPUT_PATH=${OUTPUT_PATH:-}
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

add_gate() {
  gate=$1
  evidence=$2
  path=$3
  owner=$4
  notes=$5
  status=$(read_result "$path")
  printf '| %s | %s | %s | %s | %s | %s |\n' "$gate" "$status" "$evidence" "$path" "$owner" "$notes" >> "$ROWS"
  case "$status" in
    FAIL|UNKNOWN) FAILURES=$((FAILURES + 1)) ;;
    WARN) WARNINGS=$((WARNINGS + 1)) ;;
    PENDING) PENDING=$((PENDING + 1)) ;;
  esac
}

if [ "$OUTPUT_PATH" = "" ]; then
  OUTPUT_PATH="runtime/handover/mongoyia-production-evidence-summary-$(date +%Y%m%d-%H%M%S).md"
fi
OUT=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUT")"
ROWS="$OUT.rows.tmp"
: > "$ROWS"
FAILURES=0
WARNINGS=0
PENDING=0

acceptance=$(latest_file "$ACCEPTANCE_DIR" 'mongoyia-acceptance-*.md' || true)
p2_evidence=$(latest_file "$EVIDENCE_DIR" 'mongoyia-p2-evidence-pack-*.md' || true)
payment_sandbox=$(latest_file "$EVIDENCE_DIR" 'mongoyia-payment-sandbox-evidence-*.md' || true)
im_wss=$(latest_file "$EVIDENCE_DIR" 'mongoyia-im-wss-evidence-*.md' || true)
scheduled=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-scheduled-check-*.md' || true)
health=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-health-*.md' || true)
monitor=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-monitor-*.md' || true)
backup_verify=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-backup-verify-*.md' || true)
load_smoke=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-load-smoke-*.md' || true)
load_test=$(latest_file "$EVIDENCE_DIR" 'mongoyia-production-load-test-evidence-*.md' || true)
mongolian_review=$(latest_file "$EVIDENCE_DIR" 'mongoyia-mongolian-review-evidence-*.md' || true)
handover_verify=$(latest_file "$EVIDENCE_DIR" 'mongoyia-handover-verify-*.md' || true)
preflight=$(latest_file "$EVIDENCE_DIR" 'mongoyia-test-server-preflight-*.md' || true)

add_gate "Test-server acceptance" "Latest acceptance report" "$acceptance" "QA/business" "Required before production launch."
add_gate "P2 evidence pack" "Latest P2 evidence pack report" "$p2_evidence" "QA/Ops" "Restore, preflight, acceptance, payment sandbox, and IM WSS review bundle."
add_gate "Payment sandbox evidence" "Latest payment sandbox evidence report" "$payment_sandbox" "Payment/Ops" "QPay/LianLian sandbox callback signoff without secrets."
add_gate "IM WSS evidence" "Latest IM WSS evidence report" "$im_wss" "IM/Ops" "Public WSS healthcheck, regression, TLS, reverse-proxy, and service-manager evidence."
add_gate "Handover integrity" "Latest handover verification report" "$handover_verify" "Engineering" "Confirms package and local checks."
add_gate "Test-server preflight" "Latest test-server preflight report" "$preflight" "Ops" "Required before restore/apply."
add_gate "Scheduled monitoring" "Latest scheduled-check summary" "$scheduled" "Ops" "Cron/Task Scheduler should alert on failure."
add_gate "Production health" "Latest production health report" "$health" "Engineering/Ops" "Includes deploy-check, security, payment audit, order integrity, translation audit, cleanup dry-run."
add_gate "Production monitor" "Latest monitor report" "$monitor" "Ops" "Runtime/env/Redis/IM/disk/log report."
add_gate "Backup verification" "Latest backup-verify report" "$backup_verify" "Ops" "Checksum and archive readability evidence."
add_gate "Load smoke" "Latest load-smoke report" "$load_smoke" "Engineering/Ops" "Non-destructive storefront and optional IM concurrency smoke."
add_gate "Formal load test" "Latest formal load-test evidence report" "$load_test" "Engineering/Ops/business" "Browsing, checkout, payment callback, and IM concurrency load evidence."
add_gate "Mongolian review" "Latest Mongolian review evidence report" "$mongolian_review" "Native/business reviewer" "Human review and image-text signoff evidence."

if [ "$FAILURES" -gt 0 ]; then RESULT=FAIL
elif [ "$PENDING" -gt 0 ] && [ "$FAIL_ON_PENDING" = "1" ]; then RESULT=FAIL
elif [ "$WARNINGS" -gt 0 ] || [ "$PENDING" -gt 0 ]; then RESULT=WARN
else RESULT=PASS
fi

{
  echo "# Mongoyia Production Evidence Summary"
  echo
  echo "- Result: $RESULT"
  echo "- Failures: $FAILURES"
  echo "- Warnings: $WARNINGS"
  echo "- Pending: $PENDING"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Evidence dir: $(resolve_path "$EVIDENCE_DIR")"
  echo "- Acceptance dir: $(resolve_path "$ACCEPTANCE_DIR")"
  echo
  echo "This summary is read-only. It does not run checks, restore databases, create orders, or trigger payment callbacks."
  echo
  echo "| Gate | Status | Evidence | Report | Owner | Notes |"
  echo "|---|---:|---|---|---|---|"
  cat "$ROWS"
  echo
  echo "## Required Manual Evidence"
  echo
  echo "- Payment provider sandbox and production credential signoff."
  echo "- IM WSS public-domain regression and reverse-proxy/TLS signoff."
  echo '- Mongolian native/business content signoff, recorded by `mongoyia-mongolian-review-evidence`.'
  echo '- Formal load-test signoff, recorded by `mongoyia-production-load-test-evidence`.'
  echo "- Backup restore drill to a disposable database."
  echo "- Rollout owner, rollback owner, and launch-window approval."
  echo
  echo 'Use `docs/mongoyia-external-integration-inputs.md` and `docs/mongoyia-production-rollout-rollback.md` to record external/manual evidence that cannot be generated locally.'
} > "$OUT"
rm -f "$ROWS"

echo "Mongoyia production evidence summary: $RESULT"
echo "Report: $OUT"
[ "$FAILURES" -eq 0 ] && { [ "$FAIL_ON_PENDING" != "1" ] || [ "$PENDING" -eq 0 ]; }
