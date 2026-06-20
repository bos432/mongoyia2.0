#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
HANDOVER_DIR=${HANDOVER_DIR:-runtime/handover}
ACCEPTANCE_DIR=${ACCEPTANCE_DIR:-runtime/acceptance}
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-handoff-status-$STAMP.md}
SQL_DUMP_PATH=${SQL_DUMP_PATH:-../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql}
SQL_CHECKSUM_PATH=${SQL_CHECKSUM_PATH:-runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256}
VALIDATE_DELIVERY=${VALIDATE_DELIVERY:-0}

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
  pattern=$1
  dir=$(resolve_path "$HANDOVER_DIR")
  find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1
}

latest_status_file() {
  folder_pattern=$1
  file_name=$2
  dir=$(resolve_path "$HANDOVER_DIR")
  folder=$(find "$dir" -maxdepth 1 -type d -name "$folder_pattern" 2>/dev/null | sort | tail -n 1)
  if [ "$folder" = "" ] || [ ! -f "$folder/$file_name" ]; then
    printf '\n'
  else
    printf '%s\n' "$folder/$file_name"
  fi
}

latest_non_smoke_file() {
  pattern=$1
  dir=$(resolve_path "$HANDOVER_DIR")
  find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | grep -Ev '(smoke|expected)' | sort | tail -n 1 || true
}

latest_acceptance_file() {
  pattern=$1
  dir=$(resolve_path "$ACCEPTANCE_DIR")
  find "$dir" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1
}

latest_delivery_file() {
  dir=$(resolve_path "$HANDOVER_DIR")
  file=$(find "$dir" -maxdepth 1 -type f -name 'mongoyia-test-server-delivery-*.tar.gz' 2>/dev/null | sort | tail -n 1)
  if [ "$file" = "" ]; then
    file=$(find "$dir" -maxdepth 1 -type f -name 'mongoyia-test-server-delivery-*.zip' 2>/dev/null | sort | tail -n 1)
  fi
  printf '%s\n' "$file"
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
  if [ "$file" = "" ] || [ ! -f "$file" ]; then
    printf 'MISSING|\n'
    return
  fi
  if [ "$checksum" = "" ] || [ ! -f "$checksum" ]; then
    printf 'NO_CHECKSUM|\n'
    return
  fi
  expected=$(awk '{print tolower($1)}' "$checksum")
  actual=$(sha_value "$file")
  if [ "$expected" = "$actual" ]; then
    printf 'PASS|%s\n' "$actual"
  else
    printf 'MISMATCH|%s\n' "$actual"
  fi
}

report_result() {
  path=$1
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf 'MISSING\n'
    return
  fi
  result=$(grep -E '^- Result:' "$path" | head -n 1 | sed 's/^- Result:[[:space:]]*//')
  if [ "$result" = "" ]; then
    if grep -Eq '^- Mode:[[:space:]]*DRY-RUN[[:space:]]*$' "$path"; then
      printf 'DRY_RUN\n'
      return
    fi
    status_lines=$(grep -E '^- Status:' "$path" | sed 's/^- Status:[[:space:]]*//' || true)
    if [ "$status_lines" != "" ]; then
      non_ok=$(printf '%s\n' "$status_lines" | grep -Ev '^(PASS|DRY-RUN)$' || true)
      if [ "$non_ok" = "" ]; then
        if printf '%s\n' "$status_lines" | grep -q '^DRY-RUN$'; then
          printf 'DRY_RUN\n'
        else
          printf 'PASS\n'
        fi
        return
      fi
    fi
    if grep -q PASS "$path"; then printf 'PASS\n'; else printf 'UNKNOWN\n'; fi
  else
    printf '%s\n' "$result"
  fi
}

report_profile() {
  path=$1
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf '\n'
    return
  fi
  grep -E '^- Profile:' "$path" | head -n 1 | sed 's/^- Profile:[[:space:]]*//' | tr 'A-Z' 'a-z'
}

report_strict() {
  path=$1
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf '\n'
    return
  fi
  value=$(grep -E '^- Strict mode:' "$path" | head -n 1 | sed 's/^- Strict mode:[[:space:]]*//' | tr 'A-Z' 'a-z')
  if [ "$value" = "" ]; then
    value=$(grep -E '^\|[[:space:]]*Strict mode[[:space:]]*\|' "$path" | head -n 1 | awk -F'|' '{gsub(/^[ \t]+|[ \t]+$/, "", $3); print tolower($3)}')
  fi
  printf '%s\n' "$value"
}

