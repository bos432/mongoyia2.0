# Mongoyia Production Readiness

This project is currently prepared for test-server acceptance. Production launch still needs real provider credentials, real HTTPS/WSS infrastructure, monitoring, backups, load testing, settlement/reconciliation review, and manual business signoff.

## Backup

Evidence marker: `MONGOYIA_PRODUCTION_BACKUP_VERIFY_EVIDENCE_V1`

Windows:

```powershell
.\console\shell\mongoyia-production-backup.ps1 -EnvPath .env -OutputDir runtime/backups -IncludeUploads
```

Linux:

```sh
ENV_PATH=.env OUTPUT_DIR=runtime/backups INCLUDE_UPLOADS=1 sh console/shell/mongoyia-production-backup.sh
```

The backup command creates a database archive, checksum sidecar, and a small manifest. Keep the archive outside the web root and test restore from the checksum before relying on it.

Verify the backup archive before treating it as usable:

Windows:

```powershell
.\console\shell\mongoyia-production-backup-verify.ps1 -BackupArchive runtime/backups/<backup>.sql.zip
```

Linux:

```sh
BACKUP_ARCHIVE=runtime/backups/<backup>.sql.gz sh console/shell/mongoyia-production-backup-verify.sh
```

The verification command is non-destructive. It checks the checksum sidecar, confirms the compressed database archive is readable, optionally verifies the upload archive, and writes a Markdown report.

Yii evidence command:

```bash
php yii mongoyia-production-backup-verify-evidence/run --fixture=1 --interactive=0
```

The Yii evidence command is read-only. It indexes the latest backup-verify report plus restore-drill, retention/storage, and rollback-owner signoff references, then writes `runtime/handover/mongoyia-production-backup-verify-evidence-*.md` plus a CSV companion. Local fixture mode stays `WARN` until a real backup verification report and manual restore evidence are available.

## Health Check

Evidence marker: `MONGOYIA_PRODUCTION_HEALTH_V1`

Yii command:

```bash
php yii mongoyia-production-health/run --fixture=1 --interactive=0
```

Windows:

```powershell
.\console\shell\mongoyia-production-health.ps1 -Strict
```

Linux:

```sh
STRICT=1 sh console/shell/mongoyia-production-health.sh
```

The health check runs production-profile deploy checks, security scan, payment audit, order integrity, translation audit, and generated-test-data cleanup dry-run.

Local fixture mode is expected to generate a non-PASS report until prod-style HTTPS/WSS, IM secret, payment credentials, PHP upload limit, and callback HMAC values are configured. The command is read-only and writes `runtime/handover/mongoyia-production-health-*.md` plus a CSV companion.

## Monitoring

Evidence marker: `MONGOYIA_PRODUCTION_MONITOR_V1`

Yii command:

```bash
php yii mongoyia-production-monitor/run --fixture=1 --interactive=0
```

Windows:

```powershell
.\console\shell\mongoyia-production-monitor.ps1
```

Linux:

```sh
sh console/shell/mongoyia-production-monitor.sh
```

The monitor template checks PHP CLI, required env presence, Redis port, Python IM port, disk usage, writable runtime paths, and runtime log presence. The Yii fixture writes `runtime/handover/mongoyia-production-monitor-*.md` plus a CSV companion, skips socket checks by default, and stays read-only. Feed the generated report into the server's scheduler/alerting system after test-server restore.

Use `docs/mongoyia-production-scheduled-monitoring.md` to run monitor, health, backup verification, and load smoke from Task Scheduler or cron with a single alertable exit code.

Use `docs/mongoyia-production-load-test-evidence.md` after the external formal load test to record the report reference and owner signoffs for browsing, checkout, payment callbacks, IM WSS concurrency, database/Redis/resource behavior, and rollback/monitoring observation.

Use `docs/mongoyia-production-evidence-summary.md` after the scheduled checks to summarize PASS/WARN/FAIL/PENDING evidence for production signoff.

