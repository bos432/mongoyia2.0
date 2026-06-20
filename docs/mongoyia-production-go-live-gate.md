# Mongoyia Production Go-Live Gate

This read-only gate is the final production launch review index after test-server acceptance, production rehearsal evidence, production external evidence review-result apply planning, final acceptance metadata preflight, and launch owner signoff readiness are complete. It does not run checks, switch traffic, restore databases, create orders, or trigger payment callbacks.

Marker: `MONGOYIA_PRODUCTION_GO_LIVE_GATE_V1`

Metadata-only preflight marker: `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1`. Use the production external evidence import dry-run to validate sanitized owner evidence references before this final gate; it keeps production external evidence import disabled and production NO-GO. The follow-up `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1` report checks reviewer/signoff metadata only and keeps `review_accepted=0`. The review-result apply preflight marker is `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1`; it keeps `review_result_apply_executed=0` and `production_go_live_allowed=0`. The final acceptance preflight marker is `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1`; it keeps `evidence_accepted=0`, `final_acceptance_executed=0`, and `production_go_live_allowed=0`. The launch signoff readiness marker is `MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1`; it keeps `launch_signoff_accepted=0`, `launch_approval_executed=0`, and `production_go_live_allowed=0`.

## Generate Gate Report

Local Yii fixture/readiness mode:

```bash
php yii mongoyia-production-go-live-gate/run --fixture=1 --interactive=0
```

The fixture generates a local `WARN` / `NO-GO` report by design until production evidence and manual owner signoffs are complete.

Windows:

```powershell
.\console\shell\mongoyia-production-go-live-gate.ps1
```

Linux:

```sh
sh console/shell/mongoyia-production-go-live-gate.sh
```

Strict final review mode:

```powershell
.\console\shell\mongoyia-production-go-live-gate.ps1 `
  -BusinessSignoff PASS `
  -PaymentProductionSignoff PASS `
  -SettlementSignoff PASS `
  -MonitoringAlertSignoff PASS `
  -BackupRestoreDrillSignoff PASS `
  -RollbackOwnerSignoff PASS `
  -SecuritySignoff PASS `
  -LaunchWindowSignoff PASS `
  -ApproverReference "owner-or-ticket" `
  -ChangeTicket "change-ticket-id" `
  -PaymentProductionReference "provider-ticket" `
  -SettlementReference "settlement-owner-ticket" `
  -MonitoringAlertReference "monitoring-runbook-or-ticket" `
  -BackupRestoreDrillReference "restore-drill-report" `
  -RollbackOwnerReference "rollback-owner-or-rule" `
  -SecurityReference "security-review-ticket" `
  -LaunchWindowReference "launch-window-ticket" `
  -FailOnPending
```

```sh
BUSINESS_SIGNOFF=PASS \
PAYMENT_PRODUCTION_SIGNOFF=PASS \
SETTLEMENT_SIGNOFF=PASS \
MONITORING_ALERT_SIGNOFF=PASS \
BACKUP_RESTORE_DRILL_SIGNOFF=PASS \
ROLLBACK_OWNER_SIGNOFF=PASS \
SECURITY_SIGNOFF=PASS \
LAUNCH_WINDOW_SIGNOFF=PASS \
APPROVER_REFERENCE="owner-or-ticket" \
CHANGE_TICKET="change-ticket-id" \
PAYMENT_PRODUCTION_REFERENCE="provider-ticket" \
SETTLEMENT_REFERENCE="settlement-owner-ticket" \
MONITORING_ALERT_REFERENCE="monitoring-runbook-or-ticket" \
BACKUP_RESTORE_DRILL_REFERENCE="restore-drill-report" \
ROLLBACK_OWNER_REFERENCE="rollback-owner-or-rule" \
SECURITY_REFERENCE="security-review-ticket" \
LAUNCH_WINDOW_REFERENCE="launch-window-ticket" \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-production-go-live-gate.sh
```

The Yii command and wrapper scripts write `runtime/handover/mongoyia-production-go-live-gate-*.md` plus a CSV companion file.

Preflight dry-run:

```bash
php yii mongoyia-production-external-evidence-import-dry-run/run --fixture=1 --interactive=0
```

This production external evidence import dry-run validates metadata only. It records `evidence_import_executed=0` and `production_final_no_go=1`, so it cannot approve launch by itself. The review-readiness report also remains a preflight: `review_accepted=0` and `production_go_live_allowed=0` are intentional until the separate evidence acceptance and final go-live gates pass. The final go-live gate indexes the review-result apply gate directly; that gate validates the apply plan only, and `review_result_apply_executed=0` remains intentional.

Final acceptance preflight:

```bash
php yii mongoyia-production-external-evidence-final-acceptance-gate/run --fixture=1 --interactive=0
```

This final acceptance gate validates owner/signoff metadata only. It records `evidence_accepted=0`, `final_acceptance_executed=0`, and `production_go_live_allowed=0`, so it is not production evidence acceptance and cannot approve launch by itself.

Launch signoff readiness preflight:

```bash
php yii mongoyia-production-launch-signoff-readiness-gate/run --fixture=1 --interactive=0
```

This launch signoff readiness gate validates final owner signoff metadata only. It records `launch_signoff_accepted=0`, `launch_approval_executed=0`, and `production_go_live_allowed=0`, so it is not final launch approval and cannot approve traffic switch by itself.

## Required Inputs

- Latest `mongoyia-production-evidence-summary-*.md`.
- Latest `mongoyia-production-load-test-evidence-*.md` with formal load-test signoff.
- Latest `mongoyia-production-external-evidence-review-result-apply-gate-*.md`.
- Latest `mongoyia-production-external-evidence-final-acceptance-gate-*.md`.
- Latest `mongoyia-production-launch-signoff-readiness-gate-*.md`.
- Business launch approval reference.
- QPay/LianLian production credential and callback approval reference.
- Settlement, reconciliation, refund, and accounting owner approval.
- Monitoring/alerting confirmation.
- Backup restore drill confirmation.
- Rollback owner and database rollback rule.
- Security hardening signoff.
- Launch-window operator coverage.

Store only owner names, ticket IDs, sheet references, or screenshot references. Do not paste provider credentials, private keys, callback secrets, customer data, database passwords, SSH secrets, or real `.env` values.

Any manual gate set to `PASS` must include either its dedicated reference or the shared `ChangeTicket`; otherwise the generated gate marks that row as `FAIL`.

## Boundary

A PASS report means recorded evidence is complete enough for a production launch review. It is not a substitute for provider contracts, legal/compliance review, business signoff outside this repository, or an agreed launch/change-management process.
