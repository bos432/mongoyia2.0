# Mongoyia Production External Evidence Review Readiness

This command validates reviewer and signoff metadata for the production external evidence bundle after the import dry-run report exists. It is read-only and keeps production launch blocked.

Evidence marker: `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1`

Yii command:

```bash
php yii mongoyia-production-external-evidence-review-readiness/run --fixture=1 --interactive=0
```

Optional pinned import dry-run report:

```bash
php yii mongoyia-production-external-evidence-review-readiness/run --importDryRunPath=runtime/handover/mongoyia-production-external-evidence-import-dry-run-<stamp>.md --interactive=0
```

The command checks that the latest production external evidence import dry-run report is PASS and still records import-disabled and production NO-GO markers. It then validates safe reviewer metadata for these roles:

- business
- ops
- security
- payment
- engineering
- finance
- language

Boundary markers:

- `review_input_valid=1` means reviewer/signoff metadata fields are present.
- `review_accepted=0`
- `production_go_live_allowed=0`
- `production_final_no_go=1`

The command writes `runtime/handover/mongoyia-production-external-evidence-review-readiness-*.md` plus a CSV companion. It does not accept evidence, read artifacts, import signoff rows, call providers or infrastructure services, or mutate orders, payments, callbacks, chat records, files, shipment rows, fund logs, tickets, statistics, or signoff rows.

Use this readiness report before `mongoyia-production-external-evidence-review-result-apply-gate` and `mongoyia-production-go-live-gate`; it is a preflight for human review completeness, not a production launch approval.
