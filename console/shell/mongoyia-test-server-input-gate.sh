#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
BASE_URL=${BASE_URL:-}
IM_URL=${IM_URL:-}
DELIVERY_ARCHIVE_PATH=${DELIVERY_ARCHIVE_PATH:-}
SQL_DUMP_PATH=${SQL_DUMP_PATH:-}
SQL_CHECKSUM_PATH=${SQL_CHECKSUM_PATH:-}
EXPECTED_SQL_SHA256=${EXPECTED_SQL_SHA256:-}
DATABASE=${DATABASE:-outer}
BACKUP_REFERENCE=${BACKUP_REFERENCE:-}
BACKUP_ARTIFACT_PATH=${BACKUP_ARTIFACT_PATH:-}
BACKUP_CHECKSUM_PATH=${BACKUP_CHECKSUM_PATH:-}
EXPECTED_BACKUP_SHA256=${EXPECTED_BACKUP_SHA256:-}
PROFILE=${PROFILE:-test}
OUTPUT_PATH=${OUTPUT_PATH:-}
REQUIRE_RESTORE_INPUTS=${REQUIRE_RESTORE_INPUTS:-0}
ALLOW_PRODUCTION_DOMAIN_FOR_TEST=${ALLOW_PRODUCTION_DOMAIN_FOR_TEST:-0}

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

env_value() {
  file=$1
  key=$2
  [ -f "$file" ] || return 0
  awk -F= -v k="$key" '
    $0 ~ "^[[:space:]]*#" { next }
    $1 == k {
      sub(/^[^=]*=/, "", $0)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", $0)
      gsub(/^'\''|'\''$/, "", $0)
      gsub(/^"|"$/, "", $0)
      print $0
      exit
    }
  ' "$file"
}

is_placeholder() {
  value=$(printf '%s' "$1" | tr 'A-Z' 'a-z')
  [ "$value" = "" ] && return 0
  case "$value" in
    password|replace-with-*|*example.com*|*placeholder*|*changeme*|*change-me*|*your-*) return 0 ;;
  esac
  return 1
}

failures=0
CHECKS_FILE=$(mktemp)
trap 'rm -f "$CHECKS_FILE"' EXIT

add_fail() {
  failures=$((failures + 1))
  printf '%s\n' "- FAIL $1" >> "$CHECKS_FILE"
}

add_pass() {
  printf '%s\n' "- PASS $1" >> "$CHECKS_FILE"
}

require_file() {
  label=$1
  path=$2
  if [ "$path" = "" ]; then
    add_fail "$label is required."
    printf '\n'
    return
  fi
  resolved=$(resolve_path "$path")
  if [ ! -f "$resolved" ]; then
    add_fail "$label does not exist: $resolved"
    printf '\n'
  else
    add_pass "$label exists."
    printf '%s\n' "$resolved"
  fi
}

hash_file() {
  path=$1
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$path" | awk '{print tolower($1)}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$path" | awk '{print tolower($1)}'
  else
    add_fail "sha256sum or shasum is required."
    printf '\n'
  fi
}

read_sha256() {
  awk '{print tolower($1); exit}' "$1"
}

check_sha256() {
  label=$1
  artifact=$2
  checksum=$3
  expected=$4
  [ "$artifact" != "" ] || return 0
  if [ ! -f "$artifact" ]; then
    add_fail "$label file does not exist for SHA256 check: $artifact"
    return 0
  fi
  actual=$(hash_file "$artifact")
  if [ "$checksum" != "" ]; then
    if [ ! -f "$checksum" ]; then
      add_fail "$label checksum file does not exist for SHA256 check: $checksum"
      return 0
    fi
    expected=$(read_sha256 "$checksum")
  fi
  if [ "$expected" = "" ]; then
    add_fail "$label SHA256 expectation is required."
  elif [ "$actual" != "$(printf '%s' "$expected" | tr 'A-F' 'a-f')" ]; then
    add_fail "$label SHA256 mismatch. expected=$expected actual=$actual"
  else
    add_pass "$label SHA256 matches."
  fi
}

require_key() {
  source=$1
  file=$2
  key=$3
  value=$(env_value "$file" "$key")
  if [ "$value" = "" ]; then
    add_fail "$source $key is missing or empty."
  elif is_placeholder "$value"; then
    add_fail "$source $key still looks like a placeholder."
  else
    add_pass "$source $key is set."
  fi
}

