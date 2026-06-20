#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

BASE_URL=${BASE_URL:-http://127.0.0.1:8089}
PROFILE=${PROFILE:-local}
STRICT=${STRICT:-0}
IM_URL=${IM_URL:-ws://127.0.0.1:8767}
PHP_BIN=${PHP_BIN:-php}
PYTHON_BIN=${PYTHON_BIN:-python}
TESTER=${TESTER:-TBD}
NOTES=${NOTES:-TBD}
PLATFORM_USERNAME=${PLATFORM_USERNAME:-codex_platform_backend_test_5}
PLATFORM_PASSWORD=${PLATFORM_PASSWORD:-CodexTest123}
SELLER_USERNAME=${SELLER_USERNAME:-zhishichanquan}
SELLER_PASSWORD=${SELLER_PASSWORD:-123456}
PLATFORM_STORE_ID=${PLATFORM_STORE_ID:-5}
PAYMENT_USER_ID=${PAYMENT_USER_ID:-71}
PRODUCT_IDS=${PRODUCT_IDS:-90,102}
PAYMENT_PRODUCT_IDS=${PAYMENT_PRODUCT_IDS:-90,102}
IM_MERCHANT_UID=${IM_MERCHANT_UID:-37}
IM_PRODUCT_ID=${IM_PRODUCT_ID:-102}
IM_STORE_ID=${IM_STORE_ID:-9}

STAMP=$(date +%Y%m%d-%H%M%S)
REPORT_PATH="runtime/acceptance/mongoyia-acceptance-$STAMP.md"
SIGNOFF_PATH="runtime/acceptance/mongoyia-signoff-$STAMP.md"
RISK_PATH="runtime/acceptance/mongoyia-risk-register-$STAMP.md"
DELIVERY_INDEX_PATH="runtime/acceptance/mongoyia-delivery-index-$STAMP.md"

cd "$ROOT"

run_php() {
  echo "$PHP_BIN $*"
  "$PHP_BIN" "$@"
}

echo "Running Mongoyia final handover from $ROOT"

run_php yii mongoyia-acceptance/run \
  "--baseUrl=$BASE_URL" \
  "--profile=$PROFILE" \
  "--strict=$STRICT" \
  "--imUrl=$IM_URL" \
  "--pythonBin=$PYTHON_BIN" \
  "--platformUsername=$PLATFORM_USERNAME" \
  "--platformPassword=$PLATFORM_PASSWORD" \
  "--sellerUsername=$SELLER_USERNAME" \
  "--sellerPassword=$SELLER_PASSWORD" \
  "--platformStoreId=$PLATFORM_STORE_ID" \
  "--paymentUserId=$PAYMENT_USER_ID" \
  "--productIds=$PRODUCT_IDS" \
  "--paymentProductIds=$PAYMENT_PRODUCT_IDS" \
  "--imMerchantUid=$IM_MERCHANT_UID" \
  "--imProductId=$IM_PRODUCT_ID" \
  "--imStoreId=$IM_STORE_ID" \
  "--cleanupAfterRun=1" \
  "--reportPath=$REPORT_PATH" \
  "--interactive=0"

run_php yii mongoyia-signoff/run "--reportPath=$REPORT_PATH" "--outputPath=$SIGNOFF_PATH" "--tester=$TESTER" "--notes=$NOTES" "--interactive=0"
run_php yii mongoyia-risk-register/run "--reportPath=$REPORT_PATH" "--outputPath=$RISK_PATH" "--interactive=0"
run_php yii mongoyia-delivery-index/run "--acceptancePath=$REPORT_PATH" "--signoffPath=$SIGNOFF_PATH" "--riskPath=$RISK_PATH" "--outputPath=$DELIVERY_INDEX_PATH" "--interactive=0"
run_php yii mongoyia-test-cleanup/run "--failOnPending=1" "--interactive=0"

echo ""
echo "Final handover files:"
echo "- $REPORT_PATH"
echo "- $SIGNOFF_PATH"
echo "- $RISK_PATH"
echo "- $DELIVERY_INDEX_PATH"
