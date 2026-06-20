#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

DELIVERY_ARCHIVE_PATH=${DELIVERY_ARCHIVE_PATH:-}
SQL_DUMP_PATH=${SQL_DUMP_PATH:-../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql}
SQL_CHECKSUM_PATH=${SQL_CHECKSUM_PATH:-runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256}
DATABASE=${DATABASE:-outer}
BASE_URL=${BASE_URL:-}
IM_URL=${IM_URL:-}
BACKUP_REFERENCE=${BACKUP_REFERENCE:-}
BACKUP_ARTIFACT_PATH=${BACKUP_ARTIFACT_PATH:-}
BACKUP_CHECKSUM_PATH=${BACKUP_CHECKSUM_PATH:-}
LINUX_DELIVERY_ARCHIVE_PATH=${LINUX_DELIVERY_ARCHIVE_PATH:-}
LINUX_SQL_DUMP_PATH=${LINUX_SQL_DUMP_PATH:-}
LINUX_SQL_CHECKSUM_PATH=${LINUX_SQL_CHECKSUM_PATH:-}
LINUX_BACKUP_ARTIFACT_PATH=${LINUX_BACKUP_ARTIFACT_PATH:-}
LINUX_BACKUP_CHECKSUM_PATH=${LINUX_BACKUP_CHECKSUM_PATH:-}
OUTPUT_PATH=${OUTPUT_PATH:-}

cd "$ROOT"

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

latest_delivery() {
  file=$(find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-test-server-delivery-*.tar.gz' 2>/dev/null | sort | tail -n 1)
  if [ "$file" = "" ]; then
    file=$(find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-test-server-delivery-*.zip' 2>/dev/null | sort | tail -n 1)
  fi
  printf '%s\n' "$file"
}

sh_quote() {
  printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

portable_linux_path() {
  path=$1
  prefix=$2
  if [ "$path" = "" ]; then
    printf '\n'
    return
  fi
  leaf=$(basename "$path")
  if [ "$prefix" = "" ]; then
    printf '%s\n' "$leaf"
  else
    printf '%s/%s\n' "$(printf '%s' "$prefix" | sed 's,/*$,,')" "$leaf"
  fi
}

production_domain_host() {
  value=$1
  [ "$value" = "" ] && return 1
  candidate=$(printf '%s' "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  case "$candidate" in
    *://*) host=$(printf '%s' "$candidate" | sed -n 's,^[A-Za-z][A-Za-z0-9+.-]*://\([^/:]*\).*,\1,p') ;;
    *) host=$(printf '%s' "$candidate" | cut -d/ -f1 | cut -d: -f1) ;;
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

is_placeholder() {
  value=$(printf '%s' "$1" | tr 'A-Z' 'a-z')
  [ "$value" = "" ] && return 0
  case "$value" in
    replace-with-*|*example.com*|*placeholder*|*changeme*|*change-me*|*your-*) return 0 ;;
  esac
  return 1
}

if [ "$DELIVERY_ARCHIVE_PATH" = "" ]; then
  DELIVERY_ARCHIVE_PATH=$(latest_delivery)
else
  DELIVERY_ARCHIVE_PATH=$(resolve_path "$DELIVERY_ARCHIVE_PATH")
fi
SQL_DUMP_PATH=$(resolve_path "$SQL_DUMP_PATH")
SQL_CHECKSUM_PATH=$(resolve_path "$SQL_CHECKSUM_PATH")
BACKUP_ARTIFACT_PATH=$(resolve_path "$BACKUP_ARTIFACT_PATH")
BACKUP_CHECKSUM_PATH=$(resolve_path "$BACKUP_CHECKSUM_PATH")

[ "$LINUX_DELIVERY_ARCHIVE_PATH" != "" ] || LINUX_DELIVERY_ARCHIVE_PATH=$(portable_linux_path "$DELIVERY_ARCHIVE_PATH" "runtime/handover")
[ "$LINUX_SQL_DUMP_PATH" != "" ] || LINUX_SQL_DUMP_PATH=$(portable_linux_path "$SQL_DUMP_PATH" "")
[ "$LINUX_SQL_CHECKSUM_PATH" != "" ] || LINUX_SQL_CHECKSUM_PATH=$(portable_linux_path "$SQL_CHECKSUM_PATH" "runtime/handover")
[ "$LINUX_BACKUP_ARTIFACT_PATH" != "" ] || LINUX_BACKUP_ARTIFACT_PATH=$(portable_linux_path "$BACKUP_ARTIFACT_PATH" "backups")
[ "$LINUX_BACKUP_CHECKSUM_PATH" != "" ] || LINUX_BACKUP_CHECKSUM_PATH=$(portable_linux_path "$BACKUP_CHECKSUM_PATH" "backups")

MISSING=$(mktemp)
trap 'rm -f "$MISSING"' EXIT

add_missing() {
  printf '%s\n' "- $1" >> "$MISSING"
}

[ "$DELIVERY_ARCHIVE_PATH" != "" ] && [ -f "$DELIVERY_ARCHIVE_PATH" ] || add_missing "Delivery archive is missing."
[ "$SQL_DUMP_PATH" != "" ] && [ -f "$SQL_DUMP_PATH" ] || add_missing "SQL dump file is missing."
[ "$SQL_CHECKSUM_PATH" != "" ] && [ -f "$SQL_CHECKSUM_PATH" ] || add_missing "SQL checksum sidecar is missing."
case "$DATABASE" in
  ""|mysql|information_schema|performance_schema|sys) add_missing "Target database must be a non-system database name, usually outer." ;;
esac
case "$BASE_URL" in
  https://*) : ;;
  *) add_missing "BaseUrl must be a real HTTPS test URL." ;;
esac
case "$IM_URL" in
  wss://*) : ;;
  *) add_missing "ImUrl must be a real WSS test URL." ;;
