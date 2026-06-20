#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
HANDOVER_DIR=${HANDOVER_DIR:-runtime/handover}
ACCEPTANCE_DIR=${ACCEPTANCE_DIR:-runtime/acceptance}
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-test-server-go-no-go-$STAMP.md}
SQL_DUMP_PATH=${SQL_DUMP_PATH:-../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql}
SQL_CHECKSUM_PATH=${SQL_CHECKSUM_PATH:-runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256}
HANDOFF_STATUS_PATH=${HANDOFF_STATUS_PATH:-}
INPUT_GATE_PATH=${INPUT_GATE_PATH:-}
RECEIVER_STATUS_PATH=${RECEIVER_STATUS_PATH:-}
RESTORE_STATUS_PATH=${RESTORE_STATUS_PATH:-}
EXTERNAL_INPUTS_CONFIRMED=${EXTERNAL_INPUTS_CONFIRMED:-0}
EXTERNAL_INPUTS_CONFIRM=${EXTERNAL_INPUTS_CONFIRM:-}

cd "$ROOT"

resolve_path() {
  path=$1
  case "$path" in
    /*) printf '%s\n' "$path" ;;
    [A-Za-z]:*) printf '%s\n' "$path" ;;
    *) printf '%s\n' "$ROOT/$path" ;;
  esac
}

latest_file() {
  dir=$(resolve_path "$1")
  pattern=$2
  find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1
}

latest_non_smoke_file() {
  dir=$(resolve_path "$1")
  pattern=$2
  find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null |
    while IFS= read -r file; do
      base=$(basename "$file")
      case "$base" in
        *smoke*|*expected*) : ;;
        *) printf '%s\n' "$file" ;;
      esac
    done |
    sort |
    tail -n 1 || true
}

latest_status_file() {
  dir=$(resolve_path "$HANDOVER_DIR")
  folder=$(find "$dir" -maxdepth 1 -type d -name "$1" 2>/dev/null | sort | tail -n 1)
  if [ "$folder" = "" ] || [ ! -f "$folder/$2" ]; then
    printf '\n'
  else
    printf '%s\n' "$folder/$2"
  fi
}

report_result() {
  path=$1
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf 'MISSING\n'
    return
  fi
  result=$(grep -E '^- Result:' "$path" | head -n 1 | sed 's/^- Result:[[:space:]]*//' || true)
  if [ "$result" != "" ]; then
    printf '%s\n' "$result"
  elif grep -Eq 'Mongoyia test-server receiver validation:[[:space:]]*PASS|^- Checksum validation:[[:space:]]*PASS' "$path"; then
    printf 'PASS\n'
  elif grep -q PASS "$path"; then
    printf 'PASS\n'
  else
    printf 'UNKNOWN\n'
  fi
}

sha_value() {
  path=$1
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$path" | awk '{print tolower($1)}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$path" | awk '{print tolower($1)}'
  else
    printf '\n'
  fi
}

sha_state() {
  file=$1
  checksum=$2
  if [ "$file" = "" ] || [ ! -f "$file" ]; then printf 'MISSING\n'; return; fi
  if [ "$checksum" = "" ] || [ ! -f "$checksum" ]; then printf 'NO_CHECKSUM\n'; return; fi
  expected=$(awk '{print tolower($1)}' "$checksum")
  actual=$(sha_value "$file")
  if [ "$expected" = "$actual" ]; then printf 'PASS\n'; else printf 'MISMATCH\n'; fi
}

contains_text() {
  path=$1
  pattern=$2
  [ "$path" != "" ] && [ -f "$path" ] && grep -q "$pattern" "$path"
}

OUTPUT_FULL=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUTPUT_FULL")"
ROWS="$OUTPUT_FULL.rows.tmp"
: > "$ROWS"
blockers=0
warnings=0

add_check() {
  area=$1
  check=$2
  status=$3
  evidence=$4
  action=$5
  printf '| %s | %s | %s | %s | %s |\n' "$area" "$check" "$status" "$evidence" "$action" >> "$ROWS"
  if [ "$status" = "BLOCK" ]; then blockers=$((blockers + 1)); fi
  if [ "$status" = "WARN" ]; then warnings=$((warnings + 1)); fi
}

