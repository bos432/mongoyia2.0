#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover/go-no-go-smoke-$$}
SMOKE_ROOT="$ROOT/$OUTPUT_DIR"

case "$SMOKE_ROOT" in
  "$ROOT/runtime"/*) rm -rf "$SMOKE_ROOT" || true ;;
  *) echo "ERROR refusing to remove unexpected smoke path: $SMOKE_ROOT" >&2; exit 1 ;;
esac
mkdir -p "$SMOKE_ROOT/handover/receiver-smoke" "$SMOKE_ROOT/handover/restore-smoke" "$SMOKE_ROOT/acceptance"

sha_value() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print tolower($1)}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print tolower($1)}'
  else
    echo "ERROR sha256sum or shasum is required." >&2
    exit 1
  fi
}

delivery="$SMOKE_ROOT/handover/mongoyia-test-server-delivery-smoke.zip"
printf '%s\n' "smoke delivery" > "$delivery"
printf '%s  %s\n' "$(sha_value "$delivery")" "mongoyia-test-server-delivery-smoke.zip" > "$delivery.sha256"

sql_dump="$SMOKE_ROOT/outer-smoke.sql"
sql_checksum="$SMOKE_ROOT/outer-smoke.sql.sha256"
printf '%s\n' "-- smoke sql" > "$sql_dump"
printf '%s  %s\n' "$(sha_value "$sql_dump")" "outer-smoke.sql" > "$sql_checksum"

handoff="$SMOKE_ROOT/handover/mongoyia-handoff-status-smoke-validated.md"
cat > "$handoff" <<'EOF'
# Smoke Handoff Status

- Result: WARN

| Check | Status |
|---|---|
| external test-server inputs | PENDING |
EOF

restore_plan="$SMOKE_ROOT/handover/mongoyia-test-server-restore-plan-ready.md"
input_gate="$SMOKE_ROOT/handover/mongoyia-test-server-input-gate-smoke-real.md"
receiver_status="$SMOKE_ROOT/handover/receiver-smoke/RECEIVER_STATUS.md"
restore_status="$SMOKE_ROOT/handover/restore-smoke/RESTORE_STATUS.md"
printf '%s\n' "- Result: READY" > "$restore_plan"
printf '%s\n' "- Result: PASS" > "$input_gate"
printf '%s\n' "Mongoyia test-server receiver validation: PASS" > "$receiver_status"
printf '%s\n' "- Result: DRY_RUN" > "$restore_status"
printf '%s\n' "- Result: PASS" > "$SMOKE_ROOT/acceptance/mongoyia-acceptance-smoke.md"
printf '%s\n' "- Result: PASS" > "$SMOKE_ROOT/acceptance/mongoyia-signoff-smoke.md"

run_go_no_go() {
  name=$1
  shift
  report="$SMOKE_ROOT/$name.md"
  set +e
  env \
    OUTPUT_PATH="$report" \
    HANDOVER_DIR="$SMOKE_ROOT/handover" \
    ACCEPTANCE_DIR="$SMOKE_ROOT/acceptance" \
    SQL_DUMP_PATH="$sql_dump" \
    SQL_CHECKSUM_PATH="$sql_checksum" \
    HANDOFF_STATUS_PATH="$handoff" \
    INPUT_GATE_PATH="$input_gate" \
    RECEIVER_STATUS_PATH="$receiver_status" \
    RESTORE_STATUS_PATH="$restore_status" \
    "$@" \
    sh "$SCRIPT_DIR/mongoyia-test-server-go-no-go.sh" >/dev/null
  code=$?
  set -e
  printf '%s\n' "$code"
}

missing_code=$(run_go_no_go missing-confirm)
if [ "$missing_code" -eq 0 ] || ! grep -Eq '^- Result: NO-GO$' "$SMOKE_ROOT/missing-confirm.md" || ! grep -q 'External test-server inputs supplied | BLOCK' "$SMOKE_ROOT/missing-confirm.md"; then
  echo "ERROR expected missing external confirmation go/no-go smoke to block." >&2
  exit 1
fi

wrong_code=$(run_go_no_go wrong-confirm EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=WRONG)
if [ "$wrong_code" -eq 0 ] || ! grep -Eq '^- Result: NO-GO$' "$SMOKE_ROOT/wrong-confirm.md" || ! grep -q 'EXTERNAL_INPUTS_CONFIRM must equal EXTERNAL_TEST_INPUTS_CONFIRMED' "$SMOKE_ROOT/wrong-confirm.md"; then
  echo "ERROR expected wrong external confirmation go/no-go smoke to block." >&2
  exit 1
fi

confirmed_code=$(run_go_no_go confirmed EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED)
if [ "$confirmed_code" -ne 0 ] || ! grep -Eq '^- Result: GO-WITH-WARNINGS$' "$SMOKE_ROOT/confirmed.md" || ! grep -q 'External test-server inputs supplied | PASS' "$SMOKE_ROOT/confirmed.md"; then
  echo "ERROR expected confirmed external inputs go/no-go smoke to pass external gate." >&2
  exit 1
fi

rm -rf "$SMOKE_ROOT" || true
echo "Mongoyia test-server go/no-go smoke: PASS"
