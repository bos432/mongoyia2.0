#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

PHP_BIN=${PHP_BIN:-php}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
SKIP_CONNECTIVITY=${SKIP_CONNECTIVITY:-0}

cd "$ROOT"

set -- yii deploy-check/run \
  "--profile=test" \
  "--strict=1" \
  "--phpEnv=$PHP_ENV" \
  "--imEnv=$IM_ENV" \
  "--skipConnectivity=$SKIP_CONNECTIVITY" \
  "--interactive=0"

echo "Running Mongoyia test profile preflight from $ROOT"
exec "$PHP_BIN" "$@"
