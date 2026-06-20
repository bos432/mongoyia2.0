#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
cd "$ROOT"

BASE_URL=${BASE_URL:-}
IM_URL=${IM_URL:-}
OUTPUT_PATH=${OUTPUT_PATH:-}
REQUESTS_PER_PATH=${REQUESTS_PER_PATH:-3}
WARN_MS=${WARN_MS:-2000}
FAIL_MS=${FAIL_MS:-5000}
TIMEOUT_SEC=${TIMEOUT_SEC:-15}
PATHS=${PATHS:-"/ /product/90?lang=en /product/102?lang=mn /mall/cart/index?lang=en"}
PYTHON=${PYTHON:-python}
IM_ROOT=${IM_ROOT:-../../im后端/im后端}
SKIP_IM=${SKIP_IM:-0}

if [ "$BASE_URL" = "" ]; then
  echo "ERROR BASE_URL is required, for example BASE_URL=https://test.example.com sh console/shell/mongoyia-production-load-smoke.sh" >&2
  exit 1
fi
case "$BASE_URL" in
  http://*|https://*) ;;
  *) echo "ERROR BASE_URL must start with http:// or https://" >&2; exit 1 ;;
esac
case "$REQUESTS_PER_PATH" in
  ''|*[!0-9]*) echo "ERROR REQUESTS_PER_PATH must be a positive integer" >&2; exit 1 ;;
esac
[ "$REQUESTS_PER_PATH" -gt 0 ] || { echo "ERROR REQUESTS_PER_PATH must be greater than 0" >&2; exit 1; }

if [ "$OUTPUT_PATH" = "" ]; then
  stamp=$(date '+%Y%m%d-%H%M%S')
  OUTPUT_PATH="runtime/handover/mongoyia-production-load-smoke-$stamp.md"
fi
mkdir -p "$(dirname "$OUTPUT_PATH")"
tmp_rows=$(mktemp)
trap 'rm -f "$tmp_rows"' EXIT

failures=0
warnings=0
count=0
sum=0
max=0

add_result() {
  status=$1
  name=$2
  detail=$3
  printf '| %s | %s | %s |\n' "$status" "$name" "$detail" >> "$tmp_rows"
  [ "$status" = "FAIL" ] && failures=$((failures + 1))
  [ "$status" = "WARN" ] && warnings=$((warnings + 1))
}

join_url() {
  base=$(printf '%s' "$1" | sed 's#/*$##')
  path=$2
  case "$path" in
    /*) printf '%s%s' "$base" "$path" ;;
    *) printf '%s/%s' "$base" "$path" ;;
  esac
}

now_ms() {
  if command -v python >/dev/null 2>&1; then
    python -c "import time; print(int(time.time() * 1000))"
  else
    date '+%s000'
  fi
}

for path in $PATHS; do
  i=1
  while [ "$i" -le "$REQUESTS_PER_PATH" ]; do
    url=$(join_url "$BASE_URL" "$path")
    start=$(now_ms)
    status=$(curl -L -sS -o /dev/null -w '%{http_code}' --max-time "$TIMEOUT_SEC" "$url" 2>/tmp/mongoyia-load-smoke-curl.err || true)
    end=$(now_ms)
    elapsed=$((end - start))
    count=$((count + 1))
    sum=$((sum + elapsed))
    [ "$elapsed" -gt "$max" ] && max=$elapsed
    if [ "$status" = "" ] || [ "$status" = "000" ]; then
      err=$(cat /tmp/mongoyia-load-smoke-curl.err 2>/dev/null || true)
      add_result FAIL "$path" "request $i failed: $err"
    elif [ "$status" -lt 200 ] || [ "$status" -ge 400 ]; then
      add_result FAIL "$path" "request $i returned HTTP $status in ${elapsed}ms"
    elif [ "$elapsed" -ge "$FAIL_MS" ]; then
      add_result FAIL "$path" "request $i returned HTTP $status but exceeded fail threshold: ${elapsed}ms"
    elif [ "$elapsed" -ge "$WARN_MS" ]; then
      add_result WARN "$path" "request $i returned HTTP $status but exceeded warn threshold: ${elapsed}ms"
    else
      add_result PASS "$path" "request $i returned HTTP $status in ${elapsed}ms"
    fi
    i=$((i + 1))
  done
done

if [ "$SKIP_IM" = "1" ]; then
  add_result WARN "IM concurrency" "skipped by operator"
elif [ "$IM_URL" != "" ]; then
  if [ -f "$IM_ROOT/scripts/im-concurrency.py" ]; then
    if (cd "$IM_ROOT" && "$PYTHON" scripts/im-concurrency.py --url "$IM_URL" --connections 5 --timeout "$TIMEOUT_SEC"); then
      add_result PASS "IM concurrency" "lightweight IM concurrency regression passed for $IM_URL"
    else
      add_result FAIL "IM concurrency" "im-concurrency.py failed"
    fi
  else
    add_result WARN "IM concurrency" "script not found: $IM_ROOT/scripts/im-concurrency.py"
  fi
else
  add_result WARN "IM concurrency" "skipped because IM_URL was not provided"
fi

avg=0
[ "$count" -gt 0 ] && avg=$((sum / count))
if [ "$failures" -gt 0 ]; then
  result=FAIL
elif [ "$warnings" -gt 0 ]; then
  result=WARN
else
  result=PASS
fi

{
  echo "# Mongoyia Production Load Smoke"
  echo
  echo "- Result: $result"
  echo "- Failures: $failures"
  echo "- Warnings: $warnings"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Base URL: $BASE_URL"
  echo "- IM URL: $IM_URL"
  echo "- Requests per path: $REQUESTS_PER_PATH"
  echo "- Warn threshold: ${WARN_MS}ms"
  echo "- Fail threshold: ${FAIL_MS}ms"
  echo "- HTTP samples: $count"
  echo "- Average HTTP duration: ${avg}ms"
  echo "- Max HTTP duration: ${max}ms"
  echo
  echo "This is a non-destructive baseline smoke. It does not create orders, trigger payment callbacks, or mutate database rows."
  echo
  echo "| Status | Check | Detail |"
  echo "|---|---|---|"
  cat "$tmp_rows"
} > "$OUTPUT_PATH"

echo "Production load smoke: $result"
echo "Failures: $failures"
echo "Warnings: $warnings"
echo "Report: $OUTPUT_PATH"

[ "$failures" -eq 0 ] || exit 1
