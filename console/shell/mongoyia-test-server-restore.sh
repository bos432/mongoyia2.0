#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

SQL_DUMP_PATH=${SQL_DUMP_PATH:-${1:-}}
DATABASE=${DATABASE:-outer}
MYSQL_BIN=${MYSQL_BIN:-mysql}
MYSQL_HOST=${MYSQL_HOST:-127.0.0.1}
MYSQL_PORT=${MYSQL_PORT:-3306}
MYSQL_USER=${MYSQL_USER:-root}
MYSQL_PASSWORD=${MYSQL_PASSWORD:-}
MYSQL_DEFAULTS_EXTRA_FILE=${MYSQL_DEFAULTS_EXTRA_FILE:-}
SQL_CHECKSUM_PATH=${SQL_CHECKSUM_PATH:-}
EXPECTED_SQL_SHA256=${EXPECTED_SQL_SHA256:-}
DELIVERY_ARCHIVE_PATH=${DELIVERY_ARCHIVE_PATH:-}
BASE_URL=${BASE_URL:-}
IM_URL=${IM_URL:-}
PHP_BIN=${PHP_BIN:-php}
PYTHON_BIN=${PYTHON_BIN:-python}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
WORK_DIR=${WORK_DIR:-$ROOT/runtime/handover/restore-$(date +%Y%m%d-%H%M%S)}
APPLY=${APPLY:-0}
SKIP_INPUT_GATE=${SKIP_INPUT_GATE:-0}
ALLOW_PRODUCTION_DOMAIN_FOR_TEST=${ALLOW_PRODUCTION_DOMAIN_FOR_TEST:-0}
BACKUP_CONFIRMED=${BACKUP_CONFIRMED:-0}
BACKUP_ARTIFACT_PATH=${BACKUP_ARTIFACT_PATH:-}
BACKUP_CHECKSUM_PATH=${BACKUP_CHECKSUM_PATH:-}
EXPECTED_BACKUP_SHA256=${EXPECTED_BACKUP_SHA256:-}
BACKUP_REFERENCE=${BACKUP_REFERENCE:-}
APPLY_CONFIRM=${APPLY_CONFIRM:-}
SKIP_APPLY_SAFETY=${SKIP_APPLY_SAFETY:-0}
SKIP_APPLY_SAFETY_CONFIRM=${SKIP_APPLY_SAFETY_CONFIRM:-}
EXTERNAL_INPUTS_CONFIRMED=${EXTERNAL_INPUTS_CONFIRMED:-0}
EXTERNAL_INPUTS_CONFIRM=${EXTERNAL_INPUTS_CONFIRM:-}
RUN_RECEIVER=${RUN_RECEIVER:-0}
RUN_MIGRATE=${RUN_MIGRATE:-0}
RUN_PREFLIGHT=${RUN_PREFLIGHT:-0}
RUN_ACCEPTANCE=${RUN_ACCEPTANCE:-0}
CLEANUP_AFTER_RUN=${CLEANUP_AFTER_RUN:-0}
SKIP_API=${SKIP_API:-0}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}

cd "$ROOT"
mkdir -p "$WORK_DIR"
STATUS_PATH="$WORK_DIR/RESTORE_STATUS.md"

