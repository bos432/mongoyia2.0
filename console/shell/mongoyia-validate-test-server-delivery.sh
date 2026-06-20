#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
ARCHIVE_PATH=${1:-${ARCHIVE_PATH:-}}
PATCH_MODE=${PATCH_MODE:-reverse}

cd "$ROOT"

if [ "$ARCHIVE_PATH" = "" ]; then
  ARCHIVE_PATH=$(find "$ROOT/runtime/handover" -maxdepth 1 -type f -name 'mongoyia-test-server-delivery-*.tar.gz' 2>/dev/null | sort | tail -n 1)
fi
if [ "$ARCHIVE_PATH" = "" ] || [ ! -f "$ARCHIVE_PATH" ]; then
  echo "ERROR test-server delivery archive is required." >&2
  exit 1
fi
if [ ! -f "$ARCHIVE_PATH.sha256" ]; then
  echo "ERROR missing delivery checksum: $ARCHIVE_PATH.sha256" >&2
  exit 1
fi

expected=$(awk '{print tolower($1)}' "$ARCHIVE_PATH.sha256")
if command -v sha256sum >/dev/null 2>&1; then
  actual=$(sha256sum "$ARCHIVE_PATH" | awk '{print tolower($1)}')
elif command -v shasum >/dev/null 2>&1; then
  actual=$(shasum -a 256 "$ARCHIVE_PATH" | awk '{print tolower($1)}')
else
  echo "ERROR sha256sum or shasum is required." >&2
  exit 1
fi
if [ "$expected" != "$actual" ]; then
  echo "ERROR delivery checksum mismatch. expected=$expected actual=$actual" >&2
  exit 1
fi

TMP="$ROOT/runtime/handover/test-server-delivery-verify-sh"
rm -rf "$TMP"
mkdir -p "$TMP"
tar -xzf "$ARCHIVE_PATH" -C "$TMP"

require_match() {
  pattern=$1
  if ! find "$TMP" -type f | sed "s#^$TMP/##" | grep -E "$pattern" >/dev/null; then
    echo "ERROR missing delivery entry matching: $pattern" >&2
    exit 1
  fi
}

