#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
IM_ROOT=${IM_ROOT:-../../im后端/im后端}
ACCEPTANCE_PATH=${ACCEPTANCE_PATH:-}
SIGNOFF_PATH=${SIGNOFF_PATH:-}
RISK_PATH=${RISK_PATH:-}
DELIVERY_INDEX_PATH=${DELIVERY_INDEX_PATH:-}

cd "$ROOT"

OUTPUT_ROOT="$ROOT/$OUTPUT_DIR"
STAGE="$OUTPUT_ROOT/mongoyia-handover-$STAMP"
TAR_PATH="$OUTPUT_ROOT/mongoyia-handover-$STAMP.tar.gz"
HASH_PATH="$TAR_PATH.sha256"

latest_file() {
  pattern=$1
  find "$ROOT/runtime/acceptance" -maxdepth 1 -type f -name "$pattern" 2>/dev/null | sort | tail -n 1
}

copy_to_stage() {
  src=$1
  rel=$2
  if [ "$src" = "" ] || [ ! -f "$src" ]; then
    echo "WARN missing handover file: $rel" >&2
    return
  fi
  mkdir -p "$(dirname "$STAGE/$rel")"
  cp "$src" "$STAGE/$rel"
}

assert_stage_file() {
  rel=$1
  if [ ! -f "$STAGE/$rel" ]; then
    echo "ERROR missing required staged handover file: $rel" >&2
    exit 1
  fi
}

assert_tar_entry() {
  rel=$1
  if ! tar -tzf "$TAR_PATH" | sed 's#^\./##' | grep -Fx "$rel" >/dev/null; then
    echo "ERROR missing required handover archive entry: $rel" >&2
    exit 1
  fi
}

rm -rf "$STAGE"
mkdir -p "$STAGE"

