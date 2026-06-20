# Mongoyia Production Rollout And Rollback

This checklist is for the production-readiness phase after test-server acceptance. It is not a substitute for provider signoff, business signoff, or a real backup restore drill.

## Rollout Preconditions

| Gate | Required Evidence | Status | Owner | Notes |
|---|---|---:|---|---|
| Test-server acceptance | Full acceptance report is PASS with cleanup verification | Pending |  | Keep report under `runtime/acceptance`. |
| Backup restore drill | Database backup and checksum were restored to a disposable database | Pending |  | Do not treat backup as valid until restore was tested. |
| Payment providers | QPay and LianLian sandbox callback regression passed and production credentials were verified | Pending |  | Include duplicate, bad signature, expired timestamp, and amount mismatch cases. |
| IM WSS | Public WSS healthcheck, regression, and lightweight concurrency passed | Pending |  | Use the same reverse-proxy pattern planned for production. |
| Monitoring | Health and monitor reports are scheduled or integrated with alerting | Pending |  | Include PHP, database, Redis, Python IM, disk, logs, and payment callback failures. |
| Load smoke | `mongoyia-production-load-smoke` report is PASS or approved with warnings | Pending |  | Non-destructive GET checks plus optional IM concurrency. |
| Formal load test | `mongoyia-production-load-test-evidence` report is PASS | Pending |  | External report covers browsing, checkout, payment callbacks, IM WSS concurrency, datastore/resource behavior, and rollback/monitoring observation. |
| Evidence summary | `mongoyia-production-evidence-summary` is generated and reviewed | Pending |  | Pending generated evidence is a blocker unless explicitly signed off. |
| Go-live gate | `mongoyia-production-go-live-gate` is generated and reviewed | Pending |  | Records final manual owner approvals without secrets. |
| Rollback owner | Operator and business owner are available during the launch window | Pending |  |  |

## Rollout Steps

1. Freeze content and code changes for the launch window.
2. Generate a fresh database backup and checksum outside the web root.
3. Confirm `.env` contains production domain, WSS URL, Redis, payment, callback, and secret values.
4. Run production health and security checks.
5. Run the non-destructive load smoke against the production candidate URL.
6. Record formal load-test evidence and owner approval.
7. Switch traffic only after owner approval is recorded outside this repository.
8. Watch application logs, payment callbacks, IM connectivity, database errors, Redis, disk usage, and order creation for the first hour.

## Rollback Triggers

Rollback should be considered if any of these occur during the launch window:

- Checkout or order creation returns repeated 5xx errors.
- Payment callbacks fail signature, amount, idempotency, or status checks unexpectedly.
- IM WSS cannot connect or repeatedly disconnects for real users.
- Database errors, Redis failures, or disk pressure affect customer flows.
- Monitoring shows sustained response times above the agreed business threshold.
- Business owner requests rollback because content, language, price, or settlement behavior is wrong.

## Rollback Steps

1. Stop new traffic to the release by reverting DNS, proxy upstream, load-balancer target, or app deployment pointer.
2. Preserve logs, callback payload samples, and the current database state for investigation.
3. Decide whether database rollback is required:
   - If no real orders/payments occurred, restore the pre-launch backup after owner approval.
   - If real orders/payments occurred, do not blindly restore; reconcile orders and payment attempts first.
4. Restart PHP/web server, Redis, and Python IM only after config is confirmed.
5. Run health, monitor, and smoke checks against the rollback target.
6. Record the incident summary, root cause owner, and next release gate.

## Command References

Windows:

```powershell
.\console\shell\mongoyia-production-backup.ps1 -EnvPath .env -OutputDir runtime/backups -IncludeUploads
.\console\shell\mongoyia-production-health.ps1 -Strict
.\console\shell\mongoyia-production-monitor.ps1
.\console\shell\mongoyia-production-load-smoke.ps1 -BaseUrl "https://<domain>" -ImUrl "wss://<domain>/<im-path>"
.\console\shell\mongoyia-production-load-test-evidence.ps1 -LoadTestReference "report-or-ticket" -BrowsingSignoff PASS -CheckoutSignoff PASS -PaymentCallbackSignoff PASS -ImConcurrencySignoff PASS -DataStoreSignoff PASS -RollbackMonitoringSignoff PASS -Tester "owner-or-team" -FailOnPending
.\console\shell\mongoyia-production-evidence-summary.ps1 -FailOnPending
.\console\shell\mongoyia-production-go-live-gate.ps1 -FailOnPending
```

Linux:

```sh
ENV_PATH=.env OUTPUT_DIR=runtime/backups INCLUDE_UPLOADS=1 sh console/shell/mongoyia-production-backup.sh
STRICT=1 sh console/shell/mongoyia-production-health.sh
sh console/shell/mongoyia-production-monitor.sh
BASE_URL=https://<domain> IM_URL=wss://<domain>/<im-path> sh console/shell/mongoyia-production-load-smoke.sh
LOAD_TEST_REFERENCE="report-or-ticket" BROWSING_SIGNOFF=PASS CHECKOUT_SIGNOFF=PASS PAYMENT_CALLBACK_SIGNOFF=PASS IM_CONCURRENCY_SIGNOFF=PASS DATA_STORE_SIGNOFF=PASS ROLLBACK_MONITORING_SIGNOFF=PASS TESTER="owner-or-team" FAIL_ON_PENDING=1 sh console/shell/mongoyia-production-load-test-evidence.sh
FAIL_ON_PENDING=1 sh console/shell/mongoyia-production-evidence-summary.sh
FAIL_ON_PENDING=1 sh console/shell/mongoyia-production-go-live-gate.sh
```
