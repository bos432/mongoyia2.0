# Mongoyia Handover Package Index

## Start Here

Read these files in order:

0. `docs/mongoyia-cn-overview.md`
1. `docs/mongoyia-operation-guide-zh-CN.md`
2. `docs/mongoyia-development-progress.md`
3. `docs/mongoyia-delivery-status.md`
4. `docs/mongoyia-test-server-run-sheet.md`
5. `docs/mongoyia-test-server-runbook.md`
6. `docs/mongoyia-test-server-receiver.md`
7. `docs/mongoyia-test-server-inputs.md`
8. `docs/mongoyia-external-integration-inputs.md`
9. `docs/mongoyia-deploy-checklist.md`
10. `docs/mongoyia-handover.md`
11. `docs/mongoyia-change-index.md`
12. `docs/mongoyia-acceptance-signoff-template.md`
13. `docs/mongoyia-local-baseline.md`
14. `docs/mongoyia-manual-qa-checklist.md`
15. `docs/mongoyia-mongolian-review-workflow.md`
16. `docs/mongoyia-p2-evidence-pack.md`
17. `docs/mongoyia-payment-sandbox-evidence.md`
18. `docs/mongoyia-im-wss-evidence.md`
19. `docs/mongoyia-payment-provider-contract.md`
20. `docs/mongoyia-im-media-contract.md`
21. `docs/mongoyia-mongolian-review-evidence.md`
22. `docs/mongoyia-production-readiness.md`
23. `docs/mongoyia-production-scheduled-monitoring.md`
24. `docs/mongoyia-production-load-test-evidence.md`
25. `docs/mongoyia-production-evidence-summary.md`
26. `docs/mongoyia-production-external-evidence-import-dry-run.md`
27. `docs/mongoyia-production-external-evidence-review-readiness.md`
28. `docs/mongoyia-production-external-evidence-review-result-apply-gate.md`
29. `docs/mongoyia-production-external-evidence-final-acceptance-gate.md`
30. `docs/mongoyia-production-launch-signoff-readiness-gate.md`
31. `docs/mongoyia-production-go-live-gate.md`
32. `docs/mongoyia-production-rollout-rollback.md`

## Main Paths

- Main PHP/Yii source: `funboot_K84jE/funboot`
- Python IM source: `im后端/im后端`
- Database baseline: `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql`
- Local mall URL: `http://127.0.0.1:8089/`
- Local IM URL: `ws://127.0.0.1:8767`

## Latest Test-Server Delivery

- Windows receiver archive: `runtime/handover/mongoyia-test-server-delivery-20260618-132613.zip`
- Linux receiver archive: `runtime/handover/mongoyia-test-server-delivery-20260618-132613.tar.gz`
- Delivery ZIP SHA256: `74f3a7a1a183a36a3dcbdf73e7a43fa674c487c41b97d0c1a242bfe9afd98e27`
- Delivery TAR.GZ SHA256: `046ed7813bef81eb4c46a176d26dea8fee1fa610f8f74a1cfdcd9b4424ffcb1d`
- Latest validation: `runtime/handover/mongoyia-test-server-preflight-20260618-132556.md` is packaged, and `mongoyia-validate-test-server-delivery.ps1` reports delivery validation PASS for `20260618-132613`.
- SQL baseline SHA256: `254044ee74325ff9cad39595ee2310d046a48760a950e598bfe5e0636eb5f379`

The delivery archive intentionally excludes SQL dumps, real `.env` files, uploads, vendor dependencies, generated assets, and secrets. Copy the archive, its `.sha256` sidecar, the SQL dump, and the SQL `.sha256` sidecar to the test server separately.
Use `docs/mongoyia-external-integration-inputs.md` to track non-sensitive P2/P3 inputs for the real test server, payment sandboxes, IM WSS reverse proxy, backup rehearsal, monitoring, and load-test ownership.

Production backup rehearsal now has a separate non-destructive verification step: run `mongoyia-production-backup` first, then run `mongoyia-production-backup-verify` against the generated database archive before accepting the backup as restorable evidence.

## Environment Files

PHP project:

- `.env.example`: local/example template.
- `.env.test.example`: test-server template.
- `.env`: local machine config, do not commit real secrets.

Python IM:

- `.env.example`: local/example template.
- `.env.test.example`: test-server template.
- `.env`: local machine config, do not commit real secrets.

Required rule: test/local/prod switching should be done by changing `.env`, not by editing PHP, Python, JS, or SQL.

IM rule: PHP `IM_WEBSOCKET_URL` is the browser-facing WebSocket address, while Python IM `IM_HOST`/`IM_PORT` are the service bind settings. In local mode, localhost WebSocket ports should match Python `IM_PORT`; in test/prod, expose IM through the agreed WSS reverse-proxy path.

Template rule: `.env.test.example` uses `test.mongoyia.example.com` only as a placeholder. `profile=test` and `profile=prod` deploy checks fail if `example.com` hosts remain.

Test-domain rule: test-server input and restore apply gates reject `mongoyia.com` and `www.mongoyia.com` by default. Use a real test domain; override only with `-AllowProductionDomainForTest` or `ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1` after a documented exception.

Payment rule: `QPAY_AUTH_URL` and `QPAY_INVOICE_URL` are the QPay gateway endpoints and must stay in `.env`; `QPAY_CALLBACK_BASE` and `LIANLIAN_CALLBACK_BASE` are the PHP mall callback base URLs. In test/prod callback bases must be HTTPS URLs on the same real host as `STORE_PLATFORM_DOMAIN`.

Translation batch rule: `mall-translate/fill --allStores=1` scans active real seller stores, not only the platform store. Use `--models`, `--ids`, and `--fields` for focused dry-run/apply batches, review reports under `runtime/translation/`, and set `GOOGLE_TRANSLATE_PROXY` only when PHP cURL cannot reach Google directly. Plain `--dryRun=1` previews the write plan without calling Google; add `--preview=1 --failOnBadPreview=1` when the report must call the real translation service but still avoid database writes.

Production rehearsal rule: `mongoyia-production-backup`, `mongoyia-production-backup-verify`, `mongoyia-production-health`, `mongoyia-production-monitor`, `mongoyia-production-load-smoke`, `mongoyia-production-load-test-evidence`, and `mongoyia-production-scheduled-check` are rehearsal tools for test/prod readiness. Run `php yii mongoyia-production-health/run --fixture=1 --interactive=0` locally to generate read-only health evidence under `runtime/handover/mongoyia-production-health-*.md`; local non-PASS is expected until prod-style HTTPS/WSS, IM secret, payment credentials, PHP upload limit, and callback HMAC values are configured. Run `php yii mongoyia-production-monitor/run --fixture=1 --interactive=0` locally to generate read-only runtime/env/disk/log monitor evidence under `runtime/handover/mongoyia-production-monitor-*.md`; local WARN is expected until real Redis/IM connectivity and production writable paths are verified on the host. Run `php yii mongoyia-production-backup-verify-evidence/run --fixture=1 --interactive=0` locally to generate read-only backup verification/restore-drill evidence under `runtime/handover/mongoyia-production-backup-verify-evidence-*.md`; local WARN is expected until a real backup-verify report and manual restore evidence exist. Run `php yii mongoyia-production-scheduled-check-evidence/run --fixture=1 --interactive=0` after the scheduler wrapper to generate read-only scheduler/alert evidence under `runtime/handover/mongoyia-production-scheduled-check-evidence-*.md`; local WARN/FAIL is expected until the real wrapper, scheduler, and alert-route references exist. The load smoke is non-destructive and does not replace a full business-approved load test; record the formal business-approved load-test report with `php yii mongoyia-production-load-test-evidence/run --fixture=1 --interactive=0` locally or the strict wrapper after external evidence is available. It writes `runtime/handover/mongoyia-production-load-test-evidence-*.md`.

Production evidence rule: run `php yii mongoyia-production-evidence-summary/run --fixture=1 --interactive=0` after generating acceptance, preflight, scheduled-check, scheduled-check evidence, health, monitor, backup-verify, load-smoke, and formal load-test evidence reports. It writes `runtime/handover/mongoyia-production-evidence-summary-*.md` and a CSV companion, records `MONGOYIA_PRODUCTION_EVIDENCE_SUMMARY_V1`, and stays read-only. Treat `PENDING` as a production blocker unless the business owner explicitly signs off the exception.