PROJECT_FILES="
MONGOYIA_README.md
.env.example
.env.test.example
docs/mongoyia-cn-overview.md
docs/mongoyia-package-index.md
docs/mongoyia-development-progress.md
docs/mongoyia-delivery-status.md
docs/mongoyia-test-server-runbook.md
docs/mongoyia-test-server-receiver.md
docs/mongoyia-test-server-inputs.md
docs/mongoyia-external-integration-inputs.md
docs/mongoyia-deploy-checklist.md
docs/mongoyia-handover.md
docs/mongoyia-change-index.md
docs/mongoyia-acceptance-signoff-template.md
docs/mongoyia-local-baseline.md
docs/mongoyia-manual-qa-checklist.md
docs/mongoyia-mongolian-review-workflow.md
docs/mongoyia-mongolian-review-evidence.md
docs/mongoyia-p2-evidence-pack.md
docs/mongoyia-payment-sandbox-evidence.md
docs/mongoyia-im-wss-evidence.md
docs/mongoyia-production-readiness.md
docs/mongoyia-production-scheduled-monitoring.md
docs/mongoyia-production-load-test-evidence.md
docs/mongoyia-production-evidence-summary.md
docs/mongoyia-production-go-live-gate.md
docs/mongoyia-production-rollout-rollback.md
console/shell/mongoyia-acceptance.ps1
console/shell/mongoyia-acceptance.sh
console/shell/mongoyia-test-profile-preflight.ps1
console/shell/mongoyia-test-profile-preflight.sh
console/shell/mongoyia-test-server-dry-run.ps1
console/shell/mongoyia-test-server-dry-run.sh
console/shell/mongoyia-test-server-preflight-report.ps1
console/shell/mongoyia-test-server-preflight-report.sh
console/shell/mongoyia-test-server-go-no-go.ps1
console/shell/mongoyia-test-server-go-no-go.sh
console/shell/mongoyia-test-server-go-no-go-smoke.ps1
console/shell/mongoyia-test-server-go-no-go-smoke.sh
console/shell/mongoyia-test-server-receiver.ps1
console/shell/mongoyia-test-server-receiver.sh
console/shell/mongoyia-test-server-restore.ps1
console/shell/mongoyia-test-server-restore.sh
console/shell/mongoyia-test-server-restore-plan.ps1
console/shell/mongoyia-test-server-restore-plan.sh
console/shell/mongoyia-test-server-input-gate.ps1
console/shell/mongoyia-test-server-input-gate.sh
console/shell/mongoyia-test-server-input-gate-smoke.ps1
console/shell/mongoyia-test-server-input-gate-smoke.sh
console/shell/mongoyia-sql-dump-manifest.ps1
console/shell/mongoyia-sql-dump-manifest.sh
console/shell/mongoyia-env-redacted-report.ps1
console/shell/mongoyia-env-redacted-report.sh
console/shell/mongoyia-handoff-status.ps1
console/shell/mongoyia-handoff-status.sh
console/shell/mongoyia-p2-readiness.ps1
console/shell/mongoyia-p2-readiness.sh
console/shell/mongoyia-p2-evidence-pack.ps1
console/shell/mongoyia-p2-evidence-pack.sh
console/shell/mongoyia-payment-sandbox-evidence.ps1
console/shell/mongoyia-payment-sandbox-evidence.sh
console/shell/mongoyia-im-wss-evidence.ps1
console/shell/mongoyia-im-wss-evidence.sh
console/shell/mongoyia-mongolian-review-evidence.ps1
console/shell/mongoyia-mongolian-review-evidence.sh
console/shell/mongoyia-production-backup.ps1
console/shell/mongoyia-production-backup.sh
console/shell/mongoyia-production-backup-verify.ps1
console/shell/mongoyia-production-backup-verify.sh
console/shell/mongoyia-production-health.ps1
console/shell/mongoyia-production-health.sh
console/shell/mongoyia-production-monitor.ps1
console/shell/mongoyia-production-monitor.sh
console/shell/mongoyia-production-load-smoke.ps1
console/shell/mongoyia-production-load-smoke.sh
console/shell/mongoyia-production-load-test-evidence.ps1
console/shell/mongoyia-production-load-test-evidence.sh
console/shell/mongoyia-production-scheduled-check.ps1
console/shell/mongoyia-production-scheduled-check.sh
console/shell/mongoyia-production-evidence-summary.ps1
console/shell/mongoyia-production-evidence-summary.sh
console/shell/mongoyia-production-go-live-gate.ps1
console/shell/mongoyia-production-go-live-gate.sh
console/shell/mongoyia-test-server-delivery-archive.ps1
console/shell/mongoyia-test-server-delivery-archive.sh
console/shell/mongoyia-validate-test-server-delivery.ps1
console/shell/mongoyia-validate-test-server-delivery.sh
console/shell/mongoyia-final-handover.ps1
console/shell/mongoyia-final-handover.sh
console/shell/mongoyia-archive-handover.ps1
console/shell/mongoyia-archive-handover.sh
console/shell/mongoyia-validate-handover-archive.ps1
console/shell/mongoyia-validate-handover-archive.sh
console/shell/mongoyia-handover-verify.ps1
console/shell/mongoyia-handover-verify.sh
console/shell/mongoyia-worktree-inventory.ps1
console/shell/mongoyia-worktree-inventory.sh
console/shell/mongoyia-source-diff-export.ps1
console/shell/mongoyia-source-diff-export.sh
console/shell/mongoyia-untracked-source-export.ps1
console/shell/mongoyia-untracked-source-export.sh
console/shell/mongoyia-validate-untracked-source.ps1
console/shell/mongoyia-validate-untracked-source.sh
console/shell/mongoyia-source-handover-archive.ps1
console/shell/mongoyia-source-handover-archive.sh
console/shell/mongoyia-validate-source-handover.ps1
console/shell/mongoyia-validate-source-handover.sh
console/controllers/DeployCheckController.php
console/controllers/MongoyiaPackageCheckController.php
console/controllers/MongoyiaSecurityScanController.php
console/controllers/MongoyiaDataReadinessController.php
console/controllers/MongoyiaCatalogReadinessController.php
console/controllers/MongoyiaTranslationReadinessController.php
console/controllers/MongoyiaTranslationAuditController.php
console/controllers/MongoyiaTranslationReviewController.php
console/controllers/MongoyiaOrderIntegrityController.php
console/controllers/MongoyiaPaymentAuditController.php
console/controllers/MongoyiaAcceptanceController.php
console/controllers/MongoyiaSignoffController.php
console/controllers/MongoyiaDeliveryIndexController.php
console/controllers/MongoyiaRiskRegisterController.php
console/controllers/MongoyiaTestCleanupController.php
console/controllers/MongoyiaHostCleanupController.php
console/controllers/MongoyiaCatalogCleanupController.php
console/controllers/ApiSmokeTestController.php
console/controllers/MallSmokeTestController.php
console/controllers/BackendSmokeTestController.php
console/controllers/MallPaymentTestController.php
console/controllers/MallTranslateController.php
console/migrations/m260608_150000_mongoyia_order_parent_id.php
console/migrations/m260608_160000_mongoyia_order_stock_deducted_at.php
console/migrations/m260608_170000_mongoyia_order_stock_refunded_at.php
console/migrations/m260608_180000_mongoyia_payment_attempt.php
console/migrations/m260608_181000_mongoyia_payment_attempt_permission.php
console/migrations/m260608_182000_mongoyia_payment_attempt_business_key.php
console/migrations/m260608_183000_mongoyia_order_product_stats_permission.php
console/migrations/m260608_184000_mongoyia_chat_context.php
console/migrations/m260608_185000_mongoyia_chat_read_state.php
"