require_match '^MANIFEST\.md$'
require_match '^RECEIVER_README\.md$'
require_match '^mongoyia-test-server-inputs\.md$'
require_match '^mongoyia-external-integration-inputs\.md$'
require_match '^mongoyia-mongolian-review-workflow\.md$'
require_match '^mongoyia-mongolian-review-evidence\.md$'
require_match '^mongoyia-p2-evidence-pack\.md$'
require_match '^mongoyia-payment-sandbox-evidence\.md$'
require_match '^mongoyia-im-wss-evidence\.md$'
require_match '^mongoyia-production-readiness\.md$'
require_match '^mongoyia-production-scheduled-monitoring\.md$'
require_match '^mongoyia-production-load-test-evidence\.md$'
require_match '^mongoyia-production-evidence-summary\.md$'
require_match '^mongoyia-production-go-live-gate\.md$'
require_match '^mongoyia-production-rollout-rollback\.md$'
require_match '^receiver/mongoyia-test-server-receiver\.ps1$'
require_match '^receiver/mongoyia-test-server-receiver\.sh$'
require_match '^receiver/mongoyia-test-server-input-gate\.ps1$'
require_match '^receiver/mongoyia-test-server-input-gate\.sh$'
require_match '^receiver/mongoyia-test-server-input-gate-smoke\.ps1$'
require_match '^receiver/mongoyia-test-server-input-gate-smoke\.sh$'
require_match '^receiver/mongoyia-test-server-go-no-go\.ps1$'
require_match '^receiver/mongoyia-test-server-go-no-go\.sh$'
require_match '^receiver/mongoyia-test-server-go-no-go-smoke\.ps1$'
require_match '^receiver/mongoyia-test-server-go-no-go-smoke\.sh$'
require_match '^receiver/mongoyia-test-server-restore\.ps1$'
require_match '^receiver/mongoyia-test-server-restore\.sh$'
require_match '^receiver/mongoyia-test-server-restore-plan\.ps1$'
require_match '^receiver/mongoyia-test-server-restore-plan\.sh$'
require_match '^receiver/mongoyia-sql-dump-manifest\.ps1$'
require_match '^receiver/mongoyia-sql-dump-manifest\.sh$'
require_match '^receiver/mongoyia-env-redacted-report\.ps1$'
require_match '^receiver/mongoyia-env-redacted-report\.sh$'
require_match '^receiver/mongoyia-handoff-status\.ps1$'
require_match '^receiver/mongoyia-handoff-status\.sh$'
require_match '^receiver/mongoyia-p2-readiness\.ps1$'
require_match '^receiver/mongoyia-p2-readiness\.sh$'
require_match '^receiver/mongoyia-p2-evidence-pack\.ps1$'
require_match '^receiver/mongoyia-p2-evidence-pack\.sh$'
require_match '^receiver/mongoyia-payment-sandbox-evidence\.ps1$'
require_match '^receiver/mongoyia-payment-sandbox-evidence\.sh$'
require_match '^receiver/mongoyia-im-wss-evidence\.ps1$'
require_match '^receiver/mongoyia-im-wss-evidence\.sh$'
require_match '^receiver/mongoyia-mongolian-review-evidence\.ps1$'
require_match '^receiver/mongoyia-mongolian-review-evidence\.sh$'
require_match '^receiver/mongoyia-production-backup-verify\.ps1$'
require_match '^receiver/mongoyia-production-backup-verify\.sh$'
require_match '^receiver/mongoyia-production-load-smoke\.ps1$'
require_match '^receiver/mongoyia-production-load-smoke\.sh$'
require_match '^receiver/mongoyia-production-load-test-evidence\.ps1$'
require_match '^receiver/mongoyia-production-load-test-evidence\.sh$'
require_match '^receiver/mongoyia-production-scheduled-check\.ps1$'
require_match '^receiver/mongoyia-production-scheduled-check\.sh$'
require_match '^receiver/mongoyia-production-evidence-summary\.ps1$'
require_match '^receiver/mongoyia-production-evidence-summary\.sh$'
require_match '^receiver/mongoyia-production-go-live-gate\.ps1$'
require_match '^receiver/mongoyia-production-go-live-gate\.sh$'
require_match '^archives/mongoyia-handover-.+\.zip$'
require_match '^archives/mongoyia-handover-.+\.zip\.sha256$'
require_match '^archives/mongoyia-source-handover-.+\.zip$'
require_match '^archives/mongoyia-source-handover-.+\.zip\.sha256$'
require_match '^reports/mongoyia-test-server-preflight-.+\.md$'
require_match '^reports/mongoyia-handover-verify-.+\.md$'

if find "$TMP" -type f | sed "s#^$TMP/##" | grep -E '(^|/)\.env$|(^|/)vendor/|(^|/)node_modules/|(^|/)web/attachment/|(^|/)web/assets/|\.(sql|dump|bak|7z|rar)$' >/dev/null; then
  echo "ERROR forbidden entry found in delivery archive." >&2
  exit 1
fi

if ! grep -q 'SkipApplySafetyConfirm' "$TMP/receiver/mongoyia-test-server-restore.ps1" || ! grep -q 'SKIP_RESTORE_APPLY_SAFETY' "$TMP/receiver/mongoyia-test-server-restore.ps1"; then
  echo "ERROR receiver restore PowerShell script is missing SkipApplySafety confirmation guard." >&2
  exit 1
fi
if ! grep -q 'SKIP_APPLY_SAFETY_CONFIRM' "$TMP/receiver/mongoyia-test-server-restore.sh" || ! grep -q 'SKIP_RESTORE_APPLY_SAFETY' "$TMP/receiver/mongoyia-test-server-restore.sh"; then
  echo "ERROR receiver restore shell script is missing SkipApplySafety confirmation guard." >&2
  exit 1
fi
if ! grep -q 'mongoyia-test-server-go-no-go' "$TMP/receiver/mongoyia-test-server-restore.ps1" || ! grep -q 'ExternalInputsConfirm' "$TMP/receiver/mongoyia-test-server-restore.ps1"; then
  echo "ERROR receiver restore PowerShell script is missing go/no-go apply guard." >&2
  exit 1