Production external evidence import dry-run rule: run `php yii mongoyia-production-external-evidence-import-dry-run/run --fixture=1 --interactive=0` before final go-live review when operators need to validate sanitized metadata references for backup, load-test, monitoring, security, payment, IM, Mongolian review, settlement/reconciliation, and launch/rollback owner evidence. It writes `runtime/handover/mongoyia-production-external-evidence-import-dry-run-*.md`, records `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1`, keeps `evidence_import_executed=0` and `production_final_no_go=1`, reads no artifacts, calls no providers or infrastructure services, and writes no business rows.

Production external evidence review readiness rule: run `php yii mongoyia-production-external-evidence-review-readiness/run --fixture=1 --interactive=0` after the import dry-run report. It writes `runtime/handover/mongoyia-production-external-evidence-review-readiness-*.md`, records `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1`, verifies safe reviewer/signoff metadata for business, ops, security, payment, engineering, finance, and language roles, keeps `review_accepted=0` and `production_go_live_allowed=0`, reads no artifacts, calls no external services, and writes no business rows.

Production external evidence review-result apply gate rule: run `php yii mongoyia-production-external-evidence-review-result-apply-gate/run --fixture=1 --interactive=0` after the review readiness report. It writes `runtime/handover/mongoyia-production-external-evidence-review-result-apply-gate-*.md`, records `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1`, verifies safe review-result metadata for business, ops, security, payment, engineering, finance, and language roles, keeps `review_result_apply_allowed=0`, `review_result_apply_executed=0`, `review_accepted=0`, and `production_go_live_allowed=0`, reads no artifacts, calls no external services, and writes no business rows.

Production external evidence final acceptance gate rule: run `php yii mongoyia-production-external-evidence-final-acceptance-gate/run --fixture=1 --interactive=0` after the review-result apply gate report. It writes `runtime/handover/mongoyia-production-external-evidence-final-acceptance-gate-*.md`, records `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1`, verifies safe final owner/signoff metadata for business, ops, security, payment, engineering, finance, language, and rollback roles, keeps `evidence_accepted=0`, `final_acceptance_executed=0`, and `production_go_live_allowed=0`, reads no artifacts, calls no external services, and writes no business rows.

Production launch signoff readiness gate rule: run `php yii mongoyia-production-launch-signoff-readiness-gate/run --fixture=1 --interactive=0` after the final acceptance gate report. It writes `runtime/handover/mongoyia-production-launch-signoff-readiness-gate-*.md`, records `MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1`, verifies safe launch owner signoff metadata for business launch, payment production, settlement/reconciliation, monitoring/alerting, backup restore drill, rollback ownership, security signoff, and launch-window approval, keeps `launch_signoff_accepted=0`, `launch_approval_executed=0`, and `production_go_live_allowed=0`, reads no artifacts, calls no external services, and writes no business rows.

Current local production external evidence metadata reports: import dry-run `runtime/handover/mongoyia-production-external-evidence-import-dry-run-20260619-161745-fixture-6672.md` is `PASS`; review readiness `runtime/handover/mongoyia-production-external-evidence-review-readiness-20260619-161755-fixture-2798.md` is `PASS`; review-result apply gate `runtime/handover/mongoyia-production-external-evidence-review-result-apply-gate-20260619-163209-fixture-3752.md` is `PASS`; final acceptance gate `runtime/handover/mongoyia-production-external-evidence-final-acceptance-gate-20260619-165434-fixture-5927.md` is `PASS`; launch signoff readiness gate `runtime/handover/mongoyia-production-launch-signoff-readiness-gate-20260619-170912-fixture-3043.md` is `PASS`. These reports are pre-go-live evidence-chain checks only and do not accept evidence or allow production launch.

Production go-live gate rule: run `php yii mongoyia-production-go-live-gate/run --fixture=1 --interactive=0` locally to generate the read-only default `WARN` / `NO-GO` report, then run the strict wrapper or Yii command with owner references after evidence summary and manual approvals are complete. It writes `runtime/handover/mongoyia-production-go-live-gate-*.md`, is read-only, and records final business, payment, settlement, monitoring, backup restore drill, rollback, security, and launch-window signoffs.

P2 evidence rule: after test-server restore, preflight, acceptance, payment sandbox checks, and IM WSS checks, run `mongoyia-p2-evidence-pack` to collect the latest non-sensitive reports into one review archive. Treat pending required P2 evidence as a test-server acceptance blocker.

