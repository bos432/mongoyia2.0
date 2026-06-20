#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
PHP=${PHP:-php}
PROFILE=${PROFILE:-test}
STRICT=${STRICT:-1}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
BASE_URL=${BASE_URL:-}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}
SKIP_API=${SKIP_API:-0}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-test-server-preflight-$STAMP.md}
HANDOVER_ARCHIVE_PATH=${HANDOVER_ARCHIVE_PATH:-}
SOURCE_HANDOVER_ARCHIVE_PATH=${SOURCE_HANDOVER_ARCHIVE_PATH:-}

cd "$ROOT"

latest_file() {
  pattern=$1
  find "$ROOT/runtime/handover" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1
}

[ "$HANDOVER_ARCHIVE_PATH" = "" ] && HANDOVER_ARCHIVE_PATH=$(latest_file 'mongoyia-handover-*.zip')
[ "$SOURCE_HANDOVER_ARCHIVE_PATH" = "" ] && SOURCE_HANDOVER_ARCHIVE_PATH=$(latest_file 'mongoyia-source-handover-*.zip')

mkdir -p "$(dirname "$OUTPUT_PATH")"
REPORT_TMP="$OUTPUT_PATH.tmp"
: > "$REPORT_TMP"
failures=0

append() {
  printf '%s\n' "$@" >> "$REPORT_TMP"
}

run_step() {
  name=$1
  command_text=$2
  shift 2
  log_file="$OUTPUT_PATH.$(printf '%s' "$name" | tr ' /' '__').log"

  echo ""
  echo "== $name =="
  echo "$command_text"
  if "$@" > "$log_file" 2>&1; then
    status=PASS
    exit_code=0
  else
    status=FAIL
    exit_code=$?
    failures=$((failures + 1))
  fi
  cat "$log_file"

  append "" "## $name" "" "- Status: $status" "- Exit code: $exit_code" "" "Command:" "" '```text' "$command_text" '```' "" "Output:" "" '```text'
  cat "$log_file" >> "$REPORT_TMP"
  append '```'
  rm -f "$log_file"
}

append "# Mongoyia Test Server Preflight Report" "" "- Result: PENDING" "- Failures: PENDING" "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')" "- Source root: $ROOT" "- Profile: $PROFILE" "- Strict: $STRICT" "- BaseUrl: $BASE_URL" "- PHP env: $PHP_ENV" "- IM env: $IM_ENV" "- Skip connectivity: $SKIP_CONNECTIVITY" "- Skip API: $SKIP_API" "" "This report is intended to be generated on the restored test server before full acceptance. It does not create checkout, payment, or chat regression data."

run_step "Deployment configuration" "$PHP yii deploy-check/run --profile=$PROFILE --strict=$STRICT --phpEnv=$PHP_ENV --imEnv=$IM_ENV --skipConnectivity=$SKIP_CONNECTIVITY --interactive=0" \
  "$PHP" yii deploy-check/run "--profile=$PROFILE" "--strict=$STRICT" "--phpEnv=$PHP_ENV" "--imEnv=$IM_ENV" "--skipConnectivity=$SKIP_CONNECTIVITY" --interactive=0
run_step "Package check" "$PHP yii mongoyia-package-check/run --interactive=0" \
  "$PHP" yii mongoyia-package-check/run --interactive=0
run_step "Security scan" "$PHP yii mongoyia-security-scan/run --strict=$STRICT --interactive=0" \
  "$PHP" yii mongoyia-security-scan/run "--strict=$STRICT" --interactive=0

if [ "$HANDOVER_ARCHIVE_PATH" != "" ]; then
  run_step "Handover archive validation" "ARCHIVE_PATH=$HANDOVER_ARCHIVE_PATH sh console/shell/mongoyia-validate-handover-archive.sh" \
    env ARCHIVE_PATH="$HANDOVER_ARCHIVE_PATH" sh console/shell/mongoyia-validate-handover-archive.sh
fi

if [ "$SOURCE_HANDOVER_ARCHIVE_PATH" != "" ]; then
  patch_mode=skip
  [ "$PROFILE" = "local" ] && patch_mode=reverse
  run_step "Source handover validation" "PATCH_MODE=$patch_mode sh console/shell/mongoyia-validate-source-handover.sh $SOURCE_HANDOVER_ARCHIVE_PATH" \
    env PATCH_MODE="$patch_mode" sh console/shell/mongoyia-validate-source-handover.sh "$SOURCE_HANDOVER_ARCHIVE_PATH"
fi

run_step "Data readiness" "$PHP yii mongoyia-data-readiness/run --interactive=0" \
  "$PHP" yii mongoyia-data-readiness/run --interactive=0
run_step "Catalog readiness" "$PHP yii mongoyia-catalog-readiness/run --interactive=0" \
  "$PHP" yii mongoyia-catalog-readiness/run --interactive=0
run_step "Translation readiness" "$PHP yii mongoyia-translation-readiness/run --strict=$STRICT --interactive=0" \
  "$PHP" yii mongoyia-translation-readiness/run "--strict=$STRICT" --interactive=0
run_step "Order integrity" "$PHP yii mongoyia-order-integrity/run --interactive=0" \
  "$PHP" yii mongoyia-order-integrity/run --interactive=0
run_step "Payment audit" "$PHP yii mongoyia-payment-audit/run --interactive=0" \
  "$PHP" yii mongoyia-payment-audit/run --interactive=0

if [ "$SKIP_API" != "1" ] && [ "$BASE_URL" != "" ]; then
  run_step "API smoke" "$PHP yii api-smoke-test/run --baseUrl=$BASE_URL --interactive=0" \
    "$PHP" yii api-smoke-test/run "--baseUrl=$BASE_URL" --interactive=0
fi

run_step "Generated test-data cleanup verification" "$PHP yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0" \
  "$PHP" yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0

result=PASS
[ "$failures" -gt 0 ] && result=FAIL
sed "s/^- Result: PENDING$/- Result: $result/; s/^- Failures: PENDING$/- Failures: $failures/" "$REPORT_TMP" > "$OUTPUT_PATH"
rm -f "$REPORT_TMP"

echo ""
echo "Preflight report: $OUTPUT_PATH"
echo "Result: $result ($failures failure(s))"

[ "$failures" -eq 0 ]
