#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover/input-gate-smoke}
SMOKE_ROOT="$ROOT/$OUTPUT_DIR"

case "$SMOKE_ROOT" in
  "$ROOT/runtime"/*) rm -rf "$SMOKE_ROOT" ;;
  *) echo "ERROR refusing to remove unexpected smoke path: $SMOKE_ROOT" >&2; exit 1 ;;
esac
mkdir -p "$SMOKE_ROOT"

secret=0123456789abcdef0123456789abcdef
php_good="$SMOKE_ROOT/php-good.env"
im_good="$SMOKE_ROOT/im-good.env"
php_bad="$SMOKE_ROOT/php-bad.env"
im_bad="$SMOKE_ROOT/im-bad.env"
good_report="$SMOKE_ROOT/good.md"
bad_report="$SMOKE_ROOT/bad.md"

cat > "$php_good" <<EOF
DB_DSN=mysql:host=10.0.0.10;port=3306;dbname=outer
DB_USERNAME=outer_user
DB_PASSWORD=outer_password
DB_TABLE_PREFIX=fb_
YII_ENV=test
YII_DEBUG=false
DEFAULT_STORE_ID=5
DEFAULT_ROUTE=mall
STORE_PLATFORM_DOMAIN=test.mongoyia.local
WEB_BASE_URL=https://test.mongoyia.local
MALL_PLATFORM_MODE=1
MALL_PLATFORM_OPERATOR_STORE_IDS=5
REDIS_HOST=10.0.0.11
REDIS_PORT=6379
REDIS_DATABASE=0
UPLOAD_HTTP_PREFIX=/attachment
CHAT_UPLOAD_URL=/attachment/chat
IM_WEBSOCKET_URL=wss://test.mongoyia.local/ws
QPAY_AUTH_BASIC=qpay-basic-token
QPAY_INVOICE_CODE=qpay-invoice-code
QPAY_AUTH_URL=https://merchant.qpay.mn/v2/auth/token
QPAY_INVOICE_URL=https://merchant.qpay.mn/v2/invoice
QPAY_CALLBACK_BASE=https://test.mongoyia.local
QPAY_CALLBACK_HMAC_SECRET=$secret
QPAY_CALLBACK_MAX_AGE_SECONDS=300
LIANLIAN_SANDBOX=true
LIANLIAN_MERCHANT_ID=lianlian-merchant
LIANLIAN_PUBLIC_KEY=lianlian-public-key
LIANLIAN_PRIVATE_KEY=lianlian-private-key
LIANLIAN_CALLBACK_BASE=https://test.mongoyia.local
LIANLIAN_CALLBACK_HMAC_SECRET=$secret
LIANLIAN_CALLBACK_MAX_AGE_SECONDS=300
IM_AUTH_SECRET=$secret
EOF

cat > "$im_good" <<EOF
DB_HOST=10.0.0.10
DB_PORT=3306
DB_USERNAME=outer_user
DB_PASSWORD=outer_password
DB_DATABASE=outer
DB_TABLE_PREFIX=fb_
IM_HOST=0.0.0.0
IM_PORT=8767
IM_CHAT_TABLE=fb_chat
IM_MAX_TEXT_MESSAGE_LENGTH=5000
IM_MAX_IMAGE_MESSAGE_LENGTH=4096
IM_AUTH_SECRET=$secret
EOF

cat > "$php_bad" <<EOF
DB_DSN=mysql:host=10.0.0.10;port=3306;dbname=outer
DB_USERNAME=outer_user
DB_PASSWORD=password
DB_TABLE_PREFIX=fb_
YII_ENV=test
YII_DEBUG=true
DEFAULT_STORE_ID=5
DEFAULT_ROUTE=funpay
STORE_PLATFORM_DOMAIN=www.mongoyia.com
WEB_BASE_URL=http://127.0.0.1:8089
MALL_PLATFORM_MODE=1
MALL_PLATFORM_OPERATOR_STORE_IDS=5
REDIS_HOST=10.0.0.11
REDIS_PORT=6379
REDIS_DATABASE=0
UPLOAD_HTTP_PREFIX=http://cdn.example.com/attachment
CHAT_UPLOAD_URL=http://cdn.example.com/chat
IM_WEBSOCKET_URL=ws://127.0.0.1:8767
QPAY_AUTH_BASIC=qpay-basic-token
QPAY_INVOICE_CODE=qpay-invoice-code
QPAY_AUTH_URL=http://127.0.0.1/qpay/auth
QPAY_INVOICE_URL=http://127.0.0.1/qpay/invoice
QPAY_CALLBACK_BASE=http://127.0.0.1:8089
QPAY_CALLBACK_HMAC_SECRET=$secret
QPAY_CALLBACK_MAX_AGE_SECONDS=0
LIANLIAN_SANDBOX=false
LIANLIAN_MERCHANT_ID=lianlian-merchant
LIANLIAN_PUBLIC_KEY=lianlian-public-key
LIANLIAN_PRIVATE_KEY=lianlian-private-key
LIANLIAN_CALLBACK_BASE=http://127.0.0.1:8089
LIANLIAN_CALLBACK_HMAC_SECRET=$secret
LIANLIAN_CALLBACK_MAX_AGE_SECONDS=0
IM_AUTH_SECRET=$secret
EOF

cat > "$im_bad" <<EOF
DB_HOST=10.0.0.10
DB_PORT=3306
DB_USERNAME=outer_user
DB_PASSWORD=outer_password
DB_DATABASE=outer
DB_TABLE_PREFIX=fb_
IM_HOST=http://127.0.0.1
IM_PORT=70000
IM_CHAT_TABLE=chat
IM_MAX_TEXT_MESSAGE_LENGTH=20000
IM_MAX_IMAGE_MESSAGE_LENGTH=9000
IM_AUTH_SECRET=bad
EOF

PHP_ENV="$php_good" \
IM_ENV="$im_good" \
BASE_URL=https://test.mongoyia.local \
IM_URL=wss://test.mongoyia.local/ws \
OUTPUT_PATH="$good_report" \
PROFILE=test \
sh "$SCRIPT_DIR/mongoyia-test-server-input-gate.sh"

if PHP_ENV="$php_bad" \
  IM_ENV="$im_bad" \
  BASE_URL=http://127.0.0.1:8089 \
  IM_URL=ws://127.0.0.1:8767 \
  OUTPUT_PATH="$bad_report" \
  PROFILE=test \
  sh "$SCRIPT_DIR/mongoyia-test-server-input-gate.sh"; then
  echo "ERROR expected bad input-gate smoke to fail." >&2
  exit 1
fi

rm -rf "$SMOKE_ROOT"
echo "Mongoyia test-server input-gate smoke: PASS"