Payment callback readiness rule: run `php yii mongoyia-payment-callback-readiness/run --baseUrl=<base-url> --profile=<local|test> --interactive=0` before provider signoff to confirm code hardening, env-template gates, latest payment regression evidence, and latest PWA payment UI evidence are linked in a non-sensitive report.
Payment provider readiness rule: run `php yii payment-provider-readiness/run --baseUrl=<base-url> --profile=<local|test> --interactive=0` before enabling new providers. It confirms QPay/LianLian boundaries are preserved, PayPal env-template placeholders and `docs/mongoyia-payment-provider-contract.md` exist, `PaypalRuntimeContractService` records disabled route/webhook/signature/precondition contracts, PayPal route handlers return disabled JSON while UI controls stay hidden, and `PAYPAL_ENABLED=false` keeps PayPal reserved until the full implementation gate lands.
Payment provider route skeleton gate rule: run `php yii payment-provider-route-skeleton-gate/run --fixture=1 --interactive=0` before enabling PayPal runtime traffic. It verifies `PaypalRouteSkeletonGateService`, `MONGOYIA_PAYPAL_ROUTE_SKELETON_GATE_V1`, disabled create/return/cancel/webhook route contracts, guarded `PaymentController` handlers, future `provider=paypal` audit fields, cleanup scopes for `PAYPALRT-*` fixtures, delivery evidence `mongoyia-payment-provider-route-skeleton-gate-*.md`, and unchanged order/payment/chat/fund tables without exposing PayPal UI or calling providers.
Payment provider webhook dry-run gate rule: run `php yii payment-provider-webhook-dry-run-gate/run --fixture=1 --interactive=0` before implementing PayPal live webhook handling. It verifies `PaypalWebhookDryRunGateService`, `MONGOYIA_PAYPAL_WEBHOOK_DRY_RUN_GATE_V1`, local HMAC-shim samples for valid, missing-signature, invalid-signature, expired, wrong-webhook-id, amount-mismatch, duplicate, and non-success callbacks, delivery evidence `mongoyia-payment-provider-webhook-dry-run-gate-*.md`, and unchanged order/payment/chat/fund tables without calling any payment provider or enabling PayPal UI.
Payment provider webhook verification dry-run gate rule: run `php yii payment-provider-webhook-verification-dry-run-gate/run --fixture=1 --interactive=0` before implementing PayPal live webhook verification. It verifies `PaypalWebhookVerificationDryRunService`, `MONGOYIA_PAYPAL_WEBHOOK_VERIFICATION_DRY_RUN_GATE_V1`, the future official `verify-webhook-signature` request contract, required transmission headers, cert URL/algorithm/timestamp guards, local HMAC test-shim samples, delivery evidence `mongoyia-payment-provider-webhook-verification-dry-run-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider webhook audit dry-run rule: run `php yii payment-provider-webhook-audit-dry-run/run --fixture=1 --interactive=0` before implementing PayPal live audit writes. It verifies `PaypalWebhookAuditDryRunService`, `MONGOYIA_PAYPAL_WEBHOOK_AUDIT_DRY_RUN_V1`, future `provider=paypal` `mall_payment_attempt` rows for success, failed, and ignored webhook outcomes, delivery evidence `mongoyia-payment-provider-webhook-audit-dry-run-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence gate rule: run `php yii payment-provider-paypal-sandbox-evidence-gate/run --fixture=1 --interactive=0` before collecting real PayPal sandbox signoff. It verifies `PaypalSandboxEvidenceGateService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1`, required non-sensitive sandbox evidence cases, PASS dry-run evidence, hidden PayPal UI, disabled provider API boundary, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md`, and `sandbox_evidence_ready=0` until real HTTPS sandbox evidence lands, without calling providers, writing payment attempts, or mutating business data.
Payment provider PayPal live audit write implementation gate rule: run `php yii payment-provider-paypal-live-audit-write-implementation-gate/run --fixture=1 --interactive=0` before any real PayPal audit writes are allowed. It verifies `PaypalLiveAuditWriteImplementationGateService`, `MONGOYIA_PAYPAL_LIVE_AUDIT_WRITE_IMPLEMENTATION_GATE_V1`, `live_audit_write_enabled=0`, `PaymentAttempt` field/result support, eight source dry-run `provider=paypal` webhook rows, future create/return/cancel/webhook write contracts, duplicate-webhook ignored idempotency, generated-order cleanup scope, PASS sandbox evidence gate with `sandbox_evidence_ready=0`, delivery evidence `mongoyia-payment-provider-paypal-live-audit-write-implementation-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence signoff gate rule: run `php yii payment-provider-paypal-sandbox-evidence-signoff-gate/run --fixture=1 --interactive=0` before accepting real PayPal sandbox evidence. It verifies `PaypalSandboxEvidenceSignoffGateService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1`, `signoff_ready=0`, eleven required evidence cases, the non-sensitive manifest fields `artifact_sha256` and `redaction_status`, PASS sandbox evidence/live audit write gates, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence manifest validator rule: run `php yii payment-provider-paypal-sandbox-evidence-manifest-validator/run --fixture=1 --interactive=0` before reviewing a real PayPal sandbox evidence manifest. It verifies `PaypalSandboxEvidenceManifestValidatorService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1`, eleven required manifest rows, nine manifest fields, `artifact_sha256` and `redaction_status` validation, unsafe reference/secret-marker rejection, PASS signoff gate with `signoff_ready=0`, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without reading/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence redaction checklist rule: run `php yii payment-provider-paypal-sandbox-evidence-redaction-checklist/run --fixture=1 --interactive=0` before reviewing a real PayPal evidence bundle. It verifies `PaypalSandboxEvidenceRedactionChecklistService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1`, twelve required redaction controls, nine checklist fields, `evidence_bundle_accepted=0`, PASS manifest validator with `manifest_accepted=0`, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence bundle review readiness rule: run `php yii payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run --fixture=1 --interactive=0` before manual review of a sanitized PayPal evidence bundle. It verifies `PaypalSandboxEvidenceBundleReviewReadinessService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1`, ten review readiness items, PASS manifest validator and redaction checklist reports, `bundle_review_ready=1`, `evidence_bundle_accepted=0`, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence bundle review signoff gate rule: run `php yii payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate/run --fixture=1 --interactive=0` before external reviewers sign off a sanitized PayPal evidence bundle. It verifies `PaypalSandboxEvidenceBundleReviewSignoffGateService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_SIGNOFF_GATE_V1`, nine signoff readiness items, PASS bundle review readiness report, `bundle_review_signoff_ready=1`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence signoff import dry-run rule: run `php yii payment-provider-paypal-sandbox-evidence-signoff-import-dry-run/run --fixture=1 --interactive=0` before importing any external reviewer signoff rows. It verifies `PaypalSandboxEvidenceSignoffImportDryRunService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1`, twelve non-sensitive template fields, three sanitized reviewer rows, PASS bundle review signoff gate report, `signoff_input_valid=1`, `signoff_import_applied=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-signoff-import-dry-run-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without persisting signoff rows, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal sandbox evidence review-result apply gate rule: run `php yii payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run --fixture=1 --interactive=0` before applying any external PayPal evidence review result. It verifies `PaypalSandboxEvidenceReviewResultApplyGateService`, `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1`, three approved sanitized reviewer rows, one read-only apply plan row, PASS signoff import dry-run report, `review_result_valid=1`, `review_result_apply_allowed=0`, `review_result_apply_executed=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without persisting review results, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal external evidence collection gate rule: run `php yii payment-provider-paypal-external-evidence-collection-gate/run --fixture=1 --interactive=0` before externally collecting real PayPal sandbox evidence. It verifies `PaypalExternalEvidenceCollectionGateService`, `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_COLLECTION_GATE_V1`, nine sanitized collection input references, PASS review-result apply gate report, `collection_input_valid=1`, `external_collection_ready=1`, `external_collection_started=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-external-evidence-collection-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without starting collection, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal external evidence manifest import dry-run rule: run `php yii payment-provider-paypal-external-evidence-manifest-import-dry-run/run --fixture=1 --interactive=0` before importing any externally collected PayPal sandbox evidence manifest rows. It verifies `PaypalExternalEvidenceManifestImportDryRunService`, `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1`, eleven sanitized manifest rows, PASS external collection gate report, `manifest_input_valid=1`, `manifest_import_allowed=0`, `manifest_import_executed=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without importing rows, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal external evidence manifest review readiness rule: run `php yii payment-provider-paypal-external-evidence-manifest-review-readiness/run --fixture=1 --interactive=0` before starting manual review of externally collected PayPal sandbox evidence manifest rows. It verifies `PaypalExternalEvidenceManifestReviewReadinessService`, `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_READINESS_V1`, nine review readiness items, PASS manifest import dry-run report, `manifest_review_ready=1`, `manifest_review_started=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-external-evidence-manifest-review-readiness-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without starting review, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal external evidence manifest review signoff import dry-run rule: run `php yii payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run/run --fixture=1 --interactive=0` before importing any externally reviewed PayPal sandbox evidence manifest signoff rows. It verifies `PaypalExternalEvidenceManifestReviewSignoffImportDryRunService`, `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_SIGNOFF_IMPORT_DRY_RUN_V1`, three sanitized reviewer rows, PASS manifest review readiness report, `manifest_review_signoff_input_valid=1`, `manifest_review_signoff_import_applied=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without importing signoff rows, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal external evidence manifest review-result apply gate rule: run `php yii payment-provider-paypal-external-evidence-manifest-review-result-apply-gate/run --fixture=1 --interactive=0` before applying any externally reviewed PayPal sandbox evidence manifest result. It verifies `PaypalExternalEvidenceManifestReviewResultApplyGateService`, `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_RESULT_APPLY_GATE_V1`, three approved sanitized reviewer rows, one read-only apply plan row, PASS manifest review signoff import dry-run report, `manifest_review_result_valid=1`, `manifest_review_result_apply_allowed=0`, `manifest_review_result_apply_executed=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-external-evidence-manifest-review-result-apply-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without persisting review results, accepting review, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal live provider implementation evidence dry-run rule: run `php yii payment-provider-paypal-live-provider-implementation-evidence-dry-run/run --fixture=1 --interactive=0` before implementing or enabling real PayPal provider runtime code. It verifies `PaypalLiveProviderImplementationEvidenceDryRunService`, `MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1`, twelve planned evidence rows for create/capture/cancel/webhook/audit/validation/idempotency/order-state/UI/cleanup/rollback/regression coverage, PASS live audit write implementation gate report, PASS external manifest review-result apply gate report, `live_provider_implementation_evidence_valid=1`, `live_provider_implementation_evidence_applied=0`, `live_provider_implementation_ready=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without implementing runtime provider code, accepting evidence, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal live provider implementation evidence signoff gate rule: run `php yii payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run --fixture=1 --interactive=0` before accepting any live provider implementation evidence. It verifies `PaypalLiveProviderImplementationEvidenceSignoffGateService`, `MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1`, three safe reviewer rows for business/security/technical plan approval, PASS live provider implementation evidence dry-run report, `implementation_evidence_signoff_ready=1`, `implementation_evidence_accepted=0`, `live_provider_implementation_ready=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without accepting evidence, reading/copying/hashing/importing artifacts, implementing runtime provider code, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal live execution evidence readiness gate rule: run `php yii payment-provider-paypal-live-execution-evidence-readiness-gate/run --fixture=1 --interactive=0` before collecting or accepting real PayPal sandbox/live evidence. It verifies `PaypalLiveExecutionEvidenceReadinessGateService`, `MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1`, eight sandbox execution evidence references, four live production readiness references, PASS live provider implementation evidence signoff gate report, `real_sandbox_live_evidence_ready=1`, `evidence_collection_started=0`, `sandbox_execution_evidence_accepted=0`, `live_production_evidence_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without starting collection, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider PayPal live execution evidence signoff import dry-run rule: run `php yii payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run --fixture=1 --interactive=0` before importing or accepting real PayPal sandbox/live execution signoff. It verifies `PaypalLiveExecutionEvidenceSignoffImportDryRunService`, `MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1`, twelve non-sensitive template fields, business/security/technical/ops reviewer rows, PASS live execution evidence readiness gate report, `live_execution_signoff_input_valid=1`, `signoff_import_applied=0`, `sandbox_execution_evidence_accepted=0`, `live_production_evidence_accepted=0`, `paypal_enablement_allowed=0`, delivery evidence `mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md`, and unchanged order/payment/chat/fund/ticket/stat tables without importing signoff rows, accepting evidence, reading/copying/hashing/importing artifacts, calling providers, writing payment attempts, or enabling PayPal UI.
Payment provider live verification enablement gate rule: run `php yii payment-provider-live-verification-enablement-gate/run --fixture=1 --interactive=0` before allowing `PAYPAL_ENABLED=true`. It verifies `PaypalLiveVerificationEnablementGateService`, `MONGOYIA_PAYPAL_LIVE_VERIFICATION_ENABLEMENT_GATE_V1`, PayPal env-template keys, absent template secret values, PASS webhook dry-run/verification/audit evidence, PASS external evidence manifest review-result apply gate evidence, PASS live provider implementation evidence dry-run, PASS live provider implementation evidence signoff gate, PASS live execution evidence readiness gate, PASS live execution evidence signoff import dry-run, hidden PayPal UI, disabled provider API boundary, delivery evidence `mongoyia-payment-provider-live-verification-enablement-gate-*.md`, and `enablement_allowed=false` until real sandbox/live evidence acceptance and runtime implementation land, without calling providers, writing payment attempts, or mutating business data.
Payment provider PayPal final go/no-go gate rule: run `php yii payment-provider-paypal-final-go-no-go-gate/run --fixture=1 --interactive=0` after the live verification enablement gate. It verifies `PaypalFinalGoNoGoGateService`, `MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1`, PASS live verification enablement evidence, `Final decision: NO-GO`, `go_allowed=0`, `final_decision_no_go=1`, two NO-GO reasons for real sandbox/live evidence acceptance and runtime implementation, delivery evidence `mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md`, hidden PayPal UI, disabled provider API boundary, zero provider calls, zero payment-attempt writes, and unchanged business tables.
Customer-service readiness rule: run `php yii customer-service-test/run --baseUrl=<base-url> --interactive=0` before customer-service signoff to confirm the platform/seller backend workbench, signed IM auth token, WSS/upload config, frontend product chat page, product/store context, and reserved order-assist/complaint/stat widget boundary without opening WSS or mutating chat/order data.
Advanced customer-service readiness rule: run `php yii customer-service-advanced-readiness/run --baseUrl=<base-url> --profile=<local|test> --interactive=0` before enabling order assistance, complaint handling, or service statistics. Local profile verifies the migration contract, `CustomerServiceAdvancedService` dry-run plan, and workflow dry-run transitions without applying schema; test/prod profiles require the customer-service advanced migration to be applied.
Customer-service ticket readonly backend rule: run `php yii customer-service-ticket-readonly-test/run --interactive=0` before enabling advanced customer-service operations. It verifies ticket routes, platform/seller scope isolation, permissions, rollback-clean fixtures, and no order-assist/complaint/stat write widgets.
Customer-service ticket create backend rule: run `php yii customer-service-ticket-create-test/run --interactive=0` before using backend ticket create forms. It verifies the `/mall/kf/ticket-create` permission, POST/CSRF form markers, dry-run no-mutation behavior, create event audit rows, duplicate active order-ticket blocking, store-scope blocking, invalid-type/title guards, and rollback-clean fixtures without mutating orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service ticket note backend rule: run `php yii customer-service-ticket-note-test/run --interactive=0` before using backend ticket note forms. It verifies the `/mall/kf/ticket-note` permission, POST/CSRF form markers, dry-run no-mutation behavior, note event audit rows, ticket updated timestamp/user updates, store-scope blocking, missing-content guards, and rollback-clean fixtures without mutating ticket status, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service ticket result backend rule: run `php yii customer-service-ticket-result-test/run --interactive=0` before using backend ticket result forms. It verifies the `/mall/kf/ticket-result` permission, POST/CSRF form markers, dry-run no-mutation behavior, result writeback audit rows, unchanged-result blocking, ticket updated timestamp/user updates, store-scope blocking, missing-result guards, and rollback-clean fixtures without mutating ticket status, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service ticket assign backend rule: run `php yii customer-service-ticket-assign-test/run --interactive=0` before using backend ticket handler assignment forms. It verifies the `/mall/kf/ticket-assign` permission, POST/CSRF form markers, dry-run no-mutation behavior, merchant/platform handler updates, note event audit metadata, seller platform-handler blocking, store-scope blocking, and rollback-clean fixtures without mutating ticket status, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service ticket workflow rule: run `php yii customer-service-ticket-workflow-test/run --interactive=0` before using backend ticket status buttons. It verifies the `/mall/kf/ticket-workflow` permission, POST route markers, dry-run no-mutation behavior, `pending->in_progress->resolved->closed` status transitions, event audit rows, store-scope blocking, invalid-transition blocking, and rollback-clean fixtures without mutating orders, payments, chats, IM, files, or shipment data.
Customer-service stat export rule: run `php yii customer-service-stat-export/run --fixture=1 --interactive=0` before using backend customer-service stat CSV exports. It verifies the `/mall/kf/stat-export` permission, backend export link markers, read-only Markdown/CSV evidence generation, platform/seller store-scope totals, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service stat widget readiness rule: run `php yii customer-service-stat-widget-readiness/run --fixture=1 --interactive=0` before exposing customer-service statistic write widgets. It verifies backend readiness markers, disabled apply controls, dashboard widget totals, store-scope/ticket-mix/resolution/response-time readiness, Markdown/CSV evidence generation, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service stat apply gate rule: run `php yii customer-service-stat-apply-gate/run --fixture=1 --interactive=0` before exposing customer-service statistic rebuild/upsert handling. It verifies backend reserved apply markers, disabled write controls, source-ticket aggregation, insert/update/skip dry-run plans, Markdown/CSV gate evidence, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service stat apply workflow rule: run `php yii customer-service-stat-apply-workflow/run --fixture=1 --interactive=0` before any customer-service statistic rebuild/upsert. It verifies the stat apply audit migration, dry-run no-mutation behavior, explicit apply writes for insert/update/skip audit rows, before/after JSON evidence, rollback-clean fixtures, and disabled backend write controls without mutating tickets, orders, payments, chats, IM, files, shipments, or funds.
Customer-service stat apply log review rule: run `php yii customer-service-stat-apply-log-review/run --fixture=1 --interactive=0` after any explicit customer-service statistic apply. It verifies the `/mall/kf/stat-apply-log` permission, backend readonly review markers, store/batch/operation filters, Markdown/CSV audit evidence, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, statistics, or audit logs.
Customer-service complaint export rule: run `php yii customer-service-complaint-export/run --fixture=1 --interactive=0` before using backend customer-service complaint CSV exports. It verifies the `/mall/kf/complaint-export` permission, backend export link markers, read-only Markdown/CSV complaint evidence generation, platform/seller store-scope totals, status filtering, evidence JSON presence, event counts, and rollback-clean fixtures without mutating orders, payments, chats, IM, files, shipments, funds, statistics, or complaint evidence JSON.
Customer-service complaint evidence gate rule: run `php yii customer-service-complaint-evidence-gate/run --fixture=1 --interactive=0` before exposing complaint evidence upload or evidence_json write handling. It verifies backend reserved markers, disabled write controls, valid/missing/invalid evidence JSON buckets, upload-required/repair-required/manual-review queues, Markdown/CSV gate evidence, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, statistics, or complaint evidence JSON.
Customer-service complaint evidence upload policy gate rule: run `php yii customer-service-complaint-evidence-upload-policy-gate/run --fixture=1 --interactive=0` before adding backend complaint evidence upload controls. It verifies the first enablement file policy for png, jpg/jpeg, and webp images up to 5 MB; blocks oversized, reserved document/media, unknown MIME, and path-traversal samples; generates `mongoyia-customer-service-complaint-evidence-upload-policy-gate-*.md`; and keeps backend upload/write controls disabled without mutating tickets, events, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service complaint evidence upload implementation gate rule: run `php yii customer-service-complaint-evidence-upload-implementation-gate/run --fixture=1 --interactive=0` before adding backend complaint evidence upload controls. It verifies the storage root stays outside the web root, storage keys are sha256/date/ticket based, evidence_json and audit metadata contracts are valid, cleanup patterns are scoped to generated fixture/tmp evidence paths, delivery-index evidence `mongoyia-customer-service-complaint-evidence-upload-implementation-gate-*.md`, and backend upload/write controls remain disabled without mutating tickets, events, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service complaint evidence upload cleanup readiness rule: run `php yii customer-service-complaint-evidence-upload-cleanup-readiness/run --fixture=1 --interactive=0` before enabling backend complaint evidence upload or cleanup controls. It verifies dry-run-first cleanup, explicit future `COMPLAINT_EVIDENCE_CLEANUP_APPLY` guard, fixture/tmp-only cleanup scope, reviewed evidence and handover-report exclusions, delivery-index evidence `mongoyia-customer-service-complaint-evidence-upload-cleanup-readiness-*.md`, and disabled backend upload/write controls without creating directories, deleting files, or mutating tickets, events, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service complaint evidence upload enablement gate rule: run `php yii customer-service-complaint-evidence-upload-enablement-gate/run --fixture=1 --interactive=0` before exposing backend complaint evidence upload controls. It verifies the future `/mall/kf/complaint-evidence-upload` permission contract, disabled backend upload UI marker, absent upload action/file input, audit event contract, rollback cleanup contract, delivery-index evidence `mongoyia-customer-service-complaint-evidence-upload-enablement-gate-*.md`, and precondition chain without creating directories, deleting files, uploading files, or mutating tickets, events, orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service complaint evidence apply workflow rule: run `php yii customer-service-complaint-evidence-apply-workflow/run --fixture=1 --interactive=0` before any reviewed complaint evidence JSON writeback. Real writes require `--apply=1 --confirmApply=COMPLAINT_EVIDENCE_APPLY`; the fixture verifies dry-run no-mutation behavior, one audited apply event, unchanged ticket status, store-scope blocking, invalid JSON blocking, repeated-evidence blocking, delivery-index evidence `mongoyia-customer-service-complaint-evidence-apply-workflow-*.md`, and disabled backend write controls without mutating orders, payments, chats, IM, files, shipments, funds, or statistics.
Customer-service resolution export rule: run `php yii customer-service-resolution-export/run --fixture=1 --interactive=0` before using backend customer-service resolution/result CSV exports. It verifies the `/mall/kf/resolution-export` permission, backend export link markers, read-only Markdown/CSV resolution evidence generation, platform/seller store-scope totals, resolved/closed status filtering, status-change event counts, result-field presence, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, statistics, or ticket result fields.
Customer-service SLA readiness rule: run `php yii customer-service-sla-readiness/run --fixture=1 --interactive=0` before using backend customer-service SLA CSV exports. It verifies the `/mall/kf/sla-readiness` permission, backend export link markers, read-only Markdown/CSV SLA evidence generation, platform/seller store-scope totals, first-response breach counts, resolution breach counts, missing result counts, and rollback-clean fixtures without mutating tickets, orders, payments, chats, IM, files, shipments, funds, statistics, or ticket SLA/result fields.
Customer-service SLA handling rule: run `php yii customer-service-sla-handling/run --fixture=1 --interactive=0` before using backend customer-service SLA handling suggestion exports. It verifies the `/mall/kf/sla-handling` permission, backend export link markers, read-only Markdown/CSV dry-run evidence generation, platform/seller store-scope totals, first-response overdue, resolution overdue, result-writeback-required, watch-window, and no-action buckets with rollback-clean fixtures. It does not mutate tickets, orders, payments, chats, IM, files, shipments, funds, statistics, or run automatic SLA handling.
Customer-service result signoff rule: run `php yii customer-service-result-signoff/run --fixture=1 --interactive=0` before using backend customer-service result writeback signoff plans. It verifies the `/mall/kf/result-signoff` permission, backend export link markers, read-only Markdown/CSV result signoff evidence generation, platform/seller store-scope totals, ready/missing/premature/continue buckets, and rollback-clean fixtures without mutating tickets, results, orders, payments, chats, IM, files, shipments, funds, or statistics.
PWA offline/install readiness rule: run `php yii mongoyia-pwa-offline-readiness/run --baseUrl=<base-url> --interactive=0` after PWA smoke to confirm service-worker versioned cache naming, cache boundaries, offline fallback navigation, response guards, lifecycle cleanup, and self-contained fallback HTML.
PWA visual QA readiness rule: run `php yii mongoyia-pwa-visual-qa/run --baseUrl=<base-url> --interactive=0` after PWA smoke to generate public route checks, latest mobile UI evidence coverage, and a screenshot capture checklist. Use `--screenshotsDir=<dir> --requireScreenshots=1` when browser-captured PNG files should be mandatory; strict screenshot evidence validates PNG signature, planned viewport width or scrollbar-adjusted content width, minimum viewport height, and minimum byte size.

