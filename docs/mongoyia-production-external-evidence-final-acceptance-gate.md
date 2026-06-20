# Mongoyia Production External Evidence Final Acceptance Gate

This command validates sanitized final owner/signoff metadata after the production external evidence review-result apply gate exists. It is read-only and keeps production launch blocked.

Evidence marker: `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1`

Yii command:

```bash
php yii mongoyia-production-external-evidence-final-acceptance-gate/run --fixture=1 --interactive=0
```

Optional pinned review-result apply gate report:

```bash
php yii mongoyia-production-external-evidence-final-acceptance-gate/run --reviewResultApplyGatePath=runtime/handover/mongoyia-production-external-evidence-review-result-apply-gate-<stamp>.md --interactive=0
```

The command checks that the latest production external evidence review-result apply gate report is PASS and still records apply-disabled, acceptance-disabled, and production NO-GO markers. It then validates safe final acceptance metadata for these roles:

- business
- ops
- security
- payment
- engineering
- finance
- language
- rollback

Boundary markers:

- `final_acceptance_metadata_valid=1` means final owner/signoff metadata fields are complete.
- `final_acceptance_ready=1` means the local preflight is ready for external/manual acceptance review.
- `evidence_accepted=0`
- `final_acceptance_executed=0`
- `production_go_live_allowed=0`
- `production_final_no_go=1`

The command writes `runtime/handover/mongoyia-production-external-evidence-final-acceptance-gate-*.md` plus a CSV companion. It does not accept evidence, persist signoff rows, read artifacts, import evidence, call providers or infrastructure services, or mutate orders, payments, callbacks, chat records, files, shipment rows, fund logs, tickets, statistics, signoff rows, or review rows.

Use this report before `mongoyia-production-evidence-summary` and `mongoyia-production-go-live-gate`; it is a final evidence-acceptance metadata preflight, not production release approval.
