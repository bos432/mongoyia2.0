#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
PROFILE=${PROFILE:-test}
BASE_URL=${BASE_URL:-}
IM_URL=${IM_URL:-}
DELIVERY_ARCHIVE_PATH=${DELIVERY_ARCHIVE_PATH:-}
SQL_DUMP_PATH=${SQL_DUMP_PATH:-../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql}
SQL_CHECKSUM_PATH=${SQL_CHECKSUM_PATH:-runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256}
DATABASE=${DATABASE:-outer}
BACKUP_REFERENCE=${BACKUP_REFERENCE:-}
BACKUP_ARTIFACT_PATH=${BACKUP_ARTIFACT_PATH:-}
REQUIRE_EXTERNAL_INPUTS=${REQUIRE_EXTERNAL_INPUTS:-0}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-p2-readiness-$STAMP.md}

cd "$ROOT"

latest_delivery() {
  find "$ROOT/runtime/handover" -maxdepth 1 -type f \( -name 'mongoyia-test-server-delivery-*.tar.gz' -o -name 'mongoyia-test-server-delivery-*.zip' \) 2>/dev/null | sort | tail -n 1
}

resolve_path() {
  value=$1
  [ "$value" = "" ] && return 0
  case "$value" in
    /*|?:*) printf '%s\n' "$value" ;;
    *) printf '%s\n' "$ROOT/$value" ;;
  esac
}

[ "$DELIVERY_ARCHIVE_PATH" = "" ] && DELIVERY_ARCHIVE_PATH=$(latest_delivery)
SQL_DUMP_PATH=$(resolve_path "$SQL_DUMP_PATH")
SQL_CHECKSUM_PATH=$(resolve_path "$SQL_CHECKSUM_PATH")
BACKUP_ARTIFACT_PATH=$(resolve_path "$BACKUP_ARTIFACT_PATH")
OUTPUT_FULL=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUTPUT_FULL")"

REPORT_TMP="$OUTPUT_FULL.tmp"
CHECKS_TMP="$OUTPUT_FULL.checks.tmp"
: > "$REPORT_TMP"
: > "$CHECKS_TMP"
failures=0
warnings=0
pending=0

add_pass() { printf '%s\n' "- PASS $1" >> "$CHECKS_TMP"; }
add_warn() { warnings=$((warnings + 1)); printf '%s\n' "- WARN $1" >> "$CHECKS_TMP"; }
add_pending() {
  if [ "$REQUIRE_EXTERNAL_INPUTS" = "1" ]; then
    failures=$((failures + 1))
    printf '%s\n' "- FAIL $1" >> "$CHECKS_TMP"
  else
    pending=$((pending + 1))
    printf '%s\n' "- PENDING $1" >> "$CHECKS_TMP"
  fi
}

append() {
  printf '%s\n' "$@" >> "$REPORT_TMP"
}

run_step() {
  name=$1
  command_text=$2
  shift 2
  log_file="$OUTPUT_FULL.$(printf '%s' "$name" | tr ' /' '__').log"
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
  append "" "## $name" "" "- Status: $status" "- Exit code: $exit_code" "" '```text' "$command_text" '```' "" "Output:" "" '```text'
  cat "$log_file" >> "$REPORT_TMP"
  append '```'
  rm -f "$log_file"
}

append "# Mongoyia P2 Readiness Report" "" "- Result: PENDING" "- Failures: PENDING" "- Warnings: PENDING" "- Pending external inputs: PENDING" "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')" "- Profile: $PROFILE" "- Require external inputs: $REQUIRE_EXTERNAL_INPUTS" "- Base URL: $BASE_URL" "- IM URL: $IM_URL" "- Delivery archive: $DELIVERY_ARCHIVE_PATH" "- SQL dump: $SQL_DUMP_PATH" "- SQL checksum: $SQL_CHECKSUM_PATH" "- Database: $DATABASE" "- Backup reference: $BACKUP_REFERENCE" "- Backup artifact: $BACKUP_ARTIFACT_PATH" "" "This report closes local P2 preparation. Real restore, payment sandbox, and WSS acceptance still require real server/domain/provider inputs."

if [ "$DELIVERY_ARCHIVE_PATH" != "" ] && [ -f "$DELIVERY_ARCHIVE_PATH" ]; then
  run_step "Delivery archive validation" "ARCHIVE_PATH=$DELIVERY_ARCHIVE_PATH sh console/shell/mongoyia-validate-test-server-delivery.sh" \
    env ARCHIVE_PATH="$DELIVERY_ARCHIVE_PATH" sh console/shell/mongoyia-validate-test-server-delivery.sh
else
  add_pending "Latest test-server delivery archive is missing."
fi

if [ -f "$SQL_DUMP_PATH" ] && [ -f "$SQL_CHECKSUM_PATH" ]; then
  if command -v sha256sum >/dev/null 2>&1; then
    actual=$(sha256sum "$SQL_DUMP_PATH" | awk '{print tolower($1)}')
  else
    actual=$(shasum -a 256 "$SQL_DUMP_PATH" | awk '{print tolower($1)}')
  fi
  expected=$(awk '{print tolower($1)}' "$SQL_CHECKSUM_PATH")
  [ "$actual" = "$expected" ] && add_pass "SQL dump SHA256 matches sidecar." || { failures=$((failures + 1)); printf '%s\n' "- FAIL SQL dump SHA256 mismatch. expected=$expected actual=$actual" >> "$CHECKS_TMP"; }
else
  add_pending "SQL dump and checksum sidecar must be copied to the test server."
fi

case "$BASE_URL" in
  https://*localhost*|https://*127.0.0.1*|https://*example.com*|https://www.mongoyia.com*|https://mongoyia.com*|"") add_pending "Real HTTPS test BaseUrl is required before restore apply." ;;
  https://*) add_pass "BaseUrl looks like a real test HTTPS URL." ;;
  *) add_pending "Real HTTPS test BaseUrl is required before restore apply." ;;
esac
case "$IM_URL" in
  wss://*localhost*|wss://*127.0.0.1*|wss://*example.com*|wss://www.mongoyia.com*|wss://mongoyia.com*|"") add_pending "Real WSS IM URL is required before restore apply." ;;
  wss://*) add_pass "ImUrl looks like a real test WSS URL." ;;
  *) add_pending "Real WSS IM URL is required before restore apply." ;;
esac
if [ "$BACKUP_REFERENCE" != "" ] || [ "$BACKUP_ARTIFACT_PATH" != "" ]; then
  add_pass "Backup reference/artifact is present."
else
  add_pending "BackupReference or BackupArtifactPath is required before restore apply."
fi

for file in .env.example .env.test.example; do
  for token in QPAY_AUTH_URL QPAY_INVOICE_URL; do
    if ! grep -q "$token" "$file"; then
      failures=$((failures + 1))
      printf '%s\n' "- FAIL $file is missing $token." >> "$CHECKS_TMP"
    fi
  done
done
if grep -q "env('QPAY_AUTH_URL'" frontend/modules/mall/controllers/PaymentController.php && grep -q "env('QPAY_INVOICE_URL'" frontend/modules/mall/controllers/PaymentController.php; then
  add_pass "Runtime QPay gateway URLs are configurable through .env."
else
  failures=$((failures + 1))
  printf '%s\n' "- FAIL Runtime QPay gateway URLs are still hardcoded." >> "$CHECKS_TMP"
fi
if [ -f frontend/modules/mall/controllers/PaymentController-0.php ] && grep -q 'merchant\.qpay\.mn' frontend/modules/mall/controllers/PaymentController-0.php; then
  add_warn "Historical backup PaymentController-0.php still contains QPay hardcoded URLs; do not deploy it as runtime code."
fi

run_step "PHP syntax: payment controller" "php -l frontend/modules/mall/controllers/PaymentController.php" php -l frontend/modules/mall/controllers/PaymentController.php
run_step "PHP syntax: deploy check" "php -l console/controllers/DeployCheckController.php" php -l console/controllers/DeployCheckController.php
run_step "Input-gate smoke" "powershell -ExecutionPolicy Bypass -File console/shell/mongoyia-test-server-input-gate-smoke.ps1" powershell -ExecutionPolicy Bypass -File console/shell/mongoyia-test-server-input-gate-smoke.ps1
run_step "Go/no-go smoke" "powershell -ExecutionPolicy Bypass -File console/shell/mongoyia-test-server-go-no-go-smoke.ps1" powershell -ExecutionPolicy Bypass -File console/shell/mongoyia-test-server-go-no-go-smoke.ps1
run_step "Package check" "php yii mongoyia-package-check/run --interactive=0" php yii mongoyia-package-check/run --interactive=0
run_step "Security scan" "php yii mongoyia-security-scan/run --interactive=0" php yii mongoyia-security-scan/run --interactive=0
run_step "Focused translation readiness" "php yii mongoyia-translation-readiness/run --strict=0 --productIds=90,102 --categoryIds=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --interactive=0" php yii mongoyia-translation-readiness/run --strict=0 --productIds=90,102 --categoryIds=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --interactive=0
run_step "Translation audit" "php yii mongoyia-translation-audit/run --interactive=0" php yii mongoyia-translation-audit/run --interactive=0
run_step "Generated test-data cleanup verification" "php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0" php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0

result=PASS
[ "$pending" -gt 0 ] || [ "$warnings" -gt 0 ] && result=WARN
[ "$failures" -gt 0 ] && result=FAIL
{
  sed "s/^- Result: PENDING$/- Result: $result/; s/^- Failures: PENDING$/- Failures: $failures/; s/^- Warnings: PENDING$/- Warnings: $warnings/; s/^- Pending external inputs: PENDING$/- Pending external inputs: $pending/" "$REPORT_TMP"
  printf '\n## Readiness Checks\n\n'
  cat "$CHECKS_TMP"
} > "$OUTPUT_FULL"
rm -f "$REPORT_TMP" "$CHECKS_TMP"

echo ""
echo "P2 readiness report: $OUTPUT_FULL"
echo "Result: $result ($failures failure(s), $warnings warning(s), $pending pending external input(s))"
[ "$failures" -eq 0 ]