IM media readiness rule: run `php yii mongoyia-im-media-readiness/run --baseUrl=<base-url> --interactive=0` before IM media signoff to confirm image-only upload guards, frontend/backend image controls, reserved file/video/voice controls are not advertised, Python IM payload boundaries, HTTP upload/rejection samples, reserved PDF/MP4/MP3 rejection, versioned future media contract coverage from `docs/mongoyia-im-media-contract.md`, media policy reporting, and smoke upload cleanup. This does not replace public WSS evidence or add file/video/voice transport.
IM media transport implementation gate rule: run `php yii mongoyia-im-media-transport-implementation-gate/run --fixture=1 --interactive=0` before implementing file/video/voice IM transport. It verifies `ImMediaTransportImplementationGateService`, the versioned `MONGOYIA_IM_MEDIA_TRANSPORT_IMPLEMENTATION_GATE_V1` contract, current runtime rejection of `msg_type=3/4/5`, hidden frontend/backend file/video/voice controls, Python payload rejection boundary, regression coverage markers, delivery-index evidence `mongoyia-im-media-transport-implementation-gate-*.md`, and unchanged message/order/payment/fund tables without enabling file/video/voice UI or transport.
IM media transport policy gate rule: run `php yii mongoyia-im-media-transport-policy-gate/run --fixture=1 --interactive=0` before enabling file/video/voice IM transport. It verifies `ImMediaTransportPolicyGateService`, the versioned `MONGOYIA_IM_MEDIA_TRANSPORT_POLICY_GATE_V1` contract, fixed file/video/voice size limits, extension/MIME/signature rules, storage paths, cleanup prefixes, feature-flag/permission/rollback rules, delivery-index evidence `mongoyia-im-media-transport-policy-gate-*.md`, and unchanged message/order/payment/fund tables without enabling `msg_type=3/4/5` or exposing file/video/voice controls.
IM media upload skeleton gate rule: run `php yii mongoyia-im-media-upload-skeleton-gate/run --baseUrl=<base-url> --fixture=1 --interactive=0` before implementing live file/video/voice uploads. It verifies `ImMediaUploadSkeletonService`, `MONGOYIA_IM_MEDIA_UPLOAD_SKELETON_V1`, `CHAT_MEDIA_UPLOAD_URL=/mall/chat/media-upload`, `IM_FILE_VIDEO_VOICE_ENABLED=false`, disabled JSON responses for file/video/voice, disabled-by-default extension/MIME/body-signature validation helper samples, storage preflight outside public web root, cleanup dry-run guarded by `IM_MEDIA_UPLOAD_CLEANUP_APPLY`, enablement precondition gating for frontend/backend controls and write permissions, delivery-index evidence `mongoyia-im-media-upload-skeleton-gate-*.md`, and unchanged message/order/payment/fund tables without saving uploads, creating directories, deleting files, enabling `msg_type=3/4/5`, or exposing file/video/voice controls.

