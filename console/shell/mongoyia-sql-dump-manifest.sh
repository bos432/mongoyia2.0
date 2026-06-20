#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

SQL_DUMP_PATH=${SQL_DUMP_PATH:-${1:-}}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
EXPECTED_SHA256=${EXPECTED_SHA256:-}
DATABASE=${DATABASE:-outer}

cd "$ROOT"

if [ "$SQL_DUMP_PATH" = "" ]; then
  echo "ERROR SQL_DUMP_PATH is required." >&2
  exit 1
fi

case "$SQL_DUMP_PATH" in
  /*) RESOLVED_SQL="$SQL_DUMP_PATH" ;;
  [A-Za-z]:*) RESOLVED_SQL="$SQL_DUMP_PATH" ;;
  *) RESOLVED_SQL="$ROOT/$SQL_DUMP_PATH" ;;
esac

if [ ! -f "$RESOLVED_SQL" ]; then
  echo "ERROR SQL dump not found: $RESOLVED_SQL" >&2
  exit 1
fi

OUTPUT_ROOT="$ROOT/$OUTPUT_DIR"
mkdir -p "$OUTPUT_ROOT"

if command -v sha256sum >/dev/null 2>&1; then
  HASH=$(sha256sum "$RESOLVED_SQL" | awk '{print tolower($1)}')
elif command -v shasum >/dev/null 2>&1; then
  HASH=$(shasum -a 256 "$RESOLVED_SQL" | awk '{print tolower($1)}')
else
  echo "ERROR sha256sum or shasum is required." >&2
  exit 1
fi

if [ "$EXPECTED_SHA256" != "" ]; then
  expected=$(printf '%s' "$EXPECTED_SHA256" | tr 'A-F' 'a-f')
  if [ "$expected" != "$HASH" ]; then
    echo "ERROR SQL dump checksum mismatch. expected=$EXPECTED_SHA256 actual=$HASH" >&2
    exit 1
  fi
fi

FILE_NAME=$(basename "$RESOLVED_SQL")
SIZE_BYTES=$(wc -c < "$RESOLVED_SQL" | tr -d ' ')
LINE_COUNT=$(wc -l < "$RESOLVED_SQL" | tr -d ' ')
CREATE_TABLES=$(grep -Eic '^[[:space:]]*CREATE[[:space:]]+TABLE\b' "$RESOLVED_SQL" || true)
INSERT_STATEMENTS=$(grep -Eic '^[[:space:]]*INSERT[[:space:]]+INTO\b' "$RESOLVED_SQL" || true)
DATABASE_MENTIONS=0
if [ "$DATABASE" != "" ]; then
  DATABASE_MENTIONS=$(grep -Fc "$DATABASE" "$RESOLVED_SQL" || true)
fi

RESULT=WARN
if [ "$CREATE_TABLES" -gt 0 ] && [ "$INSERT_STATEMENTS" -gt 0 ]; then
  RESULT=PASS
fi

HASH_PATH="$OUTPUT_ROOT/$FILE_NAME.sha256"
MANIFEST_PATH="$OUTPUT_ROOT/mongoyia-sql-dump-manifest-$STAMP.md"
printf '%s  %s\n' "$HASH" "$FILE_NAME" > "$HASH_PATH"

cat > "$MANIFEST_PATH" <<EOF
# Mongoyia SQL Dump Manifest

- Result: $RESULT
- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- SQL dump: $RESOLVED_SQL
- File name: $FILE_NAME
- Size bytes: $SIZE_BYTES
- SHA256: $HASH
- Sidecar checksum: $HASH_PATH
- Expected database: $DATABASE
- Line count: $LINE_COUNT
- CREATE TABLE statements: $CREATE_TABLES
- INSERT statements: $INSERT_STATEMENTS
- Database name mentions: $DATABASE_MENTIONS

## Receiver Notes

Copy the SQL dump and this .sha256 sidecar separately from the code delivery archive.
The code delivery archive intentionally excludes SQL dumps and production data.
Before restore, verify this SHA256 matches the receiver-side SQL file.
EOF

echo "SQL dump manifest: $MANIFEST_PATH"
echo "SQL dump checksum: $HASH_PATH"
echo "SHA256: $HASH"
echo "Result: $RESULT"
