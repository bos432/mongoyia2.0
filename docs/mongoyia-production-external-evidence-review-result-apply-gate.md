# Mongoyia Production External Evidence Review Result Apply Gate

This command validates sanitized review-result metadata after the production external evidence review readiness report exists. It is read-only and keeps production launch blocked.

Evidence marker: `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1`

Yii command:

```bash
php yii mongoyia-production-external-evidence-review-result-apply-gate/run --fixture=1 --interactive=0
```

Optional pinned review readiness report:

```bash
php yii mongoyia-production-external-evidence-review-result-apply-gate/run --reviewReadinessPath=runtime/handover/mongoyia-production-external-evidence-review-readiness-<stamp>.md --interactive=0
```

The command checks that the latest production external evidence review readiness report is PASS and still records review-disabled and production NO-GO markers. It then validates safe review-result metadata for these roles:

- business
- ops
- security
- payment
- engineering
- finance
- language

Boundary markers:

- `review_result_valid=1` means review-result metadata fields are complete.
- `review_result_apply_allowed=0`
- `review_result_apply_executed=0`
- `review_accepted=0`
- `production_go_live_allowed=0`
- `production_final_no_go=1`

The command writes `runtime/handover/mongoyia-production-external-evidence-review-result-apply-gate-*.md` plus a CSV companion. It does not apply review results, accept evidence, read artifacts, import signoff rows, call providers or infrastructure services, or mutate orders, payments, callbacks, chat records, files, shipment rows, fund logs, tickets, statistics, signoff rows, or review rows.

Use this report before the final production evidence acceptance and `mongoyia-production-go-live-gate`; it is a preflight for review-result traceability, not a production launch approval.