Payment sandbox evidence rule: run `mongoyia-payment-sandbox-evidence` after QPay/LianLian sandbox callbacks are reviewed. Store only non-sensitive ticket IDs or screenshot references; never write provider secrets, private keys, callback HMAC secrets, auth headers, or real `.env` values.

IM WSS evidence rule: run `mongoyia-im-wss-evidence` after public-domain WSS healthcheck, chat regression, concurrency, reverse-proxy, TLS, and service-manager checks are reviewed. Store only non-sensitive ticket/config references; never write IM secrets, DB passwords, SSH keys, or real `.env` values.

Mongolian review evidence rule: run `mongoyia-mongolian-review-evidence` after native/business reviewers complete the CSV review/import/audit loop and embedded-image text review. Store only reviewer, ticket, or sheet references; do not write customer data, credentials, or secrets.

## Test Server Acceptance

Windows:

```powershell
.\console\shell\mongoyia-acceptance.ps1 `
  -BaseUrl "https://<test-domain>" `
  -Profile test `
  -Strict `
  -CleanupAfterRun `
  -ImUrl "wss://<test-domain>/<im-path>"
```

Linux:

```bash
PROFILE=test \
STRICT=1 \
CLEANUP_AFTER_RUN=1 \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
sh console/shell/mongoyia-acceptance.sh
```

Final one-command handover:

```bash
PROFILE=test \
STRICT=1 \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
TESTER=<tester> \
NOTES=test-server \
sh console/shell/mongoyia-final-handover.sh
```

Archive handover files:

```bash
powershell -ExecutionPolicy Bypass -File console/shell/mongoyia-archive-handover.ps1
```

Export source handover files:

```powershell
.\console\shell\mongoyia-source-diff-export.ps1
.\console\shell\mongoyia-untracked-source-export.ps1
.\console\shell\mongoyia-validate-untracked-source.ps1
.\console\shell\mongoyia-source-handover-archive.ps1
.\console\shell\mongoyia-validate-source-handover.ps1
.\console\shell\mongoyia-sql-dump-manifest.ps1 -SqlDumpPath "..\..\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql"
.\console\shell\mongoyia-env-redacted-report.ps1 -Profile test
.\console\shell\mongoyia-test-server-input-gate.ps1 -BaseUrl "https://<test-domain>" -ImUrl "wss://<test-domain>/<im-path>" -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" -SqlDumpPath "<dump.sql>" -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" -Database outer -BackupReference "snapshot-or-ticket-id" -RequireRestoreInputs
.\console\shell\mongoyia-p2-readiness.ps1 -BaseUrl "https://<test-domain>" -ImUrl "wss://<test-domain>/<im-path>" -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" -SqlDumpPath "<dump.sql>" -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" -Database outer -BackupReference "snapshot-or-ticket-id" -RequireExternalInputs
.\console\shell\mongoyia-payment-sandbox-evidence.ps1 -BaseUrl "https://<test-domain>" -QpaySignoff PASS -QpayReference "ticket-or-screenshot-id" -LianlianSignoff PASS -LianlianReference "ticket-or-screenshot-id" -FailOnPending
.\console\shell\mongoyia-im-wss-evidence.ps1 -ImUrl "wss://<test-domain>/<im-path>" -WssSignoff PASS -ReverseProxyReference "ticket-or-config-reference" -TlsReference "certificate-ticket-reference" -ServiceManagerReference "systemd-or-supervisor-reference" -FailOnPending
.\console\shell\mongoyia-mongolian-review-evidence.ps1 -Reviewer "name-or-ticket" -ReviewSignoff PASS -ImageTextSignoff PASS -RemainingRiskReference "ticket-or-sheet-reference" -FailOnPending
.\console\shell\mongoyia-production-load-test-evidence.ps1 -LoadTestReference "report-or-ticket" -BrowsingSignoff PASS -CheckoutSignoff PASS -PaymentCallbackSignoff PASS -ImConcurrencySignoff PASS -DataStoreSignoff PASS -RollbackMonitoringSignoff PASS -Tester "owner-or-team" -FailOnPending
php yii mongoyia-production-scheduled-check-evidence/run --schedulerSignoff=PASS --alertSignoff=PASS --schedulerReference="task-or-cron-reference" --alertReference="alert-route-or-ticket" --operator="owner-or-team" --interactive=0
php yii mongoyia-production-evidence-summary/run --failOnPending=1 --interactive=0
php yii mongoyia-production-external-evidence-import-dry-run/run --fixture=1 --interactive=0
php yii mongoyia-production-external-evidence-review-readiness/run --fixture=1 --interactive=0
php yii mongoyia-production-external-evidence-review-result-apply-gate/run --fixture=1 --interactive=0
php yii mongoyia-production-external-evidence-final-acceptance-gate/run --fixture=1 --interactive=0
php yii mongoyia-production-launch-signoff-readiness-gate/run --fixture=1 --interactive=0
.\console\shell\mongoyia-production-go-live-gate.ps1 -BusinessSignoff PASS -PaymentProductionSignoff PASS -SettlementSignoff PASS -MonitoringAlertSignoff PASS -BackupRestoreDrillSignoff PASS -RollbackOwnerSignoff PASS -SecuritySignoff PASS -LaunchWindowSignoff PASS -ApproverReference "owner-or-ticket" -ChangeTicket "change-ticket-id" -FailOnPending
.\console\shell\mongoyia-p2-evidence-pack.ps1 -FailOnPending
.\console\shell\mongoyia-handoff-status.ps1
.\console\shell\mongoyia-test-server-delivery-archive.ps1
.\console\shell\mongoyia-validate-test-server-delivery.ps1
.\console\shell\mongoyia-test-server-receiver.ps1 -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip"
.\console\shell\mongoyia-test-server-restore-plan.ps1 -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" -SqlDumpPath "<dump.sql>" -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" -Database outer -BaseUrl "https://<test-domain>" -ImUrl "wss://<test-domain>/<im-path>" -BackupReference "snapshot-or-ticket-id"
.\console\shell\mongoyia-test-server-restore.ps1 -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" -SqlDumpPath "<dump.sql>" -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" -Database outer -BaseUrl "https://<test-domain>" -ImUrl "wss://<test-domain>/<im-path>" -RunReceiver -RunMigrate -RunPreflight
```

Expected final result: all acceptance steps pass, generated test data is cleaned, cleanup verification reports zero pending generated rows/files, and the archive wrapper prints `Archive validation: PASS`.

## Core Acceptance Commands

```bash
php yii deploy-check/run --profile=test --strict=1 --interactive=0
php yii mongoyia-package-check/run --interactive=0
php yii mongoyia-security-scan/run --strict=1 --interactive=0
php yii mongoyia-acceptance-fixture/run --apply=1 --interactive=0
php yii mongoyia-host-cleanup/run --interactive=0
php yii mongoyia-catalog-cleanup/run --interactive=0
php yii mongoyia-data-readiness/run --interactive=0
php yii mongoyia-catalog-readiness/run --interactive=0
php yii mongoyia-translation-audit/run --interactive=0
php yii mongoyia-translation-readiness/run --strict=1 --interactive=0
php yii mongoyia-order-integrity/run --interactive=0
php yii mongoyia-payment-audit/run --interactive=0
php yii mongoyia-payment-audit-backfill/run --interactive=0
php yii mongoyia-payment-callback-readiness/run --baseUrl=http://127.0.0.1:8089 --profile=local --interactive=0
php yii payment-provider-readiness/run --baseUrl=http://127.0.0.1:8089 --profile=local --interactive=0
php yii payment-provider-route-skeleton-gate/run --fixture=1 --interactive=0
php yii payment-provider-webhook-dry-run-gate/run --fixture=1 --interactive=0
php yii payment-provider-webhook-verification-dry-run-gate/run --fixture=1 --interactive=0
php yii payment-provider-webhook-audit-dry-run/run --fixture=1 --interactive=0
php yii mongoyia-im-media-readiness/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii customer-service-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii customer-service-advanced-readiness/run --baseUrl=http://127.0.0.1:8089 --profile=local --interactive=0
php yii customer-service-ticket-readonly-test/run --interactive=0
php yii customer-service-ticket-create-test/run --interactive=0
php yii customer-service-ticket-note-test/run --interactive=0
php yii customer-service-ticket-result-test/run --interactive=0
php yii customer-service-ticket-assign-test/run --interactive=0
php yii customer-service-ticket-workflow-test/run --interactive=0
php yii customer-service-stat-export/run --fixture=1 --interactive=0
php yii customer-service-stat-widget-readiness/run --fixture=1 --interactive=0
php yii customer-service-stat-apply-gate/run --fixture=1 --interactive=0
php yii customer-service-stat-apply-workflow/run --fixture=1 --interactive=0
php yii customer-service-complaint-export/run --fixture=1 --interactive=0
php yii customer-service-complaint-evidence-gate/run --fixture=1 --interactive=0
php yii customer-service-resolution-export/run --fixture=1 --interactive=0
php yii customer-service-sla-readiness/run --fixture=1 --interactive=0
php yii customer-service-sla-handling/run --fixture=1 --interactive=0
php yii customer-service-result-signoff/run --fixture=1 --interactive=0
php yii pwa-smoke-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii mongoyia-pwa-offline-readiness/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii mongoyia-pwa-visual-qa/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii merchant-onboarding-test/run --interactive=0
php yii product-audit-test/run --interactive=0
php yii merchant-stat-test/run --interactive=0
php yii merchant-backend-closure-test/run --interactive=0
php yii store-profile-test/run --interactive=0
php yii mongoyia-web-closure-fixture/run --apply=1 --interactive=0
php yii mongoyia-web-closure-test/run --baseUrl=https://<test-domain> --interactive=0
php yii mongoyia-coupon-test/run --interactive=0
php yii mongoyia-favorite-review-test/run --baseUrl=https://<test-domain> --interactive=0
php yii mongoyia-logistics-basic-test/run --interactive=0
php yii mongoyia-stat-readiness/run --interactive=0
php yii api-smoke-test/run --baseUrl=https://<test-domain> --interactive=0
php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --interactive=0
php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