require_secret() {
  source=$1
  file=$2
  key=$3
  min_len=$4
  value=$(env_value "$file" "$key")
  len=$(printf '%s' "$value" | wc -c | tr -d ' ')
  if [ "$value" = "" ]; then
    add_fail "$source $key is missing or empty."
  elif is_placeholder "$value"; then
    add_fail "$source $key still looks like a placeholder."
  elif [ "$len" -lt "$min_len" ]; then
    add_fail "$source $key must be at least $min_len characters."
  else
    add_pass "$source $key is present and long enough."
  fi
}

require_positive_int() {
  source=$1
  file=$2
  key=$3
  max=${4:-}
  value=$(env_value "$file" "$key")
  if [ "$value" = "" ]; then
    add_fail "$source $key is missing or empty."
  elif ! printf '%s' "$value" | grep -Eq '^[0-9]+$'; then
    add_fail "$source $key must be a positive integer."
  elif [ "$value" -le 0 ]; then
    add_fail "$source $key must be a positive integer."
  elif [ "$max" != "" ] && [ "$value" -gt "$max" ]; then
    add_fail "$source $key must be less than or equal to $max."
  else
    add_pass "$source $key is a positive integer."
  fi
}

require_bind_host() {
  source=$1
  file=$2
  key=$3
  value=$(env_value "$file" "$key")
  if [ "$value" = "" ]; then
    add_fail "$source $key is missing or empty."
  elif is_placeholder "$value"; then
    add_fail "$source $key still looks like a placeholder."
  elif printf '%s' "$value" | grep -Eq '^[A-Za-z][A-Za-z0-9+.-]*://|/|\?|#'; then
    add_fail "$source $key must be a bind host such as 0.0.0.0 or 127.0.0.1, not a URL."
  else
    add_pass "$source $key is a bind host."
  fi
}

require_url() {
  source=$1
  key=$2
  value=$3
  scheme=$4
  if [ "$value" = "" ]; then
    add_fail "$source $key is missing or empty."
  elif is_placeholder "$value"; then
    add_fail "$source $key still looks like a placeholder."
  elif ! printf '%s' "$value" | grep -Eq "^$scheme://"; then
    add_fail "$source $key must use $scheme://."
  elif printf '%s' "$value" | grep -Eq "localhost|127\.0\.0\.1|0\.0\.0\.0"; then
    add_fail "$source $key must not point to a local-only host on test/prod."
  else
    add_pass "$source $key uses $scheme and is not local."
  fi
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
    *) host=$(printf '%s' "$candidate" | cut -d/ -f1 | cut -d: -f1) ;;
  esac
  printf '%s\n' "$host" | tr 'A-Z' 'a-z' | sed 's/\.$//'
}

check_production_domain() {
  source=$1
  key=$2
  value=$3
  [ "$PROFILE" = "test" ] || return 0
  host=$(production_domain_host "$value" || true)
  if [ "$host" = "" ]; then
    add_pass "$source $key does not point to the production domain."
  elif [ "$ALLOW_PRODUCTION_DOMAIN_FOR_TEST" = "1" ]; then
    add_pass "$source $key points to production domain $host, allowed by explicit override."
  else
    add_fail "$source $key points to production domain $host. Use a test domain, or set ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1 only for an intentional exception."
  fi
}

