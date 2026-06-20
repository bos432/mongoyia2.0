#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
BACKUP_ARCHIVE=${BACKUP_ARCHIVE:-}
BACKUP_DIR=${BACKUP_DIR:-runtime/backups}
UPLOAD_ARCHIVE=${UPLOAD_ARCHIVE:-}
REPORT_PATH=${REPORT_PATH:-}
REQUIRE_UPLOADS=${REQUIRE_UPLOADS:-0}

cd "$ROOT"

resolve_path() {
  case "$1" in
    "") printf '\n' ;;
    /*) printf '%s\n' "$1" ;;
    [A-Za-z]:*) printf '%s\n' "$1" ;;
    *) printf '%s\n' "$ROOT/$1" ;;
  esac
}

latest_backup_archive() {
  dir=$(resolve_path "$BACKUP_DIR")
  [ -d "$dir" ] || return 0
  find "$dir" -maxdepth 1 -type f \( -name 'mongoyia-*.sql.gz' -o -name 'mongoyia-*.sql.zip' \) 2>/dev/null | sort | tail -n 1
}

add_check() {
  name=$1
  pass=$2
  detail=$3
  CHECK_LINES="${CHECK_LINES}
- $pass $name: $detail"
  [ "$pass" = "PASS" ] || FAILURES=$((FAILURES + 1))
}

checksum_value() {
  archive=$1
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$archive" | awk '{print $1}'
  else
    shasum -a 256 "$archive" | awk '{print $1}'
  fi
}

verify_checksum() {
  archive=$1
  sidecar="$archive.sha256"
  if [ ! -f "$sidecar" ]; then
    add_check "checksum sidecar" "FAIL" "Missing $sidecar"
    return
  fi
  expected=$(awk '{print tolower($1); exit}' "$sidecar")
  actual=$(checksum_value "$archive")
  if [ "$expected" = "$actual" ]; then
    add_check "checksum match" "PASS" "expected=$expected actual=$actual"
  else
    add_check "checksum match" "FAIL" "expected=$expected actual=$actual"
  fi
}

verify_archive_readable() {
  archive=$1
  require_sql=$2
  case "$archive" in
    *.zip)
      if command -v unzip >/dev/null 2>&1; then
        entries=$(unzip -Z1 "$archive" 2>/dev/null | wc -l | tr -d ' ')
        [ "$entries" -gt 0 ] && add_check "zip readable" "PASS" "entries=$entries" || add_check "zip readable" "FAIL" "entries=0"
        if [ "$require_sql" = "1" ]; then
          sql_entries=$(unzip -Z1 "$archive" 2>/dev/null | grep -c '\.sql$' || true)
          [ "$sql_entries" -gt 0 ] && add_check "sql entry exists" "PASS" "sql_entries=$sql_entries" || add_check "sql entry exists" "FAIL" "sql_entries=0"
        fi
      else
        add_check "zip readable" "FAIL" "unzip command not found"
      fi
      ;;
    *.tar.gz)
      if tar -tzf "$archive" >/dev/null 2>&1; then
        entries=$(tar -tzf "$archive" | wc -l | tr -d ' ')
        add_check "tar.gz readable" "PASS" "entries=$entries"
      else
        add_check "tar.gz readable" "FAIL" "tar could not list archive"
      fi
      ;;
    *.gz)
      if gzip -t "$archive" >/dev/null 2>&1; then
        add_check "gzip readable" "PASS" "gzip -t ok"
      else
        add_check "gzip readable" "FAIL" "gzip -t failed"
      fi
      ;;
    *)
      add_check "archive type" "FAIL" "Unsupported archive type: $archive"
      ;;
  esac
}

CHECK_LINES=
FAILURES=0

BACKUP_ARCHIVE=$(resolve_path "$BACKUP_ARCHIVE")
if [ "$BACKUP_ARCHIVE" = "" ]; then
  BACKUP_ARCHIVE=$(latest_backup_archive || true)
fi

if [ "$REPORT_PATH" = "" ]; then
  REPORT_PATH="runtime/handover/mongoyia-production-backup-verify-$(date +%Y%m%d-%H%M%S).md"
fi
REPORT_PATH=$(resolve_path "$REPORT_PATH")
mkdir -p "$(dirname "$REPORT_PATH")"

if [ "$BACKUP_ARCHIVE" = "" ] || [ ! -f "$BACKUP_ARCHIVE" ]; then
  add_check "database backup archive" "FAIL" "Backup archive not found. Set BACKUP_ARCHIVE or create one first."
else
  add_check "database backup archive" "PASS" "$BACKUP_ARCHIVE"
  verify_checksum "$BACKUP_ARCHIVE"
  verify_archive_readable "$BACKUP_ARCHIVE" "1"
fi

UPLOAD_ARCHIVE=$(resolve_path "$UPLOAD_ARCHIVE")
if [ "$UPLOAD_ARCHIVE" = "" ]; then
  if [ "$REQUIRE_UPLOADS" = "1" ]; then
    add_check "upload archive" "FAIL" "Upload archive required but not provided."
  else
    add_check "upload archive" "PASS" "Upload archive not provided."
  fi
elif [ ! -f "$UPLOAD_ARCHIVE" ]; then
  add_check "upload archive" "FAIL" "Missing $UPLOAD_ARCHIVE"
else
  add_check "upload archive" "PASS" "$UPLOAD_ARCHIVE"
  verify_checksum "$UPLOAD_ARCHIVE"
  verify_archive_readable "$UPLOAD_ARCHIVE" "0"
fi

STATUS=PASS
[ "$FAILURES" -eq 0 ] || STATUS=FAIL

{
  echo "# Mongoyia Production Backup Verify"
  echo
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Status: $STATUS"
  echo "- Database backup archive: $BACKUP_ARCHIVE"
  echo "- Upload archive: $UPLOAD_ARCHIVE"
  echo
  echo "## Checks"
  printf '%s\n' "$CHECK_LINES"
} > "$REPORT_PATH"

echo "Mongoyia production backup verify: $STATUS"
echo "Report: $REPORT_PATH"
[ "$FAILURES" -eq 0 ]