Focused translation checks:

```bash
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=product --ids=90,102 --fields=name,brief --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=category --ids=94,106 --fields=name,brief --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=category --ids=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --fields=name --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=product --fields=name,brief --dryRun=1 --preview=1 --failOnBadPreview=1 --interactive=0
php yii mongoyia-translation-audit/run --interactive=0
php yii mongoyia-translation-readiness/run --strict=1 --productIds=90,102 --categoryIds=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --interactive=0
```

Current focused translation baseline: products `90/102`, focused categories `94/106`, and homepage core categories `93-114` pass en/mn readiness. Broader product/category coverage still needs a controlled batch run plus Mongolian manual review.

Use `docs/mongoyia-mongolian-review-workflow.md` to export the Mongolian review CSV, dry-run reviewed corrections, import approved rows, and rerun audit/readiness after human signoff.
Use `docs/mongoyia-mongolian-review-evidence.md` to record the non-sensitive reviewer signoff evidence for production readiness.

Run `php yii mongoyia-host-cleanup/run --apply=1 --interactive=0` after reviewing dry-run output if restored `fb_store.host_name` rows still contain old handover domains or platform domains on non-platform stores.

Run `php yii mongoyia-catalog-cleanup/run --apply=1 --interactive=0` after reviewing dry-run output if active categories point to missing or inactive parents. Zero-price products are reported only and still require a business pricing/deactivation decision.