add_critical_state_check() {
  area=$1
  check=$2
  state=$3
  evidence=$4
  action=$5
  if [ "$state" = "PASS" ]; then
    add_check "$area" "$check" PASS "$evidence" "$action"
  else
    add_check "$area" "$check" BLOCK "$evidence" "$action Current state: $state."
  fi
}

delivery=$(latest_file "$HANDOVER_DIR" 'mongoyia-test-server-delivery-*.zip')
delivery_tar=$(latest_file "$HANDOVER_DIR" 'mongoyia-test-server-delivery-*.tar.gz')
if [ "$HANDOFF_STATUS_PATH" != "" ]; then handoff=$(resolve_path "$HANDOFF_STATUS_PATH"); else handoff=$(latest_non_smoke_file "$HANDOVER_DIR" 'mongoyia-handoff-status-*-validated.md'); fi
[ "$handoff" = "" ] && handoff=$(latest_non_smoke_file "$HANDOVER_DIR" 'mongoyia-handoff-status-*.md')
restore_plan=$(latest_non_smoke_file "$HANDOVER_DIR" 'mongoyia-test-server-restore-plan-*.md')
if [ "$INPUT_GATE_PATH" != "" ]; then input_gate=$(resolve_path "$INPUT_GATE_PATH"); else input_gate=$(latest_non_smoke_file "$HANDOVER_DIR" 'mongoyia-test-server-input-gate-*.md'); fi
if [ "$RECEIVER_STATUS_PATH" != "" ]; then receiver_status=$(resolve_path "$RECEIVER_STATUS_PATH"); else receiver_status=$(latest_status_file 'receiver-*' 'RECEIVER_STATUS.md'); fi
if [ "$RESTORE_STATUS_PATH" != "" ]; then restore_status=$(resolve_path "$RESTORE_STATUS_PATH"); else restore_status=$(latest_status_file 'restore-*' 'RESTORE_STATUS.md'); fi
acceptance=$(latest_file "$ACCEPTANCE_DIR" 'mongoyia-acceptance-*.md')
signoff=$(latest_file "$ACCEPTANCE_DIR" 'mongoyia-signoff-*.md')

if [ "$delivery" != "" ]; then
  add_critical_state_check Package "Windows delivery archive checksum" "$(sha_state "$delivery" "$delivery.sha256")" "$(basename "$delivery")" "Use the adjacent .sha256 sidecar."
else
  add_check Package "Windows delivery archive checksum" BLOCK "" "Build the delivery archive."
fi
if [ "$delivery_tar" != "" ]; then
  add_critical_state_check Package "Linux delivery archive checksum" "$(sha_state "$delivery_tar" "$delivery_tar.sha256")" "$(basename "$delivery_tar")" "Use this on Linux receivers."
fi

handoff_result=$(report_result "$handoff")
case "$handoff_result" in
  PASS|WARN) add_check Package "Handoff status generated" PASS "$(basename "$handoff")" "Review remaining warnings before apply." ;;
  *) add_check Package "Handoff status generated" BLOCK "$handoff_result" "Generate handoff status with validation." ;;
esac

restore_plan_result=$(report_result "$restore_plan")
if [ "$restore_plan_result" = "READY" ]; then
  add_check Restore "Restore command plan is READY" PASS "$(basename "$restore_plan")" "Use the generated dry-run/apply commands."
else
  add_check Restore "Restore command plan is READY" BLOCK "$restore_plan_result" "Generate a restore plan with real HTTPS/WSS test inputs and backup reference."
fi

sql_dump=$(resolve_path "$SQL_DUMP_PATH")
sql_checksum=$(resolve_path "$SQL_CHECKSUM_PATH")
add_critical_state_check Restore "SQL dump checksum" "$(sha_state "$sql_dump" "$sql_checksum")" "$(basename "$sql_dump")" "Do not restore if this is not PASS."

