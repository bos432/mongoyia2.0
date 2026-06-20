# Mongoyia Production Launch Signoff Readiness Gate

This command validates sanitized launch owner signoff metadata after the production external evidence final acceptance gate exists. It is read-only and keeps production launch blocked.

Evidence marker: `MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1`

Yii command:

```bash
php yii mongoyia-production-launch-signoff-readiness-gate/run --fixture=1 --interactive=0
```

Optional pinned final acceptance gate report:

```bash
php yii mongoyia-production-launch-signoff-readiness-gate/run --finalAcceptanceGatePath=runtime/handover/mongoyia-production-external-evidence-final-acceptance-gate-<stamp>.md --interactive=0
```

The command checks that the latest production external evidence final acceptance gate report is PASS and still records evidence-acceptance-disabled and production NO-GO markers. It then validates safe launch owner signoff metadata for these gates:

- business launch
- payment production
- settlement and reconciliation
- monitoring and alerting
- backup restore drill
- rollback ownership
- security signoff
- launch-window approval

Boundary markers:

- `launch_signoff_metadata_valid=1` means launch owner signoff metadata fields are complete.
- `launch_signoff_ready=1` means the local preflight is ready for external/manual owner signoff review.
- `launch_signoff_accepted=0`
- `launch_approval_executed=0`
- `production_go_live_allowed=0`
- `production_final_no_go=1`

The command writes `runtime/handover/mongoyia-production-launch-signoff-readiness-gate-*.md` plus a CSV companion. It does not accept launch signoff, persist approval rows, switch traffic, read artifacts, call providers or infrastructure services, or mutate orders, payments, callbacks, chat records, files, shipment rows, fund logs, tickets, statistics, signoff rows, or review rows.

Use this report before `mongoyia-production-evidence-summary` and `mongoyia-production-go-live-gate`; it is a launch owner signoff metadata preflight, not production release approval.