for rel in $PROJECT_FILES; do
  copy_to_stage "$ROOT/$rel" "$rel"
done

IM_FILES="
.env.example
.env.test.example
README.md
main.py
requirements.txt
scripts/start-im.ps1
scripts/stop-im.ps1
scripts/status-im.ps1
scripts/im-healthcheck.py
scripts/im-regression.py
scripts/im-concurrency.py
deploy/mongoyia-im.service.example
deploy/supervisor-mongoyia-im.conf.example
"

for rel in $IM_FILES; do
  copy_to_stage "$ROOT/$IM_ROOT/$rel" "im-backend/$rel"
done

[ "$ACCEPTANCE_PATH" = "" ] && ACCEPTANCE_PATH=$(latest_file "mongoyia-acceptance-*.md")
[ "$SIGNOFF_PATH" = "" ] && SIGNOFF_PATH=$(latest_file "mongoyia-signoff-*.md")
[ "$RISK_PATH" = "" ] && RISK_PATH=$(latest_file "mongoyia-risk-register-*.md")
[ "$DELIVERY_INDEX_PATH" = "" ] && DELIVERY_INDEX_PATH=$(latest_file "mongoyia-delivery-index-*.md")

copy_to_stage "$ACCEPTANCE_PATH" "runtime/acceptance/$(basename "$ACCEPTANCE_PATH")"
copy_to_stage "$SIGNOFF_PATH" "runtime/acceptance/$(basename "$SIGNOFF_PATH")"
copy_to_stage "$RISK_PATH" "runtime/acceptance/$(basename "$RISK_PATH")"
copy_to_stage "$DELIVERY_INDEX_PATH" "runtime/acceptance/$(basename "$DELIVERY_INDEX_PATH")"

cat > "$STAGE/MANIFEST.md" <<EOF
# Mongoyia Handover Archive Manifest

- Generated at: $(date '+%Y-%m-%d %H:%M:%S')
- Source root: $ROOT
- Acceptance report: runtime/acceptance/$(basename "$ACCEPTANCE_PATH")
- Signoff file: runtime/acceptance/$(basename "$SIGNOFF_PATH")
- Risk register: runtime/acceptance/$(basename "$RISK_PATH")
- Delivery index: runtime/acceptance/$(basename "$DELIVERY_INDEX_PATH")

This archive intentionally includes templates and handover scripts only. It does not include real .env files, vendor dependencies, uploaded files, or database dumps.
EOF