receiver_result=$(report_result "$receiver_status")
if [ "$receiver_result" = "PASS" ]; then
  add_check Receiver "Receiver package validation" PASS "$receiver_status" "Run again on the real receiver host."
else
  add_check Receiver "Receiver package validation" BLOCK "$receiver_result" "Run receiver validation on the test server."
fi

input_gate_result=$(report_result "$input_gate")
if [ "$input_gate_result" = "PASS" ]; then
  add_check Safety "Real input gate passed" PASS "$(basename "$input_gate")" "Must be run with real .env, BaseUrl, ImUrl, SQL checksum, and backup reference."
else
  add_check Safety "Real input gate passed" BLOCK "$input_gate_result" "Run mongoyia-test-server-input-gate with RequireRestoreInputs on the real test server."
fi

restore_result=$(report_result "$restore_status")
case "$restore_result" in
  DRY_RUN|PASS) add_check Restore "Restore dry-run reviewed" WARN "$restore_status" "Local dry-run evidence exists; repeat after real receiver .env is provisioned." ;;
  *) add_check Restore "Restore dry-run reviewed" BLOCK "$restore_result" "Run restore dry-run before apply." ;;
esac

if [ "$acceptance" != "" ] && [ "$(report_result "$acceptance")" = "PASS" ]; then
  add_check Acceptance "Acceptance evidence exists" WARN "$(basename "$acceptance")" "Current acceptance is local-only until test-server run is complete."
else
  add_check Acceptance "Acceptance evidence exists" BLOCK "" "Run full acceptance after restore/preflight."
fi
if [ "$signoff" != "" ] && [ "$(report_result "$signoff")" = "PASS" ]; then
  add_check Acceptance "Signoff evidence exists" WARN "$(basename "$signoff")" "Needs real test-server owner signoff."
else
  add_check Acceptance "Signoff evidence exists" BLOCK "" "Collect real test-server signoff."
fi

if contains_text "$handoff" 'external test-server inputs | PENDING'; then
  if [ "$EXTERNAL_INPUTS_CONFIRMED" = "1" ] && [ "$EXTERNAL_INPUTS_CONFIRM" = "EXTERNAL_TEST_INPUTS_CONFIRMED" ]; then
    add_check External "External test-server inputs supplied" PASS "$(basename "$handoff")" "Operator confirmed real host, .env values, HTTPS/WSS, payment sandbox secrets, and QA owners."
  elif [ "$EXTERNAL_INPUTS_CONFIRMED" = "1" ]; then
    add_check External "External test-server inputs supplied" BLOCK "$(basename "$handoff")" "EXTERNAL_INPUTS_CONFIRM must equal EXTERNAL_TEST_INPUTS_CONFIRMED."
  else
    add_check External "External test-server inputs supplied" BLOCK "$(basename "$handoff")" "Provide real host, .env values, HTTPS/WSS, payment sandbox secrets, and QA owners, then set EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED."
  fi
else
  add_check External "External test-server inputs supplied" PASS "" "No pending external-input marker found in handoff status."
fi

if [ "$blockers" -gt 0 ]; then result=NO-GO
elif [ "$warnings" -gt 0 ]; then result=GO-WITH-WARNINGS
else result=GO
fi

{
  echo "# Mongoyia Test Server Go/No-Go"
  echo
  echo "- Result: $result"
  echo "- Blockers: $blockers"
  echo "- Warnings: $warnings"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Source root: $ROOT"
  echo
  echo "## Checks"
  echo
  echo "| Area | Check | Status | Evidence | Required action |"
  echo "|---|---|---:|---|---|"
  cat "$ROWS"
  echo
  echo "## Decision"
  echo
  echo "Apply restore is allowed only when Result is GO and every Safety/Restore/Receiver check is PASS. GO-WITH-WARNINGS still requires owner approval. NO-GO means do not run Apply."
  echo
  echo "This report is a checklist. It does not contain real passwords, API keys, callback secrets, or private keys."
} > "$OUTPUT_FULL"
rm -f "$ROWS"

echo "Mongoyia test-server go/no-go report: $OUTPUT_FULL"
echo "Result: $result"
[ "$blockers" -eq 0 ]