Use `docs/mongoyia-production-go-live-gate.md` after the evidence summary and manual owner approvals are complete to record the final non-sensitive launch gate.

## Load Smoke

Windows:

```powershell
.\console\shell\mongoyia-production-load-smoke.ps1 -BaseUrl "https://<domain>" -ImUrl "wss://<domain>/<im-path>"
```

Linux:

```sh
BASE_URL=https://<domain> IM_URL=wss://<domain>/<im-path> sh console/shell/mongoyia-production-load-smoke.sh
```

The load smoke is non-destructive. It performs repeated GET checks against storefront paths and optionally runs the lightweight Python IM concurrency regression. It does not create orders, trigger payment callbacks, or mutate database rows.

## Formal Load-Test Evidence

Windows:

```powershell
.\console\shell\mongoyia-production-load-test-evidence.ps1 -LoadTestReference "report-or-ticket" -BrowsingSignoff PASS -CheckoutSignoff PASS -PaymentCallbackSignoff PASS -ImConcurrencySignoff PASS -DataStoreSignoff PASS -RollbackMonitoringSignoff PASS -Tester "owner-or-team" -FailOnPending
```

Linux:

```sh
LOAD_TEST_REFERENCE="report-or-ticket" BROWSING_SIGNOFF=PASS CHECKOUT_SIGNOFF=PASS PAYMENT_CALLBACK_SIGNOFF=PASS IM_CONCURRENCY_SIGNOFF=PASS DATA_STORE_SIGNOFF=PASS ROLLBACK_MONITORING_SIGNOFF=PASS TESTER="owner-or-team" FAIL_ON_PENDING=1 sh console/shell/mongoyia-production-load-test-evidence.sh
```

This step is also read-only. It records externally run load-test evidence and does not generate traffic itself.

## Rollout And Rollback

Use `docs/mongoyia-production-rollout-rollback.md` before production launch to record rollout preconditions, rollback triggers, database rollback rules, and operator ownership.

## Mongolian Review

Generate the current human-review CSV:

```sh
php yii mongoyia-translation-review/run
```

Use `docs/mongoyia-mongolian-review-workflow.md` for the full export, dry-run, import, and post-apply audit loop. The latest local export contains product/category Mongolian rows and flags rows that still need human review. Machine translation is acceptable for test-server browsing, but production launch needs a human review pass for product detail content and key storefront wording.

Record the final non-sensitive review signoff evidence with:

```sh
REVIEWER="name-or-ticket" REVIEW_SIGNOFF=PASS IMAGE_TEXT_SIGNOFF=PASS REMAINING_RISK_REFERENCE="ticket-or-sheet-reference" FAIL_ON_PENDING=1 sh console/shell/mongoyia-mongolian-review-evidence.sh
```

## Production Gate

Do not launch production until all of these are complete:

- Real backup and restore drill are PASS.
- Payment provider sandbox and production credential verification are PASS.
- QPay/LianLian callback signatures, amount checks, idempotency, and callback allowlists are confirmed.
- IM WSS healthcheck and regression pass behind the real domain.
- Monitoring and alerting exist for PHP, database, Redis, Python IM, disk, queues, and payment callback failures.
- Scheduled monitoring wrapper is configured and alerts on non-zero exit code or `Result: FAIL`.
- Production evidence summary is generated and all required gates are PASS or explicitly signed off.
- Production go-live gate is generated with all required business, payment, settlement, monitoring, backup restore drill, rollback, security, and launch-window approvals recorded.
- Non-destructive load smoke has passed or warnings were explicitly approved.
- Formal load-test evidence is recorded and signed off for browsing, checkout, payment callback, IM concurrency, datastore/resource, and rollback/monitoring scenarios.
- Rollout/rollback owner and database rollback policy are documented.
- Mongolian content review is signed off by a native/business reviewer.
- Load-test report reference and rollback plan are documented.
