#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

PHP_BIN=${PHP_BIN:-php}
BASE_URL=${BASE_URL:-}
PROFILE=${PROFILE:-test}
STRICT=${STRICT:-1}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}
SKIP_API=${SKIP_API:-0}
PLATFORM_STORE_ID=${PLATFORM_STORE_ID:-5}
PLATFORM_USERNAME=${PLATFORM_USERNAME:-codex_platform_backend_test_5}
SELLER_USERNAME=${SELLER_USERNAME:-zhishichanquan}
PAYMENT_USER_ID=${PAYMENT_USER_ID:-71}
PRODUCT_IDS=${PRODUCT_IDS:-90,102}
IM_MERCHANT_UID=${IM_MERCHANT_UID:-37}
IM_PRODUCT_ID=${IM_PRODUCT_ID:-102}
IM_STORE_ID=${IM_STORE_ID:-9}

cd "$ROOT"

run_step() {
  name=$1
  shift
  echo ""
  echo "== $name =="
  echo "$PHP_BIN $*"
  "$PHP_BIN" "$@"
}

echo "Running Mongoyia test-server dry-run from $ROOT"
echo "Profile=$PROFILE Strict=$STRICT PhpEnv=$PHP_ENV ImEnv=$IM_ENV"

run_step "deployment configuration" yii deploy-check/run \
  "--profile=$PROFILE" \
  "--strict=$STRICT" \
  "--phpEnv=$PHP_ENV" \
  "--imEnv=$IM_ENV" \
  "--skipConnectivity=$SKIP_CONNECTIVITY" \
  "--interactive=0"

run_step "handover package check" yii mongoyia-package-check/run "--interactive=0"
run_step "security hardcode scan" yii mongoyia-security-scan/run "--strict=$STRICT" "--interactive=0"
run_step "host cleanup dry-run" yii mongoyia-host-cleanup/run "--interactive=0"
run_step "catalog cleanup dry-run" yii mongoyia-catalog-cleanup/run "--interactive=0"
run_step "data readiness" yii mongoyia-data-readiness/run \
  "--platformStoreId=$PLATFORM_STORE_ID" \
  "--platformUsername=$PLATFORM_USERNAME" \
  "--sellerUsername=$SELLER_USERNAME" \
  "--paymentUserId=$PAYMENT_USER_ID" \
  "--productIds=$PRODUCT_IDS" \
  "--imMerchantUid=$IM_MERCHANT_UID" \
  "--imProductId=$IM_PRODUCT_ID" \
  "--imStoreId=$IM_STORE_ID" \
  "--interactive=0"
run_step "catalog readiness" yii mongoyia-catalog-readiness/run "--interactive=0"
run_step "translation readiness" yii mongoyia-translation-readiness/run "--strict=$STRICT" "--productIds=$PRODUCT_IDS" "--interactive=0"
run_step "order integrity" yii mongoyia-order-integrity/run "--interactive=0"
run_step "payment audit" yii mongoyia-payment-audit/run "--interactive=0"

if [ "$SKIP_API" != "1" ] && [ "$BASE_URL" != "" ]; then
  run_step "API smoke" yii api-smoke-test/run "--baseUrl=$BASE_URL" "--interactive=0"
fi

run_step "generated test-data cleanup verification" yii mongoyia-test-cleanup/run "--failOnPending=1" "--interactive=0"

echo ""
echo "Mongoyia test-server dry-run: PASS"
