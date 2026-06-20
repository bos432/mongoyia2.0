# Mongoyia Production Evidence Summary

This read-only summary is the final local evidence index before production signoff, including the production external evidence review-result apply gate, final acceptance metadata gate, and launch owner signoff readiness gate. It does not run checks, restore databases, create orders, trigger payment callbacks, call payment providers, connect to IM, or mutate business data.

Evidence marker: `MONGOYIA_PRODUCTION_EVIDENCE_SUMMARY_V1`

Related metadata-only dry-run gate: `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1`. Run the production external evidence import dry-run before the final summary when operators need to validate non-sensitive owner evidence references without accepting them. The follow-up review-readiness marker is `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1`; it validates reviewer/signoff metadata only and keeps `review_accepted=0`. The final acceptance marker is `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1`; it validates owner/signoff metadata only and keeps `evidence_accepted=0`, `final_acceptance_executed=0`, and `production_go_live_allowed=0`. The launch signoff marker is `MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1`; it validates launch owner signoff metadata only and keeps `launch_signoff_accepted=0`, `launch_approval_executed=0`, and `production_go_live_allowed=0`.

Yii command:

```bash
php yii mongoyia-production-evidence-summary/run --fixture=1 --interactive=0
```

## Generate Summary

Windows:

```powershell
.\console\shell\mongoyia-production-evidence-summary.ps1
```

Linux:

```sh
sh console/shell/mongoyia-production-evidence-summary.sh
```

Strict production signoff mode:

```sh
FAIL_ON_PENDING=1 sh console/shell/mongoyia-production-evidence-summary.sh
php yii mongoyia-production-evidence-summary/run --failOnPending=1 --interactive=0
```

The Yii command and wrapper scripts read the latest local reports for:

- test-server acceptance
- P2 evidence pack
- payment sandbox evidence
- IM WSS evidence
- handover verification
- test-server preflight
- scheduled monitoring
- scheduled monitoring evidence, optionally recorded with `mongoyia-production-scheduled-check-evidence`
- production health
- production monitor
- backup verification, optionally recorded with `mongoyia-production-backup-verify-evidence`
- load smoke
- formal load-test evidence
- production external evidence review-result apply gate
- production external evidence final acceptance gate
- production launch signoff readiness gate
- Mongolian review evidence

The production external evidence import dry-run can be used before this summary to validate sanitized metadata rows for backup, load-test, monitoring, security, payment, IM, Mongolian review, and launch/rollback signoff evidence. It keeps `evidence_import_executed=0` and `production_final_no_go=1`, so it does not replace the summary or the go-live gate. The review-readiness report reads the latest import dry-run report and checks reviewer/signoff references while keeping `review_accepted=0` and `production_go_live_allowed=0`; it is not production evidence acceptance. The review-result apply gate is indexed by this summary and checks the dry-run apply plan while keeping `review_result_apply_executed=0`. The final acceptance gate is also indexed and checks final owner/signoff metadata while keeping `evidence_accepted=0`. The launch signoff readiness gate checks launch owner signoff metadata while keeping `launch_signoff_accepted=0`; neither gate is production release approval.

It writes `runtime/handover/mongoyia-production-evidence-summary-*.md` plus a CSV companion. It returns non-zero when a generated report is `FAIL` or `UNKNOWN`. With strict mode, pending evidence also returns non-zero.

For the test-server P2 handoff itself, run `mongoyia-p2-evidence-pack` after restore, preflight, full acceptance, payment sandbox validation, and IM WSS validation. That pack is the receiver-side review bundle; this production evidence summary is the later readiness index.

## Manual Evidence Still Required

The following items cannot be proven by local scripts alone:

- QPay/LianLian production credential signoff after sandbox evidence passes
- public-domain IM WSS production-domain signoff after WSS evidence passes
- native/business Mongolian content review, recorded with `mongoyia-mongolian-review-evidence`
- formal load-test owner signoff, recorded with `mongoyia-production-load-test-evidence`
- backup restore drill to a disposable database
- rollout owner, rollback owner, and business launch approval

Record these in `docs/mongoyia-external-integration-inputs.md` and `docs/mongoyia-production-rollout-rollback.md`.
