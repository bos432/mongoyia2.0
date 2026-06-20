#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-production-health-$STAMP.md}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
STRICT=${STRICT:-0}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}

cd "$ROOT"
case "$OUTPUT_PATH" in
  /*|[A-Za-z]:*) OUT="$OUTPUT_PATH" ;;
  *) OUT="$ROOT/$OUTPUT_PATH" ;;
esac
mkdir -p "$(dirname "$OUT")"
TMP="$OUT.tmp"
: > "$TMP"
failures=0

run_step() {
  name=$1
  shift
  cmd="$*"
  echo "## $name" >> "$TMP"
  echo >> "$TMP"
  echo '```text' >> "$TMP"
  echo "$cmd" >> "$TMP"
  echo '```' >> "$TMP"
  echo >> "$TMP"
  echo "Output:" >> "$TMP"
  echo >> "$TMP"
  echo '```text' >> "$TMP"
  if sh -c "$cmd" >> "$TMP" 2>&1; then
    status=PASS
    code=0
  else
    status=FAIL
    code=$?
    failures=$((failures + 1))
  fi
  echo '```' >> "$TMP"
  echo >> "$TMP"
  echo "- Status: $status" >> "$TMP"
  echo "- Exit code: $code" >> "$TMP"
  echo >> "$TMP"
}

run_step "Deployment prod profile" "php yii deploy-check/run --profile=prod --strict=$STRICT --skipConnectivity=$SKIP_CONNECTIVITY --phpEnv='$PHP_ENV' --imEnv='$IM_ENV' --interactive=0"
run_step "Security scan" "php yii mongoyia-security-scan/run --interactive=0"
run_step "Payment audit" "php yii mongoyia-payment-audit/run --interactive=0"
run_step "Order integrity" "php yii mongoyia-order-integrity/run --interactive=0"
run_step "Translation audit" "php yii mongoyia-translation-audit/run --interactive=0"
run_step "Generated test-data cleanup dry-run" "php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0"

if [ "$failures" -gt 0 ]; then result=FAIL; else result=PASS; fi
{
  echo "# Mongoyia Production Health Report"
  echo
  echo "- Result: $result"
  echo "- Failures: $failures"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- PHP env: $PHP_ENV"
  echo "- IM env: $IM_ENV"
  echo "- Strict deploy-check: $STRICT"
  echo "- Skip connectivity: $SKIP_CONNECTIVITY"
  echo
  cat "$TMP"
} > "$OUT"
rm -f "$TMP"

echo "Mongoyia production health report: $OUT"
echo "Result: $result"
[ "$failures" -eq 0 ]