require_relative_or_https_url() {
  source=$1
  key=$2
  value=$3
  platform_host=$4
  if [ "$value" = "" ]; then
    add_fail "$source $key is missing or empty."
    return
  fi
  case "$value" in
    /*) add_pass "$source $key is root-relative."; return ;;
  esac
  if is_placeholder "$value"; then
    add_fail "$source $key still looks like a placeholder."
    return
  fi
  case "$value" in
    https://*) : ;;
    *) add_fail "$source $key must be root-relative or use https:// on test/prod."; return ;;
  esac
  host=$(env_host "$value")
  if [ "$host" = "" ]; then
    add_fail "$source $key must be root-relative or an absolute https URL."
  elif [ "$platform_host" != "" ] && [ "$host" != "$platform_host" ]; then
    add_fail "$source $key host must match STORE_PLATFORM_DOMAIN ($platform_host)."
  else
    add_pass "$source $key is an allowed URL."
  fi
}

PHP_ENV_PATH=$(resolve_path "$PHP_ENV")
IM_ENV_PATH=$(resolve_path "$IM_ENV")
RESOLVED_DELIVERY=$(resolve_path "$DELIVERY_ARCHIVE_PATH")
RESOLVED_SQL_DUMP=$(resolve_path "$SQL_DUMP_PATH")
RESOLVED_SQL_CHECKSUM=$(resolve_path "$SQL_CHECKSUM_PATH")
RESOLVED_BACKUP_ARTIFACT=$(resolve_path "$BACKUP_ARTIFACT_PATH")
RESOLVED_BACKUP_CHECKSUM=$(resolve_path "$BACKUP_CHECKSUM_PATH")

if [ ! -s "$PHP_ENV_PATH" ]; then add_fail "PHP env file is missing or empty: $PHP_ENV_PATH"; else add_pass "PHP env file is readable."; fi
if [ ! -s "$IM_ENV_PATH" ]; then add_fail "Python IM env file is missing or empty: $IM_ENV_PATH"; else add_pass "Python IM env file is readable."; fi

if [ "$REQUIRE_RESTORE_INPUTS" = "1" ]; then
  RESOLVED_DELIVERY=$(require_file "Delivery archive" "$DELIVERY_ARCHIVE_PATH")
  RESOLVED_SQL_DUMP=$(require_file "SQL dump" "$SQL_DUMP_PATH")
  RESOLVED_SQL_CHECKSUM=$(require_file "SQL checksum sidecar" "$SQL_CHECKSUM_PATH")
  case "$DATABASE" in
    ""|mysql|information_schema|performance_schema|sys) add_fail "Target database must be a non-system database name, usually outer." ;;
    *) add_pass "Target database name is allowed." ;;
  esac
  if [ "$BACKUP_REFERENCE" = "" ] && [ "$BACKUP_ARTIFACT_PATH" = "" ]; then
    add_fail "BackupReference or BackupArtifactPath is required before restore apply."
  else
    add_pass "Backup reference or artifact is present."
  fi
  if [ "$BACKUP_ARTIFACT_PATH" != "" ]; then
    RESOLVED_BACKUP_ARTIFACT=$(require_file "Backup artifact" "$BACKUP_ARTIFACT_PATH")
  fi
  if [ "$BACKUP_CHECKSUM_PATH" != "" ]; then
    RESOLVED_BACKUP_CHECKSUM=$(require_file "Backup checksum sidecar" "$BACKUP_CHECKSUM_PATH")
  fi
  if [ "$RESOLVED_SQL_DUMP" != "" ] && [ "$RESOLVED_SQL_CHECKSUM" != "" ]; then
    check_sha256 "SQL dump" "$RESOLVED_SQL_DUMP" "$RESOLVED_SQL_CHECKSUM" "$EXPECTED_SQL_SHA256"
  fi
  if [ "$RESOLVED_BACKUP_ARTIFACT" != "" ] && { [ "$RESOLVED_BACKUP_CHECKSUM" != "" ] || [ "$EXPECTED_BACKUP_SHA256" != "" ]; }; then
    check_sha256 "Backup artifact" "$RESOLVED_BACKUP_ARTIFACT" "$RESOLVED_BACKUP_CHECKSUM" "$EXPECTED_BACKUP_SHA256"
  fi
fi

for key in DB_DSN DB_USERNAME DB_PASSWORD DB_TABLE_PREFIX YII_ENV YII_DEBUG DEFAULT_STORE_ID DEFAULT_ROUTE STORE_PLATFORM_DOMAIN WEB_BASE_URL MALL_PLATFORM_MODE MALL_PLATFORM_OPERATOR_STORE_IDS REDIS_HOST REDIS_PORT REDIS_DATABASE UPLOAD_HTTP_PREFIX CHAT_UPLOAD_URL IM_WEBSOCKET_URL; do
  require_key PHP "$PHP_ENV_PATH" "$key"
done
for key in QPAY_AUTH_BASIC QPAY_INVOICE_CODE QPAY_AUTH_URL QPAY_INVOICE_URL QPAY_CALLBACK_BASE LIANLIAN_SANDBOX LIANLIAN_MERCHANT_ID LIANLIAN_PUBLIC_KEY LIANLIAN_PRIVATE_KEY LIANLIAN_CALLBACK_BASE; do
  require_key PHP "$PHP_ENV_PATH" "$key"
done
require_secret PHP "$PHP_ENV_PATH" IM_AUTH_SECRET 32
require_secret PHP "$PHP_ENV_PATH" QPAY_CALLBACK_HMAC_SECRET 32
require_secret PHP "$PHP_ENV_PATH" LIANLIAN_CALLBACK_HMAC_SECRET 32
require_positive_int PHP "$PHP_ENV_PATH" QPAY_CALLBACK_MAX_AGE_SECONDS
require_positive_int PHP "$PHP_ENV_PATH" LIANLIAN_CALLBACK_MAX_AGE_SECONDS

for key in DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_DATABASE DB_TABLE_PREFIX IM_HOST IM_PORT IM_CHAT_TABLE IM_MAX_TEXT_MESSAGE_LENGTH IM_MAX_IMAGE_MESSAGE_LENGTH; do
  require_key "Python IM" "$IM_ENV_PATH" "$key"
done
require_secret "Python IM" "$IM_ENV_PATH" IM_AUTH_SECRET 32
require_positive_int "Python IM" "$IM_ENV_PATH" IM_PORT 65535
require_positive_int "Python IM" "$IM_ENV_PATH" IM_MAX_TEXT_MESSAGE_LENGTH 10000
require_positive_int "Python IM" "$IM_ENV_PATH" IM_MAX_IMAGE_MESSAGE_LENGTH 8192

yii_debug=$(env_value "$PHP_ENV_PATH" YII_DEBUG | tr 'A-Z' 'a-z')
case "$yii_debug" in false|0|no) add_pass "PHP YII_DEBUG is disabled." ;; *) add_fail "PHP YII_DEBUG must be false/0/no on $PROFILE." ;; esac
yii_env=$(env_value "$PHP_ENV_PATH" YII_ENV | tr 'A-Z' 'a-z')
[ "$yii_env" = "$PROFILE" ] && add_pass "PHP YII_ENV matches $PROFILE." || add_fail "PHP YII_ENV must be $PROFILE."

[ "$(env_value "$PHP_ENV_PATH" DEFAULT_ROUTE)" = "mall" ] && add_pass "PHP DEFAULT_ROUTE is mall." || add_fail "PHP DEFAULT_ROUTE must be mall."
if [ "$PROFILE" = "test" ] && [ "$(env_value "$PHP_ENV_PATH" LIANLIAN_SANDBOX)" != "true" ]; then
  add_fail "PHP LIANLIAN_SANDBOX must be true for test profile."
elif [ "$PROFILE" = "prod" ] && [ "$(env_value "$PHP_ENV_PATH" LIANLIAN_SANDBOX)" = "true" ]; then
  add_fail "PHP LIANLIAN_SANDBOX must be false for prod profile."
else
  add_pass "PHP LIANLIAN_SANDBOX is compatible with $PROFILE profile."
fi
require_bind_host "Python IM" "$IM_ENV_PATH" IM_HOST

require_url PHP WEB_BASE_URL "$(env_value "$PHP_ENV_PATH" WEB_BASE_URL)" https
require_url PHP IM_WEBSOCKET_URL "$(env_value "$PHP_ENV_PATH" IM_WEBSOCKET_URL)" wss
require_url PHP QPAY_AUTH_URL "$(env_value "$PHP_ENV_PATH" QPAY_AUTH_URL)" https
require_url PHP QPAY_INVOICE_URL "$(env_value "$PHP_ENV_PATH" QPAY_INVOICE_URL)" https
require_url PHP QPAY_CALLBACK_BASE "$(env_value "$PHP_ENV_PATH" QPAY_CALLBACK_BASE)" https
require_url PHP LIANLIAN_CALLBACK_BASE "$(env_value "$PHP_ENV_PATH" LIANLIAN_CALLBACK_BASE)" https
require_url Argument BaseUrl "$BASE_URL" https
require_url Argument ImUrl "$IM_URL" wss

check_production_domain PHP STORE_PLATFORM_DOMAIN "$(env_value "$PHP_ENV_PATH" STORE_PLATFORM_DOMAIN)"
check_production_domain PHP WEB_BASE_URL "$(env_value "$PHP_ENV_PATH" WEB_BASE_URL)"
check_production_domain PHP IM_WEBSOCKET_URL "$(env_value "$PHP_ENV_PATH" IM_WEBSOCKET_URL)"
check_production_domain PHP QPAY_CALLBACK_BASE "$(env_value "$PHP_ENV_PATH" QPAY_CALLBACK_BASE)"
check_production_domain PHP LIANLIAN_CALLBACK_BASE "$(env_value "$PHP_ENV_PATH" LIANLIAN_CALLBACK_BASE)"
check_production_domain Argument BaseUrl "$BASE_URL"
check_production_domain Argument ImUrl "$IM_URL"

platform_host=$(env_host "$(env_value "$PHP_ENV_PATH" STORE_PLATFORM_DOMAIN)")
web_base_host=$(env_host "$(env_value "$PHP_ENV_PATH" WEB_BASE_URL)")
if [ "$platform_host" != "" ] && [ "$web_base_host" != "" ] && [ "$platform_host" = "$web_base_host" ]; then
  add_pass "PHP WEB_BASE_URL host matches STORE_PLATFORM_DOMAIN."
else
  add_fail "PHP WEB_BASE_URL host must match STORE_PLATFORM_DOMAIN."
fi
php_im_host=$(env_host "$(env_value "$PHP_ENV_PATH" IM_WEBSOCKET_URL)")
if [ "$platform_host" != "" ] && [ "$php_im_host" != "" ] && [ "$php_im_host" = "$platform_host" ]; then
  add_pass "PHP IM_WEBSOCKET_URL host matches STORE_PLATFORM_DOMAIN."
else
  add_fail "PHP IM_WEBSOCKET_URL host must match STORE_PLATFORM_DOMAIN."
fi
argument_base_host=$(env_host "$BASE_URL")
if [ "$platform_host" != "" ] && [ "$argument_base_host" != "" ] && [ "$argument_base_host" = "$platform_host" ]; then
  add_pass "Argument BaseUrl host matches STORE_PLATFORM_DOMAIN."
else
  add_fail "Argument BaseUrl host must match STORE_PLATFORM_DOMAIN."
fi
argument_im_host=$(env_host "$IM_URL")
if [ "$platform_host" != "" ] && [ "$argument_im_host" != "" ] && [ "$argument_im_host" = "$platform_host" ]; then
  add_pass "Argument ImUrl host matches STORE_PLATFORM_DOMAIN."
else
  add_fail "Argument ImUrl host must match STORE_PLATFORM_DOMAIN."
fi
for callback_key in QPAY_CALLBACK_BASE LIANLIAN_CALLBACK_BASE; do
  callback_host=$(env_host "$(env_value "$PHP_ENV_PATH" "$callback_key")")
  if [ "$platform_host" != "" ] && [ "$callback_host" != "" ] && [ "$callback_host" = "$platform_host" ]; then
    add_pass "PHP $callback_key host matches STORE_PLATFORM_DOMAIN."
  else
    add_fail "PHP $callback_key host must match STORE_PLATFORM_DOMAIN."
  fi
done
require_relative_or_https_url PHP CHAT_UPLOAD_URL "$(env_value "$PHP_ENV_PATH" CHAT_UPLOAD_URL)" "$platform_host"
require_relative_or_https_url PHP UPLOAD_HTTP_PREFIX "$(env_value "$PHP_ENV_PATH" UPLOAD_HTTP_PREFIX)" "$platform_host"

php_secret=$(env_value "$PHP_ENV_PATH" IM_AUTH_SECRET)
im_secret=$(env_value "$IM_ENV_PATH" IM_AUTH_SECRET)
if [ "$php_secret" != "" ] && [ "$im_secret" != "" ]; then
  [ "$php_secret" = "$im_secret" ] && add_pass "PHP and Python IM_AUTH_SECRET match." || add_fail "PHP and Python IM_AUTH_SECRET must match."
fi

expected_chat_table="$(env_value "$PHP_ENV_PATH" DB_TABLE_PREFIX)chat"
im_chat_table=$(env_value "$IM_ENV_PATH" IM_CHAT_TABLE)
if [ "$im_chat_table" != "" ] && [ "$(env_value "$PHP_ENV_PATH" DB_TABLE_PREFIX)" != "" ]; then
  [ "$im_chat_table" = "$expected_chat_table" ] && add_pass "Python IM_CHAT_TABLE matches PHP DB_TABLE_PREFIX + chat." || add_fail "Python IM_CHAT_TABLE must equal PHP DB_TABLE_PREFIX + chat ($expected_chat_table)."
fi

php_dsn=$(env_value "$PHP_ENV_PATH" DB_DSN)
dsn_host=$(printf '%s' "$php_dsn" | sed -n 's/.*host=\([^;]*\).*/\1/p')
dsn_port=$(printf '%s' "$php_dsn" | sed -n 's/.*port=\([^;]*\).*/\1/p')
dsn_db=$(printf '%s' "$php_dsn" | sed -n 's/.*dbname=\([^;]*\).*/\1/p')
[ "$dsn_port" = "" ] && dsn_port=3306
if ! printf '%s' "$php_dsn" | grep -q '^mysql:'; then
  add_fail "PHP DB_DSN must be a mysql DSN."
else
  [ "$dsn_host" != "" ] && [ "$(env_value "$IM_ENV_PATH" DB_HOST)" != "" ] && [ "$dsn_host" != "$(env_value "$IM_ENV_PATH" DB_HOST)" ] && add_fail "PHP and Python IM database host differ."
  [ "$(env_value "$IM_ENV_PATH" DB_PORT)" != "" ] && [ "$dsn_port" != "$(env_value "$IM_ENV_PATH" DB_PORT)" ] && add_fail "PHP and Python IM database port differ."
  [ "$dsn_db" != "" ] && [ "$(env_value "$IM_ENV_PATH" DB_DATABASE)" != "" ] && [ "$dsn_db" != "$(env_value "$IM_ENV_PATH" DB_DATABASE)" ] && add_fail "PHP and Python IM database name differ."
  [ "$(env_value "$PHP_ENV_PATH" DB_USERNAME)" != "" ] && [ "$(env_value "$IM_ENV_PATH" DB_USERNAME)" != "" ] && [ "$(env_value "$PHP_ENV_PATH" DB_USERNAME)" != "$(env_value "$IM_ENV_PATH" DB_USERNAME)" ] && add_fail "PHP and Python IM database username differ."
fi

result=PASS
[ "$failures" -gt 0 ] && result=FAIL
if [ "$OUTPUT_PATH" = "" ]; then
  OUTPUT_PATH="runtime/handover/mongoyia-test-server-input-gate-$(date +%Y%m%d-%H%M%S).md"
fi
OUTPUT_FULL=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUTPUT_FULL")"
{
  echo "# Mongoyia Test Server Input Gate"
  echo ""
  echo "- Result: $result"
  echo "- Failures: $failures"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Profile: $PROFILE"
  echo "- Allow production domain override: $ALLOW_PRODUCTION_DOMAIN_FOR_TEST"
  echo "- PHP env: $PHP_ENV_PATH"
  echo "- Python IM env: $IM_ENV_PATH"
  echo "- Base URL: $BASE_URL"
  echo "- IM URL: $IM_URL"
  echo "- Require restore inputs: $REQUIRE_RESTORE_INPUTS"
  echo "- Delivery archive: $RESOLVED_DELIVERY"
  echo "- SQL dump: $RESOLVED_SQL_DUMP"
  echo "- SQL checksum: $RESOLVED_SQL_CHECKSUM"
  echo "- Database: $DATABASE"
  echo "- Backup artifact: $RESOLVED_BACKUP_ARTIFACT"
  echo "- Backup reference: $BACKUP_REFERENCE"
  echo ""
  echo "Secrets are not printed in this report. This gate is intended to run before a real test-server restore with Apply enabled."
  echo ""
  echo "## Checks"
  echo ""
  cat "$CHECKS_FILE"
} > "$OUTPUT_FULL"

echo "Test-server input gate: $result"
echo "Failures: $failures"
echo "Report: $OUTPUT_FULL"
[ "$failures" -eq 0 ]
