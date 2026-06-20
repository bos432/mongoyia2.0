#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
ENV_PATH=${ENV_PATH:-.env}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/backups}
DATABASE=${DATABASE:-}
DUMP_BIN=${DUMP_BIN:-}
KEEP_DAYS=${KEEP_DAYS:-14}
INCLUDE_UPLOADS=${INCLUDE_UPLOADS:-0}
UPLOAD_DIR=${UPLOAD_DIR:-web/attachment}

cd "$ROOT"

resolve_path() {
  case "$1" in
    /*) printf '%s\n' "$1" ;;
    [A-Za-z]:*) printf '%s\n' "$1" ;;
    *) printf '%s\n' "$ROOT/$1" ;;
  esac
}

env_value() {
  file=$(resolve_path "$ENV_PATH")
  key=$1
  [ -f "$file" ] || return 0
  grep -E "^[[:space:]]*$key=" "$file" | tail -n 1 | sed "s/^[[:space:]]*$key=//; s/^['\"]//; s/['\"]$//"
}

dsn_part() {
  key=$1
  printf '%s' "$DB_DSN" | tr ';' '\n' | awk -F= -v key="$key" '$1 == key {print $2; exit}'
}

DB_DSN=$(env_value DB_DSN || true)
DB_HOST=$(env_value DB_HOST || true)
DB_PORT=$(env_value DB_PORT || true)
DB_NAME=$DATABASE
[ "$DB_HOST" != "" ] || DB_HOST=$(dsn_part host || true)
[ "$DB_PORT" != "" ] || DB_PORT=$(dsn_part port || true)
[ "$DB_NAME" != "" ] || DB_NAME=$(env_value DB_DATABASE || true)
[ "$DB_NAME" != "" ] || DB_NAME=$(dsn_part dbname || true)
[ "$DB_HOST" != "" ] || DB_HOST=127.0.0.1
[ "$DB_PORT" != "" ] || DB_PORT=3306
[ "$DB_NAME" != "" ] || { echo "Database name is empty." >&2; exit 1; }

DB_USER=$(env_value DB_USERNAME || true)
DB_PASS=$(env_value DB_PASSWORD || true)
[ "$DB_USER" != "" ] || { echo "DB_USERNAME is empty." >&2; exit 1; }

if [ "$DUMP_BIN" = "" ]; then
  if command -v mariadb-dump >/dev/null 2>&1; then DUMP_BIN=mariadb-dump
  elif command -v mysqldump >/dev/null 2>&1; then DUMP_BIN=mysqldump
  else echo "mariadb-dump/mysqldump not found. Set DUMP_BIN." >&2; exit 1
  fi
fi

OUT=$(resolve_path "$OUTPUT_DIR")
mkdir -p "$OUT"
STAMP=$(date +%Y%m%d-%H%M%S)
BASE="mongoyia-$DB_NAME-$STAMP"
SQL="$OUT/$BASE.sql"
GZ="$OUT/$BASE.sql.gz"
MANIFEST="$OUT/$BASE.md"

MYSQL_PWD=$DB_PASS "$DUMP_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --single-transaction --routines --triggers --events "$DB_NAME" > "$SQL"
gzip -f "$SQL"

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$GZ" > "$GZ.sha256"
else
  shasum -a 256 "$GZ" > "$GZ.sha256"
fi

UPLOAD_ARCHIVE=
if [ "$INCLUDE_UPLOADS" = "1" ]; then
  UPLOAD_FULL=$(resolve_path "$UPLOAD_DIR")
  if [ -d "$UPLOAD_FULL" ]; then
    UPLOAD_ARCHIVE="$OUT/$BASE-uploads.tar.gz"
    tar -czf "$UPLOAD_ARCHIVE" -C "$(dirname "$UPLOAD_FULL")" "$(basename "$UPLOAD_FULL")"
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$UPLOAD_ARCHIVE" > "$UPLOAD_ARCHIVE.sha256"; else shasum -a 256 "$UPLOAD_ARCHIVE" > "$UPLOAD_ARCHIVE.sha256"; fi
  fi
fi

find "$OUT" -type f -name 'mongoyia-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

{
  echo "# Mongoyia Production Backup"
  echo
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Database: $DB_NAME"
  echo "- Host: $DB_HOST"
  echo "- Port: $DB_PORT"
  echo "- Dump: $GZ"
  echo "- Dump checksum: $GZ.sha256"
  echo "- Upload archive: $UPLOAD_ARCHIVE"
  echo "- Keep days: $KEEP_DAYS"
  echo
  echo "This file intentionally omits database passwords and API secrets."
} > "$MANIFEST"

echo "Mongoyia production backup: $GZ"
echo "Checksum: $GZ.sha256"
echo "Manifest: $MANIFEST"