esac
is_placeholder "$BASE_URL" && add_missing "BaseUrl still looks like a placeholder."
is_placeholder "$IM_URL" && add_missing "ImUrl still looks like a placeholder."
printf '%s' "$BASE_URL" | grep -Eq 'localhost|127\.0\.0\.1|0\.0\.0\.0' && add_missing "BaseUrl must not point to a local-only host for test apply mode."
printf '%s' "$IM_URL" | grep -Eq 'localhost|127\.0\.0\.1|0\.0\.0\.0' && add_missing "ImUrl must not point to a local-only host for test apply mode."
base_host=$(env_host "$BASE_URL")
im_host=$(env_host "$IM_URL")
[ "$base_host" = "" ] || [ "$im_host" = "" ] || [ "$base_host" = "$im_host" ] || add_missing "BaseUrl and ImUrl hosts must match the same test domain."
base_prod=$(production_domain_host "$BASE_URL" || true)
[ "$base_prod" = "" ] || add_missing "BaseUrl points to production domain $base_prod; use a test domain."
im_prod=$(production_domain_host "$IM_URL" || true)
[ "$im_prod" = "" ] || add_missing "ImUrl points to production domain $im_prod; use a test domain."
[ "$BACKUP_REFERENCE" != "" ] || [ "$BACKUP_ARTIFACT_PATH" != "" ] || add_missing "BACKUP_REFERENCE or BACKUP_ARTIFACT_PATH is required before apply mode."
[ "$BACKUP_ARTIFACT_PATH" = "" ] || [ -f "$BACKUP_ARTIFACT_PATH" ] || add_missing "BACKUP_ARTIFACT_PATH does not exist."
[ "$BACKUP_CHECKSUM_PATH" = "" ] || [ -f "$BACKUP_CHECKSUM_PATH" ] || add_missing "BACKUP_CHECKSUM_PATH does not exist."

if [ "$OUTPUT_PATH" = "" ]; then
  OUTPUT_PATH="runtime/handover/mongoyia-test-server-restore-plan-$(date +%Y%m%d-%H%M%S).md"
fi
OUTPUT_FULL=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUTPUT_FULL")"

result=READY
[ -s "$MISSING" ] && result=PENDING

apply_backup_lines=
ps_apply_backup_args=
if [ "$BACKUP_REFERENCE" != "" ]; then
  apply_backup_lines="${apply_backup_lines}BACKUP_REFERENCE=$(sh_quote "$BACKUP_REFERENCE") \\
"
  ps_apply_backup_args="$ps_apply_backup_args -BackupReference \"$BACKUP_REFERENCE\""
fi
if [ "$BACKUP_ARTIFACT_PATH" != "" ]; then
  apply_backup_lines="${apply_backup_lines}BACKUP_ARTIFACT_PATH=$(sh_quote "$LINUX_BACKUP_ARTIFACT_PATH") \\
"
  ps_apply_backup_args="$ps_apply_backup_args -BackupArtifactPath \"$BACKUP_ARTIFACT_PATH\""