acceptance_display_result() {
  path=$1
  if [ "$path" = "" ] || [ ! -f "$path" ]; then
    printf '%s|%s\n' "OPTIONAL_MISSING" "Run after the real test server is ready."
    return
  fi
  result=$(report_result "$path")
  profile=$(report_profile "$path")
  strict=$(report_strict "$path")
  display=$result
  if [ "$result" = "PASS" ] && [ "$profile" = "test" ] && { [ "$strict" = "yes" ] || [ "$strict" = "1" ] || [ "$strict" = "true" ]; }; then
    display=TEST_STRICT_PASS
  elif [ "$result" = "PASS" ] && [ "$profile" = "local" ]; then
    display=PASS_LOCAL_ONLY
  elif [ "$result" = "PASS" ]; then
    display=PASS_NON_FINAL
  elif [ "$result" != "DRY_RUN" ]; then
    warnings=$((warnings + 1))
  fi
  printf '%s|%s\n' "$display" "$path"
}

OUTPUT_FULL=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUTPUT_FULL")"
ROWS="$OUTPUT_FULL.rows.tmp"
REPORT_ROWS="$OUTPUT_FULL.report-rows.tmp"
: > "$ROWS"
: > "$REPORT_ROWS"
warnings=0

add_artifact() {
  name=$1
  file=$2
  checksum=$3
  state_hash=$(sha_state "$file" "$checksum")
  state=${state_hash%%|*}
  hash=${state_hash#*|}
  [ "$state" != "PASS" ] && warnings=$((warnings + 1))
  base=
  updated=
  if [ "$file" != "" ]; then
    base=$(basename "$file")
  fi
  printf '| %s | %s | %s | %s | %s |\n' "$name" "$state" "$base" "$hash" "$updated" >> "$ROWS"
}

delivery=$(latest_delivery_file)
delivery_zip=$(latest_file 'mongoyia-test-server-delivery-*.zip')
delivery_targz=$(latest_file 'mongoyia-test-server-delivery-*.tar.gz')
handover=$(latest_file 'mongoyia-handover-*.zip')
source_handover=$(latest_file 'mongoyia-source-handover-*.zip')
untracked=$(latest_file 'mongoyia-untracked-source-*.zip')
patch=$(latest_file 'mongoyia-source-tracked-diff-*.patch')
preflight=$(latest_file 'mongoyia-test-server-preflight-*.md')
handover_verify=$(latest_file 'mongoyia-handover-verify-*.md')
sql_manifest=$(latest_file 'mongoyia-sql-dump-manifest-*.md')
env_report=$(latest_file 'mongoyia-env-redacted-report-*.md')
restore_plan=$(latest_non_smoke_file 'mongoyia-test-server-restore-plan-*.md')
go_no_go_report=$(latest_non_smoke_file 'mongoyia-test-server-go-no-go-*.md')
receiver_status=$(latest_status_file 'receiver-*' 'RECEIVER_STATUS.md')
restore_status=$(latest_status_file 'restore-*' 'RESTORE_STATUS.md')
acceptance_report=$(latest_acceptance_file 'mongoyia-acceptance-*.md')
signoff_report=$(latest_acceptance_file 'mongoyia-signoff-*.md')
risk_report=$(latest_acceptance_file 'mongoyia-risk-register-*.md')
acceptance_delivery_index=$(latest_acceptance_file 'mongoyia-delivery-index-*.md')

add_artifact 'test-server delivery' "$delivery" "$delivery.sha256"
if [ "$delivery_targz" != "" ] && [ "$delivery_targz" != "$delivery" ]; then
  add_artifact 'test-server delivery tar.gz' "$delivery_targz" "$delivery_targz.sha256"
fi
if [ "$delivery_zip" != "" ] && [ "$delivery_zip" != "$delivery" ]; then
  add_artifact 'test-server delivery zip' "$delivery_zip" "$delivery_zip.sha256"
fi
add_artifact 'handover archive' "$handover" "$handover.sha256"
add_artifact 'source handover archive' "$source_handover" "$source_handover.sha256"
add_artifact 'untracked source bundle' "$untracked" "$untracked.sha256"
add_artifact 'tracked source patch' "$patch" "$patch.sha256"
sql_dump=$(resolve_path "$SQL_DUMP_PATH")
sql_checksum=$(resolve_path "$SQL_CHECKSUM_PATH")
add_artifact 'SQL dump' "$sql_dump" "$sql_checksum"

for item in \
  "preflight report|$preflight" \
  "handover verify report|$handover_verify" \
  "SQL dump manifest|$sql_manifest" \
  "env redacted report|$env_report" \
  "restore plan|$restore_plan" \
  "go/no-go checklist|$go_no_go_report" \
  "receiver status|$receiver_status" \
  "restore status|$restore_status" \
  "acceptance report|$acceptance_report" \
  "acceptance signoff|$signoff_report" \
  "risk register|$risk_report" \
  "acceptance delivery index|$acceptance_delivery_index"; do
  name=${item%%|*}
  path=${item#*|}
  if [ "$name" = "restore plan" ] && { [ "$path" = "" ] || [ ! -f "$path" ]; }; then
    printf '| %s | %s | %s |\n' "$name" "OPTIONAL_MISSING" "Generate this after real test-server inputs are known." >> "$REPORT_ROWS"
    continue
  fi
  case "$name" in
    "acceptance report"|"acceptance signoff"|"risk register"|"acceptance delivery index")
      if [ "$path" = "" ] || [ ! -f "$path" ]; then
        printf '| %s | %s | %s |\n' "$name" "OPTIONAL_MISSING" "Generate this after the real test-server acceptance run." >> "$REPORT_ROWS"
        continue
      fi
      ;;
  esac
  if [ "$name" = "acceptance report" ]; then
    display_path=$(acceptance_display_result "$path")
    display=${display_path%%|*}
    out_path=${display_path#*|}
    printf '| %s | %s | %s |\n' "$name" "$display" "$out_path" >> "$REPORT_ROWS"
    continue
  fi
  result=$(report_result "$path")
  display_result=$result
  if [ "$name" = "env redacted report" ] && [ "$result" != "PASS" ] && [ "$(report_profile "$path")" = "local" ]; then
    display_result="${result}_LOCAL_EXPECTED"
  elif [ "$name" = "restore plan" ] && [ "$result" = "PENDING" ]; then
    display_result=PENDING_EXTERNAL_INPUTS
  elif [ "$name" = "go/no-go checklist" ] && [ "$result" = "NO-GO" ]; then
    warnings=$((warnings + 1))
  elif { [ "$name" = "risk register" ] || [ "$name" = "acceptance delivery index" ]; } && [ "$result" = "UNKNOWN" ]; then
    display_result=PRESENT
  elif { [ "$name" = "acceptance signoff" ] || [ "$name" = "risk register" ] || [ "$name" = "acceptance delivery index" ]; } && { [ "$(report_strict "$path")" = "no" ] || [ "$(report_strict "$path")" = "0" ] || [ "$(report_strict "$path")" = "false" ]; }; then
    display_result="${result}_LOCAL_ONLY"
  elif [ "$result" != "PASS" ] && [ "$result" != "DRY_RUN" ]; then
    warnings=$((warnings + 1))
  fi
  printf '| %s | %s | %s |\n' "$name" "$display_result" "$path" >> "$REPORT_ROWS"
done

warnings=$((warnings + 1))
printf '| %s | %s | %s |\n' 'external test-server inputs' 'PENDING' 'See Remaining External Inputs below.' >> "$REPORT_ROWS"

delivery_validation=NOT_RUN
if [ "$VALIDATE_DELIVERY" = "1" ] && [ "$delivery" != "" ]; then
  case "$delivery" in
    *.tar.gz)
      if env ARCHIVE_PATH="$delivery" sh "$SCRIPT_DIR/mongoyia-validate-test-server-delivery.sh" >/dev/null 2>&1; then
        delivery_validation=PASS
      else
        delivery_validation=FAIL
        warnings=$((warnings + 1))
      fi
      ;;
    *)
      delivery_validation=SKIPPED_UNSUPPORTED_ARCHIVE
      warnings=$((warnings + 1))
      ;;
  esac
fi

result=PASS
[ "$warnings" -gt 0 ] && result=WARN

cat > "$OUTPUT_FULL" <<EOF
# Mongoyia Handoff Status

- Result: $result
- Warnings: $warnings
- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Source root: $ROOT
- Delivery validation: $delivery_validation

## Artifacts

| Item | Check | File | SHA256 | Updated |
|---|---:|---|---|---|
EOF
cat "$ROWS" >> "$OUTPUT_FULL"
cat >> "$OUTPUT_FULL" <<EOF

## Reports

| Item | Result | Path |
|---|---:|---|
EOF
cat "$REPORT_ROWS" >> "$OUTPUT_FULL"
cat >> "$OUTPUT_FULL" <<EOF

## Remaining External Inputs

- Real test-server host and access.
- Real PHP and Python IM .env values.
- HTTPS test domain and WSS IM path.
- Test database credentials.
- Payment sandbox credentials and callback secrets.
- Manual QA owner for payment, IM, backend seller operations, and Mongolian content.
EOF

rm -f "$ROWS" "$REPORT_ROWS"
echo "Handoff status report: $OUTPUT_FULL"
echo "Result: $result ($warnings warning(s))"