resolve_path() {
  path=$1
  if [ "$path" = "" ]; then
    printf '\n'
    return
  fi
  case "$path" in
    /*) printf '%s\n' "$path" ;;
    [A-Za-z]:*) printf '%s\n' "$path" ;;
    *) printf '%s\n' "$ROOT/$path" ;;
  esac
}

run_step() {
  name=$1
  shift
  echo ""
  echo "== $name =="
  "$@"
}

production_domain_host() {
  value=$1
  [ "$value" = "" ] && return 1
  candidate=$(printf '%s' "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  case "$candidate" in
    *://*)
      host=$(printf '%s' "$candidate" | sed -n 's,^[A-Za-z][A-Za-z0-9+.-]*://\([^/:]*\).*,\1,p')
      ;;
    *)
      host=$(printf '%s' "$candidate" | cut -d/ -f1 | cut -d: -f1)
      ;;
  esac
  host=$(printf '%s' "$host" | tr 'A-Z' 'a-z' | sed 's/\.$//')
  case "$host" in
    mongoyia.com|www.mongoyia.com) printf '%s\n' "$host"; return 0 ;;
  esac
  return 1
}

env_host() {
  value=$1
  [ "$value" = "" ] && return 0
  candidate=$(printf '%s' "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  case "$candidate" in
    *://*) host=$(printf '%s' "$candidate" | sed -n 's,^[A-Za-z][A-Za-z0-9+.-]*://\([^/:]*\).*,\1,p') ;;
    *) host= ;;
  esac
  printf '%s\n' "$host" | tr 'A-Z' 'a-z' | sed 's/\.$//'
}

RESOLVED_SQL_DUMP=$(resolve_path "$SQL_DUMP_PATH")
RESOLVED_DELIVERY=$(resolve_path "$DELIVERY_ARCHIVE_PATH")
RESOLVED_SQL_CHECKSUM=$(resolve_path "$SQL_CHECKSUM_PATH")
RESOLVED_BACKUP_ARTIFACT=$(resolve_path "$BACKUP_ARTIFACT_PATH")
RESOLVED_BACKUP_CHECKSUM=$(resolve_path "$BACKUP_CHECKSUM_PATH")

if [ "$SQL_DUMP_PATH" != "" ] && [ ! -f "$RESOLVED_SQL_DUMP" ]; then
  echo "ERROR SQL dump not found: $RESOLVED_SQL_DUMP" >&2
  exit 1
fi
if [ "$DELIVERY_ARCHIVE_PATH" != "" ] && [ ! -f "$RESOLVED_DELIVERY" ]; then
  echo "ERROR delivery archive not found: $RESOLVED_DELIVERY" >&2
  exit 1
fi
if [ "$SQL_CHECKSUM_PATH" != "" ] && [ ! -f "$RESOLVED_SQL_CHECKSUM" ]; then
  echo "ERROR SQL checksum file not found: $RESOLVED_SQL_CHECKSUM" >&2
  exit 1
fi
if [ "$BACKUP_ARTIFACT_PATH" != "" ] && [ ! -f "$RESOLVED_BACKUP_ARTIFACT" ]; then
  echo "ERROR backup artifact not found: $RESOLVED_BACKUP_ARTIFACT" >&2
  exit 1
fi
if [ "$BACKUP_CHECKSUM_PATH" != "" ] && [ ! -f "$RESOLVED_BACKUP_CHECKSUM" ]; then
  echo "ERROR backup checksum file not found: $RESOLVED_BACKUP_CHECKSUM" >&2
  exit 1
fi

ACTUAL_SQL_SHA256=
if [ "$SQL_DUMP_PATH" != "" ]; then
  if command -v sha256sum >/dev/null 2>&1; then
    ACTUAL_SQL_SHA256=$(sha256sum "$RESOLVED_SQL_DUMP" | awk '{print tolower($1)}')
  elif command -v shasum >/dev/null 2>&1; then
    ACTUAL_SQL_SHA256=$(shasum -a 256 "$RESOLVED_SQL_DUMP" | awk '{print tolower($1)}')
  else
    echo "ERROR sha256sum or shasum is required." >&2
    exit 1
  fi
  if [ "$SQL_CHECKSUM_PATH" != "" ]; then
    EXPECTED_SQL_SHA256=$(awk '{print tolower($1)}' "$RESOLVED_SQL_CHECKSUM")
  fi
  if [ "$EXPECTED_SQL_SHA256" != "" ]; then
    expected=$(printf '%s' "$EXPECTED_SQL_SHA256" | tr 'A-F' 'a-f')
    if [ "$expected" != "$ACTUAL_SQL_SHA256" ]; then
      echo "ERROR SQL dump checksum mismatch. expected=$EXPECTED_SQL_SHA256 actual=$ACTUAL_SQL_SHA256" >&2
      exit 1
    fi
  fi
fi

ACTUAL_BACKUP_SHA256=
if [ "$BACKUP_ARTIFACT_PATH" != "" ]; then
  if command -v sha256sum >/dev/null 2>&1; then
    ACTUAL_BACKUP_SHA256=$(sha256sum "$RESOLVED_BACKUP_ARTIFACT" | awk '{print tolower($1)}')
  elif command -v shasum >/dev/null 2>&1; then
    ACTUAL_BACKUP_SHA256=$(shasum -a 256 "$RESOLVED_BACKUP_ARTIFACT" | awk '{print tolower($1)}')
  else
    echo "ERROR sha256sum or shasum is required." >&2
    exit 1
  fi
  if [ "$BACKUP_CHECKSUM_PATH" != "" ]; then
    EXPECTED_BACKUP_SHA256=$(awk '{print tolower($1)}' "$RESOLVED_BACKUP_CHECKSUM")
  fi
  if [ "$EXPECTED_BACKUP_SHA256" != "" ]; then
    expected_backup=$(printf '%s' "$EXPECTED_BACKUP_SHA256" | tr 'A-F' 'a-f')
    if [ "$expected_backup" != "$ACTUAL_BACKUP_SHA256" ]; then
      echo "ERROR backup artifact checksum mismatch. expected=$EXPECTED_BACKUP_SHA256 actual=$ACTUAL_BACKUP_SHA256" >&2
      exit 1
    fi
  fi
fi

mode=DRY-RUN
[ "$APPLY" = "1" ] && mode=APPLY

cat > "$STATUS_PATH" <<EOF
# Mongoyia Test Server Restore Status

- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Project root: $ROOT
- Mode: $mode
- SQL dump: $RESOLVED_SQL_DUMP
- SQL checksum: $RESOLVED_SQL_CHECKSUM
- SQL SHA256: $ACTUAL_SQL_SHA256
- Database: $DATABASE
- Delivery archive: $RESOLVED_DELIVERY
- Base URL: $BASE_URL
- IM URL: $IM_URL
- Allow production domain override: $ALLOW_PRODUCTION_DOMAIN_FOR_TEST
- Backup artifact: $RESOLVED_BACKUP_ARTIFACT
- Backup reference: $BACKUP_REFERENCE
- Backup SHA256: $ACTUAL_BACKUP_SHA256

EOF

echo "Mongoyia test-server restore orchestration"
echo "Mode: $mode"
echo "Status report: $STATUS_PATH"

{
  echo "## Apply safety gate"
  echo ""
} >> "$STATUS_PATH"
if [ "$APPLY" = "1" ] && [ "$SKIP_APPLY_SAFETY" != "1" ]; then
  safety_failures=
  [ "$BACKUP_CONFIRMED" = "1" ] || safety_failures="${safety_failures}
- FAIL BackupConfirmed is required before apply mode."
  [ "$BACKUP_ARTIFACT_PATH" != "" ] || [ "$BACKUP_REFERENCE" != "" ] || safety_failures="${safety_failures}
- FAIL BACKUP_ARTIFACT_PATH or BACKUP_REFERENCE is required before apply mode."
  [ "$APPLY_CONFIRM" = "RESTORE_OUTER_TEST_SERVER" ] || safety_failures="${safety_failures}
- FAIL APPLY_CONFIRM must equal RESTORE_OUTER_TEST_SERVER."
  [ "$SQL_DUMP_PATH" != "" ] || safety_failures="${safety_failures}
- FAIL SQL_DUMP_PATH is required before apply mode."
  [ "$SQL_CHECKSUM_PATH" != "" ] || safety_failures="${safety_failures}
- FAIL SQL_CHECKSUM_PATH is required before apply mode."
  [ "$DELIVERY_ARCHIVE_PATH" != "" ] || safety_failures="${safety_failures}
- FAIL DELIVERY_ARCHIVE_PATH is required before apply mode."
  case "$BASE_URL" in
    "") safety_failures="${safety_failures}
- FAIL BASE_URL is required before apply mode." ;;
    https://*) : ;;
    *) safety_failures="${safety_failures}
- FAIL BASE_URL must use https:// before apply mode." ;;
  esac
  case "$IM_URL" in
    "") safety_failures="${safety_failures}
- FAIL IM_URL is required before apply mode." ;;
    wss://*) : ;;
    *) safety_failures="${safety_failures}
- FAIL IM_URL must use wss:// before apply mode." ;;
  esac
  if printf '%s' "$BASE_URL" | grep -Eq 'localhost|127\.0\.0\.1|0\.0\.0\.0'; then
    safety_failures="${safety_failures}
- FAIL BASE_URL must not point to a local-only host before apply mode."
  fi
  if printf '%s' "$IM_URL" | grep -Eq 'localhost|127\.0\.0\.1|0\.0\.0\.0'; then
    safety_failures="${safety_failures}
- FAIL IM_URL must not point to a local-only host before apply mode."
  fi
  [ "$RUN_MIGRATE" = "1" ] || safety_failures="${safety_failures}
- FAIL RUN_MIGRATE=1 is required before apply mode."
  [ "$RUN_PREFLIGHT" = "1" ] || safety_failures="${safety_failures}
- FAIL RUN_PREFLIGHT=1 is required before apply mode."
  [ "$RUN_RECEIVER" = "1" ] || safety_failures="${safety_failures}
- FAIL RUN_RECEIVER=1 is required before apply mode."
  [ "$SKIP_INPUT_GATE" != "1" ] || safety_failures="${safety_failures}
- FAIL SKIP_INPUT_GATE=1 is not allowed before apply mode unless SKIP_APPLY_SAFETY=1 is also set."
  case "$DATABASE" in
    ""|mysql|information_schema|performance_schema|sys)
      safety_failures="${safety_failures}
- FAIL Refusing to restore into protected or empty database name: $DATABASE"
      ;;
  esac
  base_prod_host=$(production_domain_host "$BASE_URL" || true)
  if [ "$base_prod_host" != "" ] && [ "$ALLOW_PRODUCTION_DOMAIN_FOR_TEST" != "1" ]; then
    safety_failures="${safety_failures}
- FAIL BASE_URL points to production domain $base_prod_host. Use a test domain, or set ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1 only for an intentional exception."
  fi
  im_prod_host=$(production_domain_host "$IM_URL" || true)
  if [ "$im_prod_host" != "" ] && [ "$ALLOW_PRODUCTION_DOMAIN_FOR_TEST" != "1" ]; then
    safety_failures="${safety_failures}
- FAIL IM_URL points to production domain $im_prod_host. Use a test domain, or set ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1 only for an intentional exception."
  fi
  base_host=$(env_host "$BASE_URL")
  im_host=$(env_host "$IM_URL")
  if [ "$base_host" != "" ] && [ "$im_host" != "" ] && [ "$base_host" != "$im_host" ]; then
    safety_failures="${safety_failures}
- FAIL BaseUrl and ImUrl hosts must match before apply mode."
  fi
  if [ "$safety_failures" != "" ]; then
    echo "- Status: FAIL" >> "$STATUS_PATH"
    printf '%s\n' "$safety_failures" >> "$STATUS_PATH"
    echo "ERROR apply safety gate failed. See $STATUS_PATH" >&2
    exit 1
  fi
  echo "- Status: PASS" >> "$STATUS_PATH"
  echo "- Backup confirmation: present" >> "$STATUS_PATH"
  if [ "$BACKUP_ARTIFACT_PATH" != "" ]; then
    echo "- Backup artifact: $RESOLVED_BACKUP_ARTIFACT" >> "$STATUS_PATH"
    echo "- Backup SHA256: $ACTUAL_BACKUP_SHA256" >> "$STATUS_PATH"
  fi
  if [ "$BACKUP_REFERENCE" != "" ]; then
    echo "- Backup reference: $BACKUP_REFERENCE" >> "$STATUS_PATH"
  fi
  echo "- Apply confirmation phrase: matched" >> "$STATUS_PATH"
  echo "- Target database: $DATABASE" >> "$STATUS_PATH"
  echo "- Required follow-up steps: receiver validation, migrate, and strict preflight" >> "$STATUS_PATH"
elif [ "$APPLY" = "1" ]; then
  if [ "$SKIP_APPLY_SAFETY_CONFIRM" != "SKIP_RESTORE_APPLY_SAFETY" ]; then
    echo "- Status: FAIL" >> "$STATUS_PATH"
    echo "- FAIL SKIP_APPLY_SAFETY_CONFIRM must equal SKIP_RESTORE_APPLY_SAFETY when SKIP_APPLY_SAFETY=1 is used." >> "$STATUS_PATH"
    echo "ERROR SKIP_APPLY_SAFETY_CONFIRM must equal SKIP_RESTORE_APPLY_SAFETY when SKIP_APPLY_SAFETY=1 is used." >&2
    exit 1
  fi
  echo "- Status: SKIPPED by SKIP_APPLY_SAFETY=1" >> "$STATUS_PATH"
  echo "- SKIP_APPLY_SAFETY_CONFIRM: matched" >> "$STATUS_PATH"
else
  echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  echo "- Apply mode requires BACKUP_CONFIRMED=1, BACKUP_ARTIFACT_PATH or BACKUP_REFERENCE, APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER, DELIVERY_ARCHIVE_PATH, SQL_DUMP_PATH, SQL_CHECKSUM_PATH, RUN_RECEIVER=1, RUN_MIGRATE=1, RUN_PREFLIGHT=1, BASE_URL, IM_URL, and input gate unless SKIP_APPLY_SAFETY=1 is set." >> "$STATUS_PATH"
fi
echo "" >> "$STATUS_PATH"

{
  echo "## Test-server input gate"
  echo ""
} >> "$STATUS_PATH"
if [ "$APPLY" = "1" ] && [ "$SKIP_INPUT_GATE" != "1" ]; then
  run_step "test-server input gate" env PHP_ENV="$PHP_ENV" IM_ENV="$IM_ENV" BASE_URL="$BASE_URL" IM_URL="$IM_URL" DELIVERY_ARCHIVE_PATH="$RESOLVED_DELIVERY" SQL_DUMP_PATH="$RESOLVED_SQL_DUMP" SQL_CHECKSUM_PATH="$RESOLVED_SQL_CHECKSUM" EXPECTED_SQL_SHA256="$ACTUAL_SQL_SHA256" DATABASE="$DATABASE" BACKUP_REFERENCE="$BACKUP_REFERENCE" BACKUP_ARTIFACT_PATH="$RESOLVED_BACKUP_ARTIFACT" BACKUP_CHECKSUM_PATH="$RESOLVED_BACKUP_CHECKSUM" EXPECTED_BACKUP_SHA256="$ACTUAL_BACKUP_SHA256" PROFILE=test REQUIRE_RESTORE_INPUTS=1 OUTPUT_PATH="$WORK_DIR/INPUT_GATE.md" ALLOW_PRODUCTION_DOMAIN_FOR_TEST="$ALLOW_PRODUCTION_DOMAIN_FOR_TEST" sh "$SCRIPT_DIR/mongoyia-test-server-input-gate.sh"
  echo "- Status: PASS" >> "$STATUS_PATH"
elif [ "$APPLY" = "1" ]; then
  echo "- Status: SKIPPED by SKIP_INPUT_GATE=1" >> "$STATUS_PATH"
else
  echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  echo "- Apply mode will run this gate before database restore unless SKIP_INPUT_GATE=1 is passed." >> "$STATUS_PATH"
fi
echo "" >> "$STATUS_PATH"

if [ "$DELIVERY_ARCHIVE_PATH" != "" ] && { [ "$RUN_RECEIVER" = "1" ] || [ "$APPLY" != "1" ]; }; then
  {
    echo "## Receiver validation"
    echo ""
    echo "- Command: \`DELIVERY_ARCHIVE_PATH=$RESOLVED_DELIVERY sh console/shell/mongoyia-test-server-receiver.sh\`"
  } >> "$STATUS_PATH"
  if [ "$APPLY" = "1" ] && [ "$RUN_RECEIVER" = "1" ]; then
    run_step "receiver validation" env DELIVERY_ARCHIVE_PATH="$RESOLVED_DELIVERY" BASE_URL="$BASE_URL" SKIP_API="$SKIP_API" SKIP_CONNECTIVITY="$SKIP_CONNECTIVITY" sh "$SCRIPT_DIR/mongoyia-test-server-receiver.sh"
    echo "- Status: PASS" >> "$STATUS_PATH"
  else
    echo "DRY-RUN receiver validation: DELIVERY_ARCHIVE_PATH=$RESOLVED_DELIVERY sh console/shell/mongoyia-test-server-receiver.sh"
    echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  fi
  echo "" >> "$STATUS_PATH"
fi

{
  echo "## Go/no-go checklist"
  echo ""
} >> "$STATUS_PATH"
if [ "$APPLY" = "1" ] && [ "$SKIP_APPLY_SAFETY" != "1" ]; then
  run_step "go/no-go checklist" env OUTPUT_PATH="$WORK_DIR/GO_NO_GO.md" INPUT_GATE_PATH="$WORK_DIR/INPUT_GATE.md" EXTERNAL_INPUTS_CONFIRMED="$EXTERNAL_INPUTS_CONFIRMED" EXTERNAL_INPUTS_CONFIRM="$EXTERNAL_INPUTS_CONFIRM" sh "$SCRIPT_DIR/mongoyia-test-server-go-no-go.sh"
  echo "- Status: PASS" >> "$STATUS_PATH"
  echo "- Report: $WORK_DIR/GO_NO_GO.md" >> "$STATUS_PATH"
elif [ "$APPLY" = "1" ]; then
  echo "- Status: SKIPPED by SKIP_APPLY_SAFETY=1" >> "$STATUS_PATH"
else
  echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  echo "- Apply mode runs this checklist after input gate and receiver validation, before database restore." >> "$STATUS_PATH"
fi
echo "" >> "$STATUS_PATH"

if [ "$SQL_DUMP_PATH" != "" ]; then
  masked_password=
  mysql_args="--default-character-set=utf8mb4"
  if [ "$MYSQL_DEFAULTS_EXTRA_FILE" != "" ]; then
    mysql_args="$mysql_args --defaults-extra-file=$MYSQL_DEFAULTS_EXTRA_FILE"
  fi
  mysql_args="$mysql_args -h $MYSQL_HOST -P $MYSQL_PORT"
  if [ "$MYSQL_USER" != "" ]; then
    mysql_args="$mysql_args -u $MYSQL_USER"
  fi
  if [ "$MYSQL_PASSWORD" != "" ]; then
    masked_password=" MYSQL_PWD=***"
  fi
  {
    echo "## Database restore"
    echo ""
    echo "- SHA256: $ACTUAL_SQL_SHA256"
    echo "- Command: \`$masked_password $MYSQL_BIN $mysql_args $DATABASE < $RESOLVED_SQL_DUMP\`"
  } >> "$STATUS_PATH"
  if [ "$APPLY" = "1" ]; then
    echo "$masked_password $MYSQL_BIN $mysql_args $DATABASE < $RESOLVED_SQL_DUMP"
    if [ "$MYSQL_PASSWORD" != "" ]; then
      MYSQL_PWD="$MYSQL_PASSWORD" "$MYSQL_BIN" $mysql_args "$DATABASE" < "$RESOLVED_SQL_DUMP"
    else
      "$MYSQL_BIN" $mysql_args "$DATABASE" < "$RESOLVED_SQL_DUMP"
    fi
    echo "- Status: PASS" >> "$STATUS_PATH"
  else
    echo "DRY-RUN database restore:$masked_password $MYSQL_BIN $mysql_args $DATABASE < $RESOLVED_SQL_DUMP"
    echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  fi
  echo "" >> "$STATUS_PATH"
fi

if [ "$RUN_MIGRATE" = "1" ]; then
  {
    echo "## Migrations"
    echo ""
    echo "- Command: \`$PHP_BIN yii migrate/up --interactive=0\`"
  } >> "$STATUS_PATH"
  if [ "$APPLY" = "1" ]; then
    run_step "migrations" "$PHP_BIN" yii migrate/up --interactive=0
    echo "- Status: PASS" >> "$STATUS_PATH"
  else
    echo "DRY-RUN migrations: $PHP_BIN yii migrate/up --interactive=0"
    echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  fi
  echo "" >> "$STATUS_PATH"
fi

if [ "$RUN_PREFLIGHT" = "1" ]; then
  {
    echo "## Strict preflight"
    echo ""
    echo "- Command: \`BASE_URL=$BASE_URL PROFILE=test STRICT=1 sh console/shell/mongoyia-test-server-preflight-report.sh\`"
  } >> "$STATUS_PATH"
  if [ "$APPLY" = "1" ]; then
    run_step "strict preflight" env BASE_URL="$BASE_URL" PROFILE=test STRICT=1 PHP="$PHP_BIN" SKIP_API="$SKIP_API" SKIP_CONNECTIVITY="$SKIP_CONNECTIVITY" sh "$SCRIPT_DIR/mongoyia-test-server-preflight-report.sh"
    echo "- Status: PASS" >> "$STATUS_PATH"
  else
    echo "DRY-RUN strict preflight: BASE_URL=$BASE_URL PROFILE=test STRICT=1 sh console/shell/mongoyia-test-server-preflight-report.sh"
    echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  fi
  echo "" >> "$STATUS_PATH"
fi

if [ "$RUN_ACCEPTANCE" = "1" ]; then
  if [ "$IM_URL" = "" ]; then
    echo "ERROR RUN_ACCEPTANCE=1 requires IM_URL." >&2
    exit 1
  fi
  {
    echo "## Full acceptance"
    echo ""
    echo "- Command: \`PROFILE=test STRICT=1 BASE_URL=$BASE_URL IM_URL=$IM_URL sh console/shell/mongoyia-acceptance.sh\`"
  } >> "$STATUS_PATH"
  if [ "$APPLY" = "1" ]; then
    run_step "full acceptance" env PROFILE=test STRICT=1 BASE_URL="$BASE_URL" IM_URL="$IM_URL" PHP_BIN="$PHP_BIN" PYTHON_BIN="$PYTHON_BIN" CLEANUP_AFTER_RUN="$CLEANUP_AFTER_RUN" sh "$SCRIPT_DIR/mongoyia-acceptance.sh"
    echo "- Status: PASS" >> "$STATUS_PATH"
  else
    echo "DRY-RUN full acceptance: PROFILE=test STRICT=1 BASE_URL=$BASE_URL IM_URL=$IM_URL sh console/shell/mongoyia-acceptance.sh"
    echo "- Status: DRY-RUN" >> "$STATUS_PATH"
  fi
  echo "" >> "$STATUS_PATH"
fi

if [ "$APPLY" != "1" ]; then
  {
    echo "## Apply note"
    echo ""
    echo "This was a dry-run. Set \`APPLY=1\` to execute the selected steps."
  } >> "$STATUS_PATH"
fi

echo ""
echo "Restore orchestration status: $STATUS_PATH"
if [ "$APPLY" != "1" ]; then
  echo "Dry-run only. Set APPLY=1 to execute selected steps."
fi
