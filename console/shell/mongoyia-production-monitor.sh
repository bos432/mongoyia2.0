#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_PATH=${OUTPUT_PATH:-runtime/handover/mongoyia-production-monitor-$STAMP.md}
PHP_ENV=${PHP_ENV:-.env}
IM_ENV=${IM_ENV:-../../im后端/im后端/.env}
DISK_WARN_PERCENT=${DISK_WARN_PERCENT:-85}
DISK_FAIL_PERCENT=${DISK_FAIL_PERCENT:-95}
SKIP_IM_PORT=${SKIP_IM_PORT:-0}

cd "$ROOT"
case "$OUTPUT_PATH" in
  /*|[A-Za-z]:*) OUT="$OUTPUT_PATH" ;;
  *) OUT="$ROOT/$OUTPUT_PATH" ;;
esac
mkdir -p "$(dirname "$OUT")"
ROWS="$OUT.rows.tmp"
: > "$ROWS"
failures=0
warnings=0

add_row() {
  area=$1 check=$2 status=$3 evidence=$4 action=$5
  printf '| %s | %s | %s | %s | %s |\n' "$area" "$check" "$status" "$evidence" "$action" >> "$ROWS"
  [ "$status" = "FAIL" ] && failures=$((failures + 1))
  [ "$status" = "WARN" ] && warnings=$((warnings + 1))
}

env_value() {
  file=$1 key=$2
  [ -f "$file" ] || return 0
  grep -E "^[[:space:]]*$key=" "$file" | tail -n 1 | sed "s/^[[:space:]]*$key=//; s/^['\"]//; s/['\"]$//"
}

if php -v >/tmp/mongoyia_php_version.$$ 2>&1; then
  add_row Runtime "PHP CLI" PASS "$(head -n 1 /tmp/mongoyia_php_version.$$)" "Keep PHP CLI available for console health and maintenance commands."
else
  add_row Runtime "PHP CLI" FAIL "php -v failed" "Install/fix PHP CLI."
fi
rm -f /tmp/mongoyia_php_version.$$

db_dsn=$(env_value "$PHP_ENV" DB_DSN || true)
db_user=$(env_value "$PHP_ENV" DB_USERNAME || true)
if [ "$db_dsn" != "" ] && [ "$db_user" != "" ]; then
  add_row Config "PHP database env present" PASS "DB_DSN and DB_USERNAME exist" "Run deploy-check for credential validation."
else
  add_row Config "PHP database env present" FAIL "Missing DB_DSN or DB_USERNAME" "Provision real .env."
fi

redis_host=$(env_value "$PHP_ENV" REDIS_HOST || true)
redis_port=$(env_value "$PHP_ENV" REDIS_PORT || true)
if [ "$redis_host" != "" ] && [ "$redis_port" != "" ] && command -v nc >/dev/null 2>&1; then
  if nc -z "$redis_host" "$redis_port" >/dev/null 2>&1; then
    add_row Connectivity "Redis port" PASS "$redis_host:$redis_port reachable" "Monitor latency and memory in production."
  else
    add_row Connectivity "Redis port" WARN "$redis_host:$redis_port not reachable from this host" "Start Redis or verify network/security group."
  fi
else
  add_row Connectivity "Redis port" WARN "REDIS_HOST/REDIS_PORT missing or nc unavailable" "Provision Redis env and install nc for port checks."
fi

if [ "$SKIP_IM_PORT" != "1" ]; then
  im_host=$(env_value "$IM_ENV" IM_HOST || true)
  im_port=$(env_value "$IM_ENV" IM_PORT || true)
  [ "$im_host" = "0.0.0.0" ] && im_host=127.0.0.1
  if [ "$im_host" != "" ] && [ "$im_port" != "" ] && command -v nc >/dev/null 2>&1; then
    if nc -z "$im_host" "$im_port" >/dev/null 2>&1; then
      add_row Connectivity "Python IM port" PASS "$im_host:$im_port reachable" "Also run IM WSS healthcheck through the real domain."
    else
      add_row Connectivity "Python IM port" WARN "$im_host:$im_port not reachable" "Start IM process or verify supervisor/systemd."
    fi
  else
    add_row Connectivity "Python IM port" WARN "IM_HOST/IM_PORT missing or nc unavailable" "Provision Python IM .env and install nc for port checks."
  fi
fi

used=$(df -P "$ROOT" | awk 'NR==2 {gsub("%", "", $5); print $5}')
if [ "$used" -ge "$DISK_FAIL_PERCENT" ]; then
  add_row Capacity "Project disk usage" FAIL "$used% used" "Free disk space before uploads/logs/backups fill the volume."
elif [ "$used" -ge "$DISK_WARN_PERCENT" ]; then
  add_row Capacity "Project disk usage" WARN "$used% used" "Plan cleanup or volume expansion."
else
  add_row Capacity "Project disk usage" PASS "$used% used" "Keep daily disk alerts enabled."
fi

for path in runtime frontend/runtime web/assets web/attachment; do
  if [ -e "$ROOT/$path" ]; then add_row Filesystem "$path" PASS exists "Keep writable by the PHP runtime user."; else add_row Filesystem "$path" WARN missing "Create before production traffic."; fi
done

if find "$ROOT/frontend/runtime/logs" "$ROOT/console/runtime/logs" -type f 2>/dev/null | head -n 1 | grep -q .; then
  add_row Logs "Recent runtime logs" PASS "log files found" "Feed PHP and IM logs into alerting."
else
  add_row Logs "Recent runtime logs" WARN "No runtime log files found" "Verify log path and rotation."
fi

if [ "$failures" -gt 0 ]; then result=FAIL
elif [ "$warnings" -gt 0 ]; then result=WARN
else result=PASS
fi

{
  echo "# Mongoyia Production Monitor"
  echo
  echo "- Result: $result"
  echo "- Failures: $failures"
  echo "- Warnings: $warnings"
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo
  echo "| Area | Check | Status | Evidence | Action |"
  echo "|---|---|---:|---|---|"
  cat "$ROWS"
} > "$OUT"
rm -f "$ROWS"

echo "Mongoyia production monitor report: $OUT"
echo "Result: $result"
[ "$failures" -eq 0 ]