## Acceptance Chain

`mongoyia-acceptance/run` executes:

1. Deployment configuration check.
2. Handover package check.
3. Security/hardcode scan.
4. Acceptance fixture preparation.
5. Data-readiness check.
6. Catalog-readiness check.
7. Translation dirty-data audit.
8. Translation-readiness check.
9. Order-integrity check.
10. Payment-audit check.
11. Merchant onboarding Phase 2 closure check.
12. Product audit Phase 2 closure check.
13. Merchant statistics Phase 2 closure check.
14. IM healthcheck.
15. IM chat regression.
16. IM concurrency regression.
17. IM media upload readiness.
18. API smoke test.
19. Frontend smoke test.
20. Backend smoke test.
21. Web closure fixture preparation for temporary `WEBFIX-*` order samples.
22. Web closure smoke test.
23. Coupon closure check.
24. Favorite/review closure check.
25. Logistics basic closure check.
26. Statistics readiness check.
27. Payment callback regression.
28. Payment provider Phase 6 readiness.
29. PayPal route skeleton gate Phase 6 closure.
30. PayPal webhook dry-run gate Phase 6 closure.
31. PayPal webhook verification dry-run gate Phase 6 closure.
32. PayPal webhook audit dry-run gate Phase 6 closure.
33. PayPal sandbox evidence gate Phase 6 closure.
34. PayPal live audit write implementation gate Phase 6 closure.
35. PayPal sandbox evidence signoff gate Phase 6 closure.
36. PayPal sandbox evidence manifest validator Phase 6 closure.
37. PayPal sandbox evidence redaction checklist Phase 6 closure.
38. PayPal sandbox evidence bundle review readiness Phase 6 closure.
39. PayPal sandbox evidence bundle review signoff gate Phase 6 closure.
40. PayPal sandbox evidence signoff import dry-run Phase 6 closure.
41. PayPal sandbox evidence review-result apply gate Phase 6 closure.
42. PayPal external evidence collection gate Phase 6 closure.
43. PayPal external evidence manifest import dry-run Phase 6 closure.
44. PayPal external evidence manifest review readiness Phase 6 closure.
45. PayPal external evidence manifest review signoff import dry-run Phase 6 closure.
46. PayPal external evidence manifest review-result apply gate Phase 6 closure.
47. PayPal live provider implementation evidence dry-run Phase 6 closure.
48. PayPal live provider implementation evidence signoff gate Phase 6 closure.
49. PayPal live execution evidence readiness gate Phase 6 closure.
50. PayPal live execution evidence signoff import dry-run Phase 6 closure.
51. PayPal live verification enablement gate Phase 6 closure.
52. PayPal final go/no-go gate Phase 6 closure.
53. Customer-service Phase 6 readiness.
54. Advanced customer-service Phase 6 readiness.
55. Customer-service ticket readonly/backend create/backend note/backend result/backend assign/backend workflow and stat/stat-widget/stat-apply-gate/stat-apply-workflow/stat-apply-log-review/complaint/complaint-evidence/resolution/SLA/SLA-handling/result-signoff export Phase 6 closure.
56. Optional generated test-data cleanup.
57. Optional generated test-data cleanup verification.

The translation dirty-data audit runs before readiness in acceptance. By default it records same-as-source or content QA warnings without failing acceptance; pass `--translationAuditStrict=1` when those warnings should block signoff.

Reports are written to `runtime/acceptance/` and start with a `Signoff Summary` table.
Run `php yii mongoyia-signoff/run --interactive=0` after acceptance to generate `runtime/acceptance/mongoyia-signoff-*.md`.
Run `php yii mongoyia-risk-register/run --interactive=0` to generate `runtime/acceptance/mongoyia-risk-register-*.md`.
Run `php yii mongoyia-delivery-index/run --interactive=0` to generate a final `runtime/acceptance/mongoyia-delivery-index-*.md` handover index. The index auto-references the latest `runtime/handover/mongoyia-pwa-mobile-ui-evidence-*.md`, `runtime/handover/mongoyia-pwa-offline-readiness-*.md`, `runtime/handover/mongoyia-pwa-visual-qa-*.md`, `runtime/handover/mongoyia-payment-callback-readiness-*.md`, `runtime/handover/mongoyia-payment-provider-readiness-*.md`, `runtime/handover/mongoyia-payment-provider-route-skeleton-gate-*.md`, `runtime/handover/mongoyia-payment-provider-webhook-dry-run-gate-*.md`, `runtime/handover/mongoyia-payment-provider-webhook-verification-dry-run-gate-*.md`, `runtime/handover/mongoyia-payment-provider-webhook-audit-dry-run-*.md`, `runtime/handover/mongoyia-production-load-test-evidence-*.md`, `runtime/handover/mongoyia-production-external-evidence-import-dry-run-*.md`, `runtime/handover/mongoyia-production-external-evidence-review-readiness-*.md`, `runtime/handover/mongoyia-production-go-live-gate-*.md`, `runtime/handover/mongoyia-im-media-readiness-*.md`, `runtime/handover/mongoyia-customer-service-readiness-*.md`, `runtime/handover/mongoyia-customer-service-advanced-readiness-*.md`, `runtime/handover/mongoyia-customer-service-stat-export-*.md`, `runtime/handover/mongoyia-customer-service-stat-widget-readiness-*.md`, `runtime/handover/mongoyia-customer-service-stat-apply-gate-*.md`, `runtime/handover/mongoyia-customer-service-stat-apply-workflow-*.md`, `runtime/handover/mongoyia-customer-service-stat-apply-log-review-*.md`, `runtime/handover/mongoyia-customer-service-complaint-export-*.md`, `runtime/handover/mongoyia-customer-service-complaint-evidence-gate-*.md`, `runtime/handover/mongoyia-customer-service-resolution-export-*.md`, `runtime/handover/mongoyia-customer-service-sla-readiness-*.md`, `runtime/handover/mongoyia-customer-service-sla-handling-*.md`, and `runtime/handover/mongoyia-customer-service-result-signoff-*.md` reports and their results; use `--pwaEvidencePath=...`, `--pwaOfflineReadinessPath=...`, `--pwaVisualQaPath=...`, `--paymentCallbackReadinessPath=...`, `--paymentProviderReadinessPath=...`, `--paymentProviderRouteSkeletonGatePath=...`, `--paymentProviderWebhookDryRunGatePath=...`, `--paymentProviderWebhookVerificationDryRunGatePath=...`, `--paymentProviderWebhookAuditDryRunPath=...`, `--productionLoadTestEvidencePath=...`, `--productionExternalEvidenceImportDryRunPath=...`, `--productionExternalEvidenceReviewReadinessPath=...`, `--productionGoLiveGatePath=...`, `--imMediaReadinessPath=...`, `--customerServiceReadinessPath=...`, `--customerServiceAdvancedReadinessPath=...`, `--customerServiceStatExportPath=...`, `--customerServiceStatWidgetReadinessPath=...`, `--customerServiceStatApplyGatePath=...`, `--customerServiceStatApplyWorkflowPath=...`, `--customerServiceStatApplyLogReviewPath=...`, `--customerServiceComplaintExportPath=...`, `--customerServiceComplaintEvidenceGatePath=...`, `--customerServiceResolutionExportPath=...`, `--customerServiceSlaReadinessPath=...`, `--customerServiceSlaHandlingPath=...`, or `--customerServiceResultSignoffPath=...` to pin a specific evidence file.
Production evidence summary index note: `mongoyia-delivery-index/run` also auto-references `runtime/handover/mongoyia-production-health-*.md`, `runtime/handover/mongoyia-production-monitor-*.md`, `runtime/handover/mongoyia-production-backup-verify-evidence-*.md`, `runtime/handover/mongoyia-production-scheduled-check-evidence-*.md`, `runtime/handover/mongoyia-production-evidence-summary-*.md`, `runtime/handover/mongoyia-production-external-evidence-final-acceptance-gate-*.md`, and `runtime/handover/mongoyia-production-launch-signoff-readiness-gate-*.md`; use `--productionHealthPath=...`, `--productionMonitorPath=...`, `--productionBackupVerifyEvidencePath=...`, `--productionScheduledCheckEvidencePath=...`, `--productionEvidenceSummaryPath=...`, `--productionExternalEvidenceFinalAcceptanceGatePath=...`, or `--productionLaunchSignoffReadinessGatePath=...` to pin a specific report.
Record final test-server results with `docs/mongoyia-acceptance-signoff-template.md`.