fi
if ! grep -q 'mongoyia-test-server-go-no-go' "$TMP/receiver/mongoyia-test-server-restore.sh" || ! grep -q 'EXTERNAL_INPUTS_CONFIRM' "$TMP/receiver/mongoyia-test-server-restore.sh"; then
  echo "ERROR receiver restore shell script is missing go/no-go apply guard." >&2
  exit 1
fi
if ! grep -q 'SKIP_RESTORE_APPLY_SAFETY' "$TMP/receiver/mongoyia-test-server-restore-plan.ps1" || ! grep -q 'SKIP_APPLY_SAFETY_CONFIRM' "$TMP/receiver/mongoyia-test-server-restore-plan.ps1"; then
  echo "ERROR receiver restore-plan PowerShell script is missing emergency apply-safety bypass notes." >&2
  exit 1
fi
if ! grep -q 'SKIP_RESTORE_APPLY_SAFETY' "$TMP/receiver/mongoyia-test-server-restore-plan.sh" || ! grep -q 'SKIP_APPLY_SAFETY_CONFIRM' "$TMP/receiver/mongoyia-test-server-restore-plan.sh"; then
  echo "ERROR receiver restore-plan shell script is missing emergency apply-safety bypass notes." >&2
  exit 1
fi
if ! grep -q 'ExternalInputsConfirm' "$TMP/receiver/mongoyia-test-server-restore-plan.ps1" || ! grep -q 'EXTERNAL_TEST_INPUTS_CONFIRMED' "$TMP/receiver/mongoyia-test-server-restore-plan.ps1"; then
  echo "ERROR receiver restore-plan PowerShell script is missing external-input go/no-go confirmation in apply commands." >&2
  exit 1
fi
if ! grep -q 'EXTERNAL_INPUTS_CONFIRM' "$TMP/receiver/mongoyia-test-server-restore-plan.sh" || ! grep -q 'EXTERNAL_TEST_INPUTS_CONFIRMED' "$TMP/receiver/mongoyia-test-server-restore-plan.sh"; then
  echo "ERROR receiver restore-plan shell script is missing external-input go/no-go confirmation in apply commands." >&2
  exit 1
fi
if ! grep -q 'SKIP_RESTORE_APPLY_SAFETY' "$TMP/RECEIVER_README.md" || ! grep -q 'Do not skip the input gate' "$TMP/RECEIVER_README.md" || ! grep -q 'mongoyia-test-server-go-no-go' "$TMP/RECEIVER_README.md" || ! grep -q 'NO-GO' "$TMP/RECEIVER_README.md"; then
  echo "ERROR receiver README is missing input-gate/apply-safety bypass guidance." >&2
  exit 1
fi
if ! grep -q 'EXTERNAL_TEST_INPUTS_CONFIRMED' "$TMP/RECEIVER_README.md" || ! grep -q 'Restore apply runs it again automatically before database restore' "$TMP/RECEIVER_README.md"; then
  echo "ERROR receiver README is missing external-input or automatic go/no-go apply guidance." >&2
  exit 1
fi
if ! grep -q 'EXTERNAL_TEST_INPUTS_CONFIRMED' "$TMP/MANIFEST.md" || ! grep -q 'Restore Apply runs input-gate and go/no-go again before database restore' "$TMP/MANIFEST.md"; then
  echo "ERROR delivery manifest is missing automatic go/no-go apply guidance." >&2
  exit 1
fi
if ! grep -q 'mongoyia-test-server-go-no-go-smoke' "$TMP/MANIFEST.md"; then
  echo "ERROR delivery manifest is missing go/no-go smoke guidance." >&2
  exit 1
fi
for token in DEFAULT_ROUTE STORE_PLATFORM_DOMAIN IM_WEBSOCKET_URL QPAY_AUTH_URL QPAY_INVOICE_URL QPAY_CALLBACK_BASE LIANLIAN_SANDBOX IM_PORT IM_MAX_TEXT_MESSAGE_LENGTH IM_MAX_IMAGE_MESSAGE_LENGTH IM_CHAT_TABLE CHAT_UPLOAD_URL UPLOAD_HTTP_PREFIX; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-input-gate.ps1"; then
    echo "ERROR receiver input-gate PowerShell script is missing required check token: $token" >&2
    exit 1
  fi
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-input-gate.sh"; then
    echo "ERROR receiver input-gate shell script is missing required check token: $token" >&2
    exit 1
  fi