fi
if [ "$BACKUP_CHECKSUM_PATH" != "" ]; then
  apply_backup_lines="${apply_backup_lines}BACKUP_CHECKSUM_PATH=$(sh_quote "$LINUX_BACKUP_CHECKSUM_PATH") \\
"
  ps_apply_backup_args="$ps_apply_backup_args -BackupChecksumPath \"$BACKUP_CHECKSUM_PATH\""
fi

{
  echo "# Mongoyia Test Server Restore Plan"
  echo ""
  echo "- Result: $result"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Delivery archive: $DELIVERY_ARCHIVE_PATH"
  echo "- SQL dump: $SQL_DUMP_PATH"
  echo "- SQL checksum: $SQL_CHECKSUM_PATH"
  echo "- Database: $DATABASE"
  echo "- BaseUrl: $BASE_URL"
  echo "- BaseUrl host: $base_host"
  echo "- ImUrl: $IM_URL"
  echo "- ImUrl host: $im_host"
  echo "- Backup reference: $BACKUP_REFERENCE"
  echo "- Backup artifact: $BACKUP_ARTIFACT_PATH"
  echo ""
  echo "## Linux Path Mapping"
  echo ""
  echo "| Item | Linux/Test-Server Path Used In Bash Commands |"
  echo "|---|---|"
  echo "| Delivery archive | \`$LINUX_DELIVERY_ARCHIVE_PATH\` |"
  echo "| SQL dump | \`$LINUX_SQL_DUMP_PATH\` |"
  echo "| SQL checksum | \`$LINUX_SQL_CHECKSUM_PATH\` |"
  echo "| Backup artifact | \`$LINUX_BACKUP_ARTIFACT_PATH\` |"
  echo "| Backup checksum | \`$LINUX_BACKUP_CHECKSUM_PATH\` |"
  echo ""
  echo "## Missing Or Unsafe Inputs"
  echo ""
  if [ -s "$MISSING" ]; then cat "$MISSING"; else echo "- No missing inputs detected by this planner."; fi
  echo ""
  echo "## Windows Commands"
  echo ""
  echo "Receiver validation:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-test-server-receiver.ps1 -DeliveryArchivePath \"$DELIVERY_ARCHIVE_PATH\" -BaseUrl \"$BASE_URL\""
  echo '```'
  echo ""
  echo "Input gate:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-test-server-input-gate.ps1 -BaseUrl \"$BASE_URL\" -ImUrl \"$IM_URL\" -DeliveryArchivePath \"$DELIVERY_ARCHIVE_PATH\" -SqlDumpPath \"$SQL_DUMP_PATH\" -SqlChecksumPath \"$SQL_CHECKSUM_PATH\" -Database \"$DATABASE\" -BackupReference \"$BACKUP_REFERENCE\" -BackupArtifactPath \"$BACKUP_ARTIFACT_PATH\" -BackupChecksumPath \"$BACKUP_CHECKSUM_PATH\" -RequireRestoreInputs"
  echo '```'
  echo ""
  echo "Restore dry-run:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-test-server-restore.ps1 -DeliveryArchivePath \"$DELIVERY_ARCHIVE_PATH\" -SqlDumpPath \"$SQL_DUMP_PATH\" -SqlChecksumPath \"$SQL_CHECKSUM_PATH\" -Database \"$DATABASE\" -BaseUrl \"$BASE_URL\" -RunReceiver -RunMigrate -RunPreflight"
  echo '```'
  echo ""
  echo "Restore dry-run with full acceptance:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-test-server-restore.ps1 -DeliveryArchivePath \"$DELIVERY_ARCHIVE_PATH\" -SqlDumpPath \"$SQL_DUMP_PATH\" -SqlChecksumPath \"$SQL_CHECKSUM_PATH\" -Database \"$DATABASE\" -BaseUrl \"$BASE_URL\" -ImUrl \"$IM_URL\" -RunReceiver -RunMigrate -RunPreflight -RunAcceptance -CleanupAfterRun"
  echo '```'
  echo ""
  echo "Apply restore after dry-run, backup, and input gate are approved:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-test-server-restore.ps1 -Apply -BackupConfirmed -ApplyConfirm RESTORE_OUTER_TEST_SERVER -ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED$ps_apply_backup_args -DeliveryArchivePath \"$DELIVERY_ARCHIVE_PATH\" -SqlDumpPath \"$SQL_DUMP_PATH\" -SqlChecksumPath \"$SQL_CHECKSUM_PATH\" -Database \"$DATABASE\" -BaseUrl \"$BASE_URL\" -ImUrl \"$IM_URL\" -RunReceiver -RunMigrate -RunPreflight"
  echo '```'
  echo ""
  echo "Apply restore and then run full acceptance after dry-run, backup, and input gate are approved:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-test-server-restore.ps1 -Apply -BackupConfirmed -ApplyConfirm RESTORE_OUTER_TEST_SERVER -ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED$ps_apply_backup_args -DeliveryArchivePath \"$DELIVERY_ARCHIVE_PATH\" -SqlDumpPath \"$SQL_DUMP_PATH\" -SqlChecksumPath \"$SQL_CHECKSUM_PATH\" -Database \"$DATABASE\" -BaseUrl \"$BASE_URL\" -ImUrl \"$IM_URL\" -RunReceiver -RunMigrate -RunPreflight -RunAcceptance -CleanupAfterRun"
  echo '```'
  echo ""
  echo "Full acceptance only, after restore and strict preflight are PASS:"
  echo ""
  echo '```powershell'
  printf '%s\n' ".\\console\\shell\\mongoyia-acceptance.ps1 -BaseUrl \"$BASE_URL\" -Profile test -Strict -CleanupAfterRun -ImUrl \"$IM_URL\""
  echo '```'
  echo ""
  echo "## Linux Commands"
  echo ""
  echo "Input gate:"
  echo ""
  echo '```bash'
  echo "DELIVERY_ARCHIVE_PATH=$(sh_quote "$LINUX_DELIVERY_ARCHIVE_PATH") \\"
  echo "SQL_DUMP_PATH=$(sh_quote "$LINUX_SQL_DUMP_PATH") \\"
  echo "SQL_CHECKSUM_PATH=$(sh_quote "$LINUX_SQL_CHECKSUM_PATH") \\"
  echo "DATABASE=$(sh_quote "$DATABASE") \\"
  echo "BASE_URL=$(sh_quote "$BASE_URL") \\"
  echo "IM_URL=$(sh_quote "$IM_URL") \\"
  echo "BACKUP_REFERENCE=$(sh_quote "$BACKUP_REFERENCE") \\"
  echo "BACKUP_ARTIFACT_PATH=$(sh_quote "$LINUX_BACKUP_ARTIFACT_PATH") \\"
  echo "BACKUP_CHECKSUM_PATH=$(sh_quote "$LINUX_BACKUP_CHECKSUM_PATH") \\"
  echo "REQUIRE_RESTORE_INPUTS=1 \\"
  echo "sh console/shell/mongoyia-test-server-input-gate.sh"
  echo '```'
  echo ""
  echo "Restore dry-run:"
  echo ""
  echo '```bash'
  echo "DELIVERY_ARCHIVE_PATH=$(sh_quote "$LINUX_DELIVERY_ARCHIVE_PATH") \\"
  echo "SQL_DUMP_PATH=$(sh_quote "$LINUX_SQL_DUMP_PATH") \\"
  echo "SQL_CHECKSUM_PATH=$(sh_quote "$LINUX_SQL_CHECKSUM_PATH") \\"
  echo "DATABASE=$(sh_quote "$DATABASE") \\"
  echo "BASE_URL=$(sh_quote "$BASE_URL") \\"
  echo "RUN_RECEIVER=1 \\"
  echo "RUN_MIGRATE=1 \\"
  echo "RUN_PREFLIGHT=1 \\"
  echo "sh console/shell/mongoyia-test-server-restore.sh"
  echo '```'
  echo ""
  echo "Restore dry-run with full acceptance:"
  echo ""
  echo '```bash'
  echo "DELIVERY_ARCHIVE_PATH=$(sh_quote "$LINUX_DELIVERY_ARCHIVE_PATH") \\"
  echo "SQL_DUMP_PATH=$(sh_quote "$LINUX_SQL_DUMP_PATH") \\"
  echo "SQL_CHECKSUM_PATH=$(sh_quote "$LINUX_SQL_CHECKSUM_PATH") \\"
  echo "DATABASE=$(sh_quote "$DATABASE") \\"
  echo "BASE_URL=$(sh_quote "$BASE_URL") \\"
  echo "IM_URL=$(sh_quote "$IM_URL") \\"
  echo "RUN_RECEIVER=1 \\"
  echo "RUN_MIGRATE=1 \\"
  echo "RUN_PREFLIGHT=1 \\"
  echo "RUN_ACCEPTANCE=1 \\"
  echo "CLEANUP_AFTER_RUN=1 \\"
  echo "sh console/shell/mongoyia-test-server-restore.sh"
  echo '```'
  echo ""
  echo "Apply restore after dry-run, backup, and input gate are approved:"
  echo ""
  echo '```bash'
  echo "APPLY=1 \\"
  echo "BACKUP_CONFIRMED=1 \\"
  echo "APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER \\"
  echo "EXTERNAL_INPUTS_CONFIRMED=1 \\"
  echo "EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED \\"
  printf '%s' "$apply_backup_lines"
  echo "DELIVERY_ARCHIVE_PATH=$(sh_quote "$LINUX_DELIVERY_ARCHIVE_PATH") \\"
  echo "SQL_DUMP_PATH=$(sh_quote "$LINUX_SQL_DUMP_PATH") \\"
  echo "SQL_CHECKSUM_PATH=$(sh_quote "$LINUX_SQL_CHECKSUM_PATH") \\"
  echo "DATABASE=$(sh_quote "$DATABASE") \\"
  echo "BASE_URL=$(sh_quote "$BASE_URL") \\"
  echo "IM_URL=$(sh_quote "$IM_URL") \\"
  echo "RUN_RECEIVER=1 \\"
  echo "RUN_MIGRATE=1 \\"
  echo "RUN_PREFLIGHT=1 \\"
  echo "sh console/shell/mongoyia-test-server-restore.sh"
  echo '```'
  echo ""
  echo "Apply restore and then run full acceptance after dry-run, backup, and input gate are approved:"
  echo ""
  echo '```bash'
  echo "APPLY=1 \\"
  echo "BACKUP_CONFIRMED=1 \\"
  echo "APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER \\"
  echo "EXTERNAL_INPUTS_CONFIRMED=1 \\"
  echo "EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED \\"
  printf '%s' "$apply_backup_lines"
  echo "DELIVERY_ARCHIVE_PATH=$(sh_quote "$LINUX_DELIVERY_ARCHIVE_PATH") \\"
  echo "SQL_DUMP_PATH=$(sh_quote "$LINUX_SQL_DUMP_PATH") \\"
  echo "SQL_CHECKSUM_PATH=$(sh_quote "$LINUX_SQL_CHECKSUM_PATH") \\"
  echo "DATABASE=$(sh_quote "$DATABASE") \\"
  echo "BASE_URL=$(sh_quote "$BASE_URL") \\"
  echo "IM_URL=$(sh_quote "$IM_URL") \\"
  echo "RUN_RECEIVER=1 \\"
  echo "RUN_MIGRATE=1 \\"
  echo "RUN_PREFLIGHT=1 \\"
  echo "RUN_ACCEPTANCE=1 \\"
  echo "CLEANUP_AFTER_RUN=1 \\"
  echo "sh console/shell/mongoyia-test-server-restore.sh"
  echo '```'
  echo ""
  echo "Full acceptance only, after restore and strict preflight are PASS:"
  echo ""
  echo '```bash'
  echo "PROFILE=test \\"
  echo "STRICT=1 \\"
  echo "CLEANUP_AFTER_RUN=1 \\"
  echo "BASE_URL=$(sh_quote "$BASE_URL") \\"
  echo "IM_URL=$(sh_quote "$IM_URL") \\"
  echo "sh console/shell/mongoyia-acceptance.sh"
  echo '```'
  echo ""
  echo "## Notes"
  echo ""
  echo "- This planner does not run the restore and does not print secrets."
  echo "- Windows commands use the local paths shown at the top; Linux commands use the Linux/Test-Server paths above."
  echo "- Do not use mongoyia.com or www.mongoyia.com for test-server restore."
  echo "- Apply mode must run only after a backup/snapshot is confirmed."
  echo "- Emergency apply-safety bypass is not part of the normal plan; if a documented emergency requires it, PowerShell must include \`-SkipApplySafety -SkipApplySafetyConfirm SKIP_RESTORE_APPLY_SAFETY\`, and Bash must include \`SKIP_APPLY_SAFETY=1 SKIP_APPLY_SAFETY_CONFIRM=SKIP_RESTORE_APPLY_SAFETY\`."
} > "$OUTPUT_FULL"

echo "Test-server restore plan: $OUTPUT_FULL"
echo "Result: $result"
