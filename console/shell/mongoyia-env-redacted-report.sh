#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
PROFILE=${PROFILE:-test}
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-env-redacted-report-$STAMP.md}

cd "$ROOT"

resolve_path() {
  path=$1
  case "$path" in
    /*) printf '%s\n' "$path" ;;
    [A-Za-z]:*) printf '%s\n' "$path" ;;
    *) printf '%s\n' "$ROOT/$path" ;;
  esac
}

env_value() {
  file=$1
  key=$2
  if [ ! -f "$file" ]; then
    printf '\n'
    return
  fi
  awk -F= -v key="$key" '
    $0 ~ "^[[:space:]]*#" { next }
    $1 == key {
      sub(/^[^=]*=/, "", $0)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", $0)
      gsub(/^"|"$/, "", $0)
      gsub(/^'\''|'\''$/, "", $0)
      print $0
      exit
    }
  ' "$file"
}

is_secret_key() {
  printf '%s' "$1" | grep -Eiq 'PASSWORD|SECRET|TOKEN|KEY|AUTH|PRIVATE|PUBLIC|BASIC|INVOICE_CODE|CALLBACK_SECRET'
}

is_placeholder() {
  value=$(printf '%s' "$1" | tr 'A-Z' 'a-z')
  [ "$value" = "" ] && return 0
  case "$value" in
    replace-with-*|*example.com*|*placeholder*|*changeme*|*change-me*) return 0 ;;
  esac
  return 1
}

state_for() {
  file=$1
  key=$2
  if [ ! -f "$file" ] || ! grep -E "^$key=" "$file" >/dev/null 2>&1; then
    printf 'MISSING\n'
    return
  fi
  value=$(env_value "$file" "$key")
  if [ "$value" = "" ]; then
    printf 'EMPTY\n'
  elif is_placeholder "$value"; then
    printf 'PLACEHOLDER\n'
  else
    printf 'SET\n'
  fi
}

is_optional_empty() {
  file=$1
  key=$2
  state=$3
  [ "$state" = "EMPTY" ] || return 1
  if [ "$key" = "GOOGLE_TRANSLATE_API_KEY" ]; then
    enabled=$(env_value "$file" FRONTEND_TRANSLATE_ENABLED | tr 'A-Z' 'a-z')
    case "$enabled" in
      ""|false|0|no) return 0 ;;
    esac
  fi
  return 1
}

table_value() {
  key=$1
  value=$2
  if is_secret_key "$key"; then
    if [ "$value" = "" ]; then
      printf '\n'
    else
      printf 'present (redacted)\n'
    fi
    return
  fi
  printf '%s\n' "$value" | sed 's/|/\\|/g' | awk '{ if (length($0) > 120) print substr($0,1,117) "..."; else print }'
}

PHP_ENV_PATH=$(resolve_path "$PHP_ENV")
IM_ENV_PATH=$(resolve_path "$IM_ENV")
OUTPUT_FULL=$(resolve_path "$OUTPUT_PATH")
mkdir -p "$(dirname "$OUTPUT_FULL")"

PHP_KEYS="DB_DSN DB_USERNAME DB_PASSWORD DB_TABLE_PREFIX YII_DEBUG YII_ENV DEFAULT_STORE_ID DEFAULT_ROUTE STORE_PLATFORM_DOMAIN WEB_BASE_URL MALL_PLATFORM_MODE MALL_PLATFORM_OPERATOR_STORE_IDS REDIS_HOST REDIS_PORT REDIS_DATABASE UPLOAD_HTTP_PREFIX CHAT_UPLOAD_URL IM_WEBSOCKET_URL IM_AUTH_SECRET IM_AUTH_TOKEN_TTL QPAY_AUTH_BASIC QPAY_INVOICE_CODE QPAY_AUTH_URL QPAY_INVOICE_URL QPAY_CALLBACK_BASE QPAY_CALLBACK_HMAC_SECRET LIANLIAN_SANDBOX LIANLIAN_MERCHANT_ID LIANLIAN_PUBLIC_KEY LIANLIAN_PRIVATE_KEY LIANLIAN_CALLBACK_BASE LIANLIAN_CALLBACK_HMAC_SECRET GOOGLE_TRANSLATE_API_KEY GOOGLE_TRANSLATE_PROXY FRONTEND_TRANSLATE_ENABLED MALL_TRANSLATE_TARGETS"
IM_KEYS="DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_DATABASE DB_TABLE_PREFIX IM_CHAT_TABLE IM_HOST IM_PORT IM_AUTH_SECRET IM_MAX_TEXT_MESSAGE_LENGTH IM_MAX_IMAGE_MESSAGE_LENGTH"

TMP_ROWS="$OUTPUT_FULL.rows.tmp"
TMP_CHECKS="$OUTPUT_FULL.checks.tmp"
: > "$TMP_ROWS"
: > "$TMP_CHECKS"
warnings=0

add_rows() {
  source=$1
  file=$2
  keys=$3
  for key in $keys; do
    state=$(state_for "$file" "$key")
    warning_state=$state
    if is_optional_empty "$file" "$key" "$state"; then
      state=OPTIONAL_EMPTY
      warning_state=SET
    fi
    value=$(env_value "$file" "$key")
    shown=$(table_value "$key" "$value")
    printf '| %s | `%s` | %s | %s |\n' "$source" "$key" "$state" "$shown" >> "$TMP_ROWS"
    if [ "$warning_state" != "SET" ]; then
      warnings=$((warnings + 1))
    fi
  done
}

add_rows PHP "$PHP_ENV_PATH" "$PHP_KEYS"
add_rows "Python IM" "$IM_ENV_PATH" "$IM_KEYS"

if [ ! -s "$PHP_ENV_PATH" ]; then
  printf '%s\n' "- WARN PHP env file missing or empty: \`$PHP_ENV_PATH\`" >> "$TMP_CHECKS"
  warnings=$((warnings + 1))
fi
if [ ! -s "$IM_ENV_PATH" ]; then
  printf '%s\n' "- WARN Python IM env file missing or empty: \`$IM_ENV_PATH\`" >> "$TMP_CHECKS"
  warnings=$((warnings + 1))
fi

php_secret=$(env_value "$PHP_ENV_PATH" IM_AUTH_SECRET)
im_secret=$(env_value "$IM_ENV_PATH" IM_AUTH_SECRET)
if [ "$php_secret" != "" ] && [ "$im_secret" != "" ]; then
  if [ "$php_secret" = "$im_secret" ]; then
    printf '%s\n' "- PASS PHP and Python IM auth secrets match." >> "$TMP_CHECKS"
  else
    printf '%s\n' "- WARN PHP and Python IM auth secrets do not match." >> "$TMP_CHECKS"
    warnings=$((warnings + 1))
  fi
fi

if [ "$PROFILE" = "test" ] || [ "$PROFILE" = "prod" ]; then
  for key in STORE_PLATFORM_DOMAIN WEB_BASE_URL IM_WEBSOCKET_URL QPAY_CALLBACK_BASE LIANLIAN_CALLBACK_BASE; do
    value=$(env_value "$PHP_ENV_PATH" "$key")
    case "$value" in
      *example.com*)
        printf '%s\n' "- WARN \`$key\` still uses example.com." >> "$TMP_CHECKS"
        warnings=$((warnings + 1))
        ;;
    esac
  done
fi

result=PASS
[ "$warnings" -gt 0 ] && result=WARN

cat > "$OUTPUT_FULL" <<EOF
# Mongoyia Environment Redacted Report

- Result: $result
- Warnings: $warnings
- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Profile: $PROFILE
- PHP env: $PHP_ENV_PATH
- Python IM env: $IM_ENV_PATH

This report is safe to share for handover review. Secrets, passwords, tokens, private keys, public keys, auth values, and provider credentials are redacted.

## Key Status

| Source | Key | State | Value |
|---|---|---:|---|
EOF

cat "$TMP_ROWS" >> "$OUTPUT_FULL"
cat >> "$OUTPUT_FULL" <<EOF

## Cross Checks

EOF
cat "$TMP_CHECKS" >> "$OUTPUT_FULL"
rm -f "$TMP_ROWS" "$TMP_CHECKS"

echo "Environment redacted report: $OUTPUT_FULL"
echo "Result: $result ($warnings warning(s))"