done

for token in 'missing external confirmation go/no-go smoke to block' 'wrong external confirmation go/no-go smoke to block' 'confirmed external inputs go/no-go smoke to pass external gate'; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-go-no-go-smoke.ps1"; then
    echo "ERROR receiver go/no-go smoke PowerShell script is missing required check token: $token" >&2
    exit 1
  fi
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-go-no-go-smoke.sh"; then
    echo "ERROR receiver go/no-go smoke shell script is missing required check token: $token" >&2
    exit 1
  fi
done

for token in 'Expected good input-gate smoke to pass' 'Expected bad input-gate smoke to fail' 'php-good.env' 'php-bad.env'; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-input-gate-smoke.ps1"; then
    echo "ERROR receiver input-gate smoke PowerShell script is missing required check token: $token" >&2
    exit 1
  fi
done
for token in 'expected bad input-gate smoke to fail' 'php-good.env' 'php-bad.env'; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-input-gate-smoke.sh"; then
    echo "ERROR receiver input-gate smoke shell script is missing required check token: $token" >&2
    exit 1
  fi
done

for token in 'NO-GO' 'GO-WITH-WARNINGS' 'Real input gate passed' 'External test-server inputs supplied'; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-go-no-go.ps1"; then
    echo "ERROR receiver go/no-go PowerShell script is missing required check token: $token" >&2
    exit 1
  fi
  if ! grep -q "$token" "$TMP/receiver/mongoyia-test-server-go-no-go.sh"; then
    echo "ERROR receiver go/no-go shell script is missing required check token: $token" >&2
    exit 1
  fi
done

for token in QPAY_AUTH_URL QPAY_INVOICE_URL RequireExternalInputs 'P2 Readiness Report'; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-p2-readiness.ps1"; then
    echo "ERROR receiver P2 readiness PowerShell script is missing required check token: $token" >&2
    exit 1
  fi
done
for token in QPAY_AUTH_URL QPAY_INVOICE_URL REQUIRE_EXTERNAL_INPUTS 'P2 Readiness Report'; do
  if ! grep -q "$token" "$TMP/receiver/mongoyia-p2-readiness.sh"; then
    echo "ERROR receiver P2 readiness shell script is missing required check token: $token" >&2
    exit 1
  fi
done

handover=$(find "$TMP/archives" -maxdepth 1 -type f -name 'mongoyia-handover-*.zip' | head -n 1)
source_handover=$(find "$TMP/archives" -maxdepth 1 -type f -name 'mongoyia-source-handover-*.zip' | head -n 1)
preflight=$(find "$TMP/reports" -maxdepth 1 -type f -name 'mongoyia-test-server-preflight-*.md' | head -n 1)
handover_verify=$(find "$TMP/reports" -maxdepth 1 -type f -name 'mongoyia-handover-verify-*.md' | head -n 1)

env ARCHIVE_PATH="$handover" sh "$SCRIPT_DIR/mongoyia-validate-handover-archive.sh"
env PATCH_MODE="$PATCH_MODE" sh "$SCRIPT_DIR/mongoyia-validate-source-handover.sh" "$source_handover"

if ! grep -E '^- Result: PASS$' "$preflight" >/dev/null; then
  echo "ERROR preflight report inside delivery archive is not marked PASS." >&2
  exit 1
fi
if ! grep -E '^- Result: PASS$' "$handover_verify" >/dev/null; then
  echo "ERROR handover verify report inside delivery archive is not marked PASS." >&2
  exit 1
fi
for token in '- PASS: input-gate smoke' '- PASS: go/no-go smoke'; do
  if ! grep -Fq -- "$token" "$handover_verify"; then
    echo "ERROR handover verify report inside delivery archive is missing smoke evidence: $token" >&2
    exit 1
  fi
done

entries=$(find "$TMP" -type f | wc -l | tr -d ' ')
rm -rf "$TMP"

echo "Test-server delivery validation: PASS"
echo "Archive: $ARCHIVE_PATH"
echo "Checksum: PASS ($ARCHIVE_PATH.sha256)"
echo "Entries: $entries"
echo "Patch mode: $PATCH_MODE"