## Default Acceptance Data

- Platform backend: `codex_platform_backend_test_5`
- Seller backend: `zhishichanquan`
- Platform store id: `5`
- Payment user id: `71`
- Product ids: `90,102`
- IM merchant uid: `37`
- IM product id: `102`
- IM store id: `9`

Override these values when the test server uses different data.

## Important Generated Files And Controllers

Console controllers:

- `console/controllers/DeployCheckController.php`
- `console/controllers/MongoyiaPackageCheckController.php`
- `console/controllers/MongoyiaSecurityScanController.php`
- `console/controllers/MongoyiaAcceptanceFixtureController.php`
- `console/controllers/MongoyiaDataReadinessController.php`
- `console/controllers/MongoyiaCatalogReadinessController.php`
- `console/controllers/MongoyiaTranslationAuditController.php`
- `console/controllers/MongoyiaTranslationReadinessController.php`
- `console/controllers/MongoyiaOrderIntegrityController.php`
- `console/controllers/MongoyiaPaymentAuditController.php`
- `console/controllers/MongoyiaPaymentAuditBackfillController.php`
- `console/controllers/MongoyiaPaymentCallbackReadinessController.php`
- `console/controllers/PaymentProviderReadinessController.php`
- `console/controllers/PaymentProviderRouteSkeletonGateController.php`
- `console/controllers/PaymentProviderWebhookDryRunGateController.php`
- `console/controllers/PaymentProviderWebhookVerificationDryRunGateController.php`
- `console/controllers/PaymentProviderWebhookAuditDryRunController.php`
- `console/controllers/MongoyiaImMediaReadinessController.php`
- `console/controllers/MongoyiaWebClosureFixtureController.php`
- `console/controllers/MongoyiaWebClosureTestController.php`
- `console/controllers/MongoyiaCouponTestController.php`
- `console/controllers/MongoyiaFavoriteReviewTestController.php`
- `console/controllers/MongoyiaLogisticsBasicTestController.php`
- `console/controllers/MongoyiaStatReadinessController.php`
- `console/controllers/MerchantOnboardingTestController.php`
- `console/controllers/ProductAuditTestController.php`
- `console/controllers/MerchantStatTestController.php`
- `console/controllers/PwaSmokeTestController.php`
- `console/controllers/MongoyiaPwaOfflineReadinessController.php`
- `console/controllers/MongoyiaPwaVisualQaController.php`
- `console/controllers/MongoyiaAcceptanceController.php`
- `console/controllers/MongoyiaSignoffController.php`
- `console/controllers/MongoyiaDeliveryIndexController.php`
- `console/controllers/MongoyiaRiskRegisterController.php`
- `console/controllers/MongoyiaTestCleanupController.php`
- `console/controllers/MongoyiaHostCleanupController.php`
- `console/controllers/MongoyiaCatalogCleanupController.php`
- `console/controllers/ApiSmokeTestController.php`
- `console/controllers/MallSmokeTestController.php`
- `console/controllers/BackendSmokeTestController.php`
- `console/controllers/MallPaymentTestController.php`
- `console/controllers/MallTranslateController.php`

Wrapper scripts:

- `console/shell/mongoyia-acceptance.ps1`
- `console/shell/mongoyia-acceptance.sh`
- `console/shell/mongoyia-test-profile-preflight.ps1`
- `console/shell/mongoyia-test-profile-preflight.sh`
- `console/shell/mongoyia-test-server-dry-run.ps1`
- `console/shell/mongoyia-test-server-dry-run.sh`
- `console/shell/mongoyia-test-server-preflight-report.ps1`
- `console/shell/mongoyia-test-server-preflight-report.sh`
- `console/shell/mongoyia-test-server-go-no-go.ps1`
- `console/shell/mongoyia-test-server-go-no-go.sh`
- `console/shell/mongoyia-test-server-go-no-go-smoke.ps1`
- `console/shell/mongoyia-test-server-go-no-go-smoke.sh`
- `console/shell/mongoyia-test-server-receiver.ps1`
- `console/shell/mongoyia-test-server-receiver.sh`
- `console/shell/mongoyia-test-server-restore.ps1`
- `console/shell/mongoyia-test-server-restore.sh`
- `console/shell/mongoyia-test-server-restore-plan.ps1`
- `console/shell/mongoyia-test-server-restore-plan.sh`
- `console/shell/mongoyia-test-server-input-gate.ps1`
- `console/shell/mongoyia-test-server-input-gate.sh`
- `console/shell/mongoyia-test-server-input-gate-smoke.ps1`
- `console/shell/mongoyia-test-server-input-gate-smoke.sh`
- `console/shell/mongoyia-sql-dump-manifest.ps1`
- `console/shell/mongoyia-sql-dump-manifest.sh`
- `console/shell/mongoyia-env-redacted-report.ps1`
- `console/shell/mongoyia-env-redacted-report.sh`
- `console/shell/mongoyia-handoff-status.ps1`
- `console/shell/mongoyia-handoff-status.sh`
- `console/shell/mongoyia-p2-readiness.ps1`
- `console/shell/mongoyia-p2-readiness.sh`
- `console/shell/mongoyia-p2-evidence-pack.ps1`
- `console/shell/mongoyia-p2-evidence-pack.sh`
- `console/shell/mongoyia-payment-sandbox-evidence.ps1`
- `console/shell/mongoyia-payment-sandbox-evidence.sh`
- `console/shell/mongoyia-im-wss-evidence.ps1`
- `console/shell/mongoyia-im-wss-evidence.sh`
- `console/shell/mongoyia-mongolian-review-evidence.ps1`
- `console/shell/mongoyia-mongolian-review-evidence.sh`
- `console/shell/mongoyia-test-server-delivery-archive.ps1`
- `console/shell/mongoyia-test-server-delivery-archive.sh`
- `console/shell/mongoyia-validate-test-server-delivery.ps1`
- `console/shell/mongoyia-validate-test-server-delivery.sh`
- `console/shell/mongoyia-final-handover.ps1`
- `console/shell/mongoyia-final-handover.sh`
- `console/shell/mongoyia-archive-handover.ps1`
- `console/shell/mongoyia-archive-handover.sh`
- `console/shell/mongoyia-validate-handover-archive.ps1`
- `console/shell/mongoyia-validate-handover-archive.sh`
- `console/shell/mongoyia-handover-verify.ps1`
- `console/shell/mongoyia-handover-verify.sh`
- `console/shell/mongoyia-worktree-inventory.ps1`
- `console/shell/mongoyia-worktree-inventory.sh`
- `console/shell/mongoyia-source-diff-export.ps1`
- `console/shell/mongoyia-source-diff-export.sh`
- `console/shell/mongoyia-untracked-source-export.ps1`
- `console/shell/mongoyia-untracked-source-export.sh`
- `console/shell/mongoyia-validate-untracked-source.ps1`
- `console/shell/mongoyia-validate-untracked-source.sh`
- `console/shell/mongoyia-source-handover-archive.ps1`
- `console/shell/mongoyia-source-handover-archive.sh`
- `console/shell/mongoyia-validate-source-handover.ps1`
- `console/shell/mongoyia-validate-source-handover.sh`

Required docs:

- `docs/mongoyia-test-server-run-sheet.md`
- `docs/mongoyia-test-server-runbook.md`
- `docs/mongoyia-test-server-receiver.md`
- `docs/mongoyia-test-server-inputs.md`

IM scripts:

- `im后端/im后端/scripts/start-im.ps1`
- `im后端/im后端/scripts/stop-im.ps1`
- `im后端/im后端/scripts/status-im.ps1`
- `im后端/im后端/scripts/im-healthcheck.py`
- `im后端/im后端/scripts/im-regression.py`
- `im后端/im后端/scripts/im-concurrency.py`

## Current Scope Boundary

This package is prepared for test-server acceptance. It is not a final production launch package.

Before production launch, continue with payment provider confirmation, production security, monitoring, backups, reconciliation, settlement, full translation QA, IM load tests beyond the lightweight concurrency regression, and manual business acceptance.