REQUIRED_FILES="
MANIFEST.md
MONGOYIA_README.md
.env.example
.env.test.example
docs/mongoyia-package-index.md
docs/mongoyia-development-progress.md
docs/mongoyia-local-baseline.md
docs/mongoyia-test-server-receiver.md
docs/mongoyia-test-server-inputs.md
docs/mongoyia-external-integration-inputs.md
docs/mongoyia-mongolian-review-workflow.md
docs/mongoyia-mongolian-review-evidence.md
docs/mongoyia-p2-evidence-pack.md
docs/mongoyia-payment-sandbox-evidence.md
docs/mongoyia-im-wss-evidence.md
docs/mongoyia-production-scheduled-monitoring.md
docs/mongoyia-production-load-test-evidence.md
docs/mongoyia-production-evidence-summary.md
docs/mongoyia-production-go-live-gate.md
console/shell/mongoyia-test-server-dry-run.sh
console/shell/mongoyia-test-server-preflight-report.sh
console/shell/mongoyia-test-server-go-no-go.sh
console/shell/mongoyia-test-server-go-no-go-smoke.sh
console/shell/mongoyia-test-server-receiver.sh
console/shell/mongoyia-test-server-restore.sh
console/shell/mongoyia-test-server-restore-plan.sh
console/shell/mongoyia-test-server-input-gate.sh
console/shell/mongoyia-test-server-input-gate-smoke.sh
console/shell/mongoyia-sql-dump-manifest.sh
console/shell/mongoyia-env-redacted-report.sh
console/shell/mongoyia-handoff-status.sh
console/shell/mongoyia-p2-readiness.sh
console/shell/mongoyia-p2-evidence-pack.sh
console/shell/mongoyia-payment-sandbox-evidence.sh
console/shell/mongoyia-im-wss-evidence.sh
console/shell/mongoyia-mongolian-review-evidence.sh
console/shell/mongoyia-production-backup-verify.sh
console/shell/mongoyia-production-load-smoke.sh
console/shell/mongoyia-production-load-test-evidence.sh
console/shell/mongoyia-production-scheduled-check.sh
console/shell/mongoyia-production-evidence-summary.sh
console/shell/mongoyia-production-go-live-gate.sh
console/shell/mongoyia-test-server-delivery-archive.sh
console/shell/mongoyia-validate-test-server-delivery.sh
console/shell/mongoyia-final-handover.sh
console/shell/mongoyia-archive-handover.sh
console/shell/mongoyia-validate-handover-archive.sh
console/shell/mongoyia-handover-verify.sh
console/shell/mongoyia-worktree-inventory.sh
console/shell/mongoyia-source-diff-export.sh
console/shell/mongoyia-untracked-source-export.sh
console/shell/mongoyia-validate-untracked-source.sh
console/shell/mongoyia-source-handover-archive.sh
console/shell/mongoyia-validate-source-handover.sh
console/controllers/MongoyiaAcceptanceController.php
console/controllers/MongoyiaPackageCheckController.php
console/controllers/MongoyiaTranslationReviewController.php
im-backend/main.py
im-backend/.env.example
im-backend/scripts/im-healthcheck.py
runtime/acceptance/$(basename "$ACCEPTANCE_PATH")
runtime/acceptance/$(basename "$SIGNOFF_PATH")
runtime/acceptance/$(basename "$RISK_PATH")
runtime/acceptance/$(basename "$DELIVERY_INDEX_PATH")
"

for rel in $REQUIRED_FILES; do
  assert_stage_file "$rel"
done

mkdir -p "$OUTPUT_ROOT"
rm -f "$TAR_PATH" "$HASH_PATH"
tar -czf "$TAR_PATH" -C "$STAGE" .

for rel in $REQUIRED_FILES; do
  assert_tar_entry "$rel"
done

if command -v sha256sum >/dev/null 2>&1; then
  (cd "$OUTPUT_ROOT" && sha256sum "$(basename "$TAR_PATH")" > "$(basename "$HASH_PATH")")
elif command -v shasum >/dev/null 2>&1; then
  (cd "$OUTPUT_ROOT" && shasum -a 256 "$(basename "$TAR_PATH")" > "$(basename "$HASH_PATH")")
else
  echo "WARN sha256sum/shasum not found; checksum file was not generated." >&2
fi

echo "Handover folder: $STAGE"
echo "Handover archive: $TAR_PATH"
if [ -f "$HASH_PATH" ]; then
  echo "Handover checksum: $HASH_PATH"
fi
echo "Archive validation: PASS ($(printf '%s\n' $REQUIRED_FILES | wc -l | tr -d ' ') required files)"
