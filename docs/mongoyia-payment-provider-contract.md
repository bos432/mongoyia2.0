# Mongoyia Payment Provider Contract

Contract version: 2026-06-19-payment-provider-v1

MONGOYIA_PAYMENT_PROVIDER_CONTRACT_V1

This document defines the current payment-provider boundary for Phase 6. It is a readiness artifact only. No runtime enablement is included for PayPal, and current QPay/LianLian callback URLs, order-state semantics, callback signatures, and payment-attempt audit behavior must remain unchanged.

## Current Runtime

| Provider | State | Runtime routes | Callback gate |
|---|---|---|---|
| QPay | Enabled when configured | `/mall/payment/qpay`, `/mall/payment/qpayres` | Amount, merchant transaction, success status, duplicate callback idempotency, optional callback secret, optional HMAC, optional max-age, optional IP allowlist. |
| LianLian | Enabled when configured | `/mall/payment/lianlian`, `/mall/payment/succeeded` | Amount, merchant transaction, success status, duplicate callback idempotency, optional callback secret, optional HMAC, optional max-age, optional IP allowlist. |
| PayPal | Reserved / disabled | No runtime route | No runtime webhook handler. |

## Future PayPal Contract

MONGOYIA_PAYPAL_PROVIDER_RESERVED_V1

MONGOYIA_PAYPAL_RUNTIME_CONTRACT_V1

MONGOYIA_PAYPAL_ROUTE_SKELETON_GATE_V1

MONGOYIA_PAYPAL_WEBHOOK_DRY_RUN_GATE_V1

MONGOYIA_PAYPAL_WEBHOOK_VERIFICATION_DRY_RUN_GATE_V1

MONGOYIA_PAYPAL_WEBHOOK_AUDIT_DRY_RUN_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1

MONGOYIA_PAYPAL_LIVE_AUDIT_WRITE_IMPLEMENTATION_GATE_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1

MONGOYIA_PAYPAL_LIVE_VERIFICATION_ENABLEMENT_GATE_V1

MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1

| Item | Contract |
|---|---|
| Enable flag | `PAYPAL_ENABLED=false` until the implementation gate is complete. |
| Sandbox flag | `PAYPAL_SANDBOX=true` for test profile. |
| Credentials | `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`. |
| Callback base | `PAYPAL_CALLBACK_BASE`, same HTTPS host as `STORE_PLATFORM_DOMAIN` on test/prod. |
| Return path | `/mall/payment/paypal-return`. |
| Cancel path | `/mall/payment/paypal-cancel`. |
| Webhook path | `/mall/payment/paypal-webhook`. |
| Local HMAC guard | `PAYPAL_WEBHOOK_HMAC_SECRET`, used only if a local gateway-facing HMAC shim is added. |
| Currency | `PAYPAL_CURRENCY=USD` unless business signs off another settlement currency. |

## Runtime Contract Gate

`common/services/mall/PaypalRuntimeContractService.php` records the disabled runtime contract used by `payment-provider-readiness/run`.

- Four route handlers exist and are disabled by default: `/mall/payment/paypal`, `/mall/payment/paypal-return`, `/mall/payment/paypal-cancel`, and `/mall/payment/paypal-webhook`.
- With `PAYPAL_ENABLED=false`, each handler returns a JSON `PAYPAL_DISABLED` response with HTTP 404 and performs no provider call or business-data mutation.
- Webhook verification must account for `PAYPAL-AUTH-ALGO`, `PAYPAL-CERT-URL`, `PAYPAL-TRANSMISSION-ID`, `PAYPAL-TRANSMISSION-SIG`, and `PAYPAL-TRANSMISSION-TIME`.
- Required webhook environment keys remain `PAYPAL_WEBHOOK_ID` and `PAYPAL_WEBHOOK_HMAC_SECRET`.
- Verification modes are limited to PayPal's official webhook verification API or a reviewed local HMAC shim for test callbacks only.
- Disabled route handlers are the only satisfied precondition; signature verification, payment-attempt audit, regression cleanup, and sandbox evidence remain explicit unsatisfied preconditions until a reviewed PayPal implementation increment lands.

## PayPal Webhook Dry-run Gate

`payment-provider-webhook-dry-run-gate/run --fixture=1` generates a read-only PayPal Webhook Dry-run Gate report using a local HMAC shim for test callbacks only.

- The gate covers valid completed callback, missing signature, invalid signature, expired timestamp, wrong webhook ID, amount mismatch, duplicate webhook event, and non-success status samples.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-webhook-dry-run-gate-*.md` and `.csv`.
- It does not call PayPal, QPay, LianLian, or any network service.
- It does not create orders, payment attempts, callback audit rows, chats, files, shipment rows, fund logs, or statistics.
- It is not a production signature implementation; live PayPal enablement still requires official PayPal webhook verification or a reviewed gateway-facing HMAC shim plus route handlers, UI controls, audit records, sandbox evidence, and cleanup.

## PayPal Webhook Verification Dry-run Gate

`payment-provider-webhook-verification-dry-run-gate/run --fixture=1` generates a read-only PayPal Webhook Verification Dry-run Gate report before any live PayPal verification request is implemented.

- It records the future official `verify-webhook-signature` request contract: required PayPal transmission headers, `PAYPAL_WEBHOOK_ID`, raw webhook body, and the expected verification response handling.
- It checks dry-run guard samples for missing transmission ID, missing certificate URL, untrusted certificate URL, unsupported algorithm, expired transmission time, valid local HMAC test shim, and invalid local HMAC test shim.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-webhook-verification-dry-run-gate-*.md` and `.csv`.
- It is contract-planning only and must not call PayPal, QPay, LianLian, or any network service.
- It must not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.
- The local HMAC shim remains limited to test callbacks and is not a production PayPal verification substitute.

## PayPal Webhook Audit Dry-run Gate

`payment-provider-webhook-audit-dry-run/run --fixture=1` generates a dry-run-only audit plan from the PayPal Webhook Dry-run Gate samples.

- It maps future `mall_payment_attempt` rows with `provider=paypal`, `event=webhook`, result `success`, `failed`, or `ignored`, merchant transaction ID, gateway event/transaction ID, business key, amount, currency, redacted payload hash, and error message.
- It expects one success audit row for the valid completed sample, one ignored audit row for the duplicate webhook sample, and failed audit rows for rejected samples.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-webhook-audit-dry-run-*.md` and `.csv`.
- It is dry-run only and must not insert, update, or delete `mall_payment_attempt` rows.
- It does not call PayPal, QPay, LianLian, or any network service, and it does not mutate orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Live Provider Implementation Evidence Dry Run

`payment-provider-paypal-live-provider-implementation-evidence-dry-run/run --fixture=1` validates the non-sensitive implementation evidence plan required before real PayPal provider runtime code can be enabled.

- Gate marker: `MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1`.
- It records `live_provider_implementation_evidence_valid=1` when the dry-run plan covers create, capture, cancel, official webhook verification, audit writes, amount/currency validation, idempotency, order state, UI rollout, cleanup, rollback, and acceptance regression evidence.
- It keeps `live_provider_implementation_evidence_applied=0`, `live_provider_implementation_ready=0`, and `paypal_enablement_allowed=0` explicit.
- It requires PASS PayPal live audit write implementation gate evidence and PASS external evidence manifest review-result apply gate evidence.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md` and `.csv`.
- It does not implement runtime PayPal provider code, expose PayPal UI, call PayPal/QPay/LianLian, write `mall_payment_attempt`, or mutate orders, callbacks, chats, files, shipments, funds, tickets, statistics, or evidence rows.

## PayPal Live Provider Implementation Evidence Signoff Gate

`payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run --fixture=1` validates the non-sensitive signoff metadata for the live provider implementation evidence plan.

- Gate marker: `MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1`.
- It requires PASS PayPal live provider implementation evidence dry-run evidence before signoff metadata can be considered ready.
- It records `implementation_evidence_signoff_ready=1` when business, security, and technical reviewer rows are present with safe references.
- It keeps `implementation_evidence_accepted=0`, `live_provider_implementation_ready=0`, and `paypal_enablement_allowed=0` explicit.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md` and `.csv`.
- It does not implement runtime PayPal provider code, accept evidence, read/copy/hash/import artifacts, expose PayPal UI, call PayPal/QPay/LianLian, write `mall_payment_attempt`, or mutate orders, callbacks, chats, files, shipments, funds, tickets, statistics, signoff rows, or evidence rows.

## PayPal Live Execution Evidence Readiness Gate

`payment-provider-paypal-live-execution-evidence-readiness-gate/run --fixture=1` validates the redacted evidence checklist needed before real PayPal sandbox execution and live production readiness evidence can be collected externally.

- Gate marker: `MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1`.
- It requires PASS PayPal live provider implementation evidence signoff gate evidence before the checklist can be considered ready.
- It records `real_sandbox_live_evidence_ready=1` when eight sandbox execution evidence references and four live production readiness references are present as safe redacted metadata.
- It keeps `evidence_collection_started=0`, `sandbox_execution_evidence_accepted=0`, `live_production_evidence_accepted=0`, and `paypal_enablement_allowed=0` explicit.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md` and `.csv`.
- It does not start collection, accept evidence, read/copy/hash/import artifacts, expose PayPal UI, call PayPal/QPay/LianLian, write `mall_payment_attempt`, or mutate orders, callbacks, chats, files, shipments, funds, tickets, statistics, signoff rows, or evidence rows.

## PayPal Live Execution Evidence Signoff Import Dry Run

`payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run --fixture=1` validates the non-sensitive reviewer signoff input template for real sandbox execution and live production readiness evidence.

- Gate marker: `MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1`.
- It requires PASS PayPal live execution evidence readiness gate evidence before signoff import metadata can be considered valid.
- It records `live_execution_signoff_input_valid=1` when business, security, technical, and ops reviewer rows are present with safe references.
- It keeps `signoff_import_applied=0`, `sandbox_execution_evidence_accepted=0`, `live_production_evidence_accepted=0`, and `paypal_enablement_allowed=0` explicit.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md` and `.csv`.
- It does not import or persist signoff rows, accept evidence, read/copy/hash/import artifacts, expose PayPal UI, call PayPal/QPay/LianLian, write `mall_payment_attempt`, or mutate orders, callbacks, chats, files, shipments, funds, tickets, statistics, signoff rows, or evidence rows.

## PayPal Live Verification Enablement Gate

`payment-provider-live-verification-enablement-gate/run --fixture=1` generates a read-only PayPal Live Verification Enablement Gate report before PayPal live verification can be enabled.

- It requires `PAYPAL_ENABLED=false`, env-template PayPal keys, absent template secret values, hidden PayPal UI, no live PayPal API URL or credential reads in `PaymentController`, and PASS evidence for webhook dry-run, webhook verification dry-run, webhook audit dry-run, external evidence manifest review-result apply, live provider implementation evidence dry-run, live provider implementation evidence signoff, live execution evidence readiness, and live execution evidence signoff import dry-run gates.
- It intentionally records `enablement_allowed=false` because PayPal sandbox evidence and live implementation evidence are still pending.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-live-verification-enablement-gate-*.md` and `.csv`.
- It does not call PayPal, QPay, LianLian, or any network service.
- It does not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Final Go/No-Go Gate

`payment-provider-paypal-final-go-no-go-gate/run --fixture=1` generates a read-only PayPal Final Go/No-Go Gate report after the live verification enablement gate.

- Gate marker: `MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1`.
- It requires a PASS PayPal live verification enablement gate report and records `Final decision: NO-GO` while `enablement_allowed=false`.
- It records `go_allowed=0`, `final_decision_no_go=1`, and the current NO-GO reasons: real sandbox/live evidence acceptance pending and runtime implementation pending.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md` and `.csv`.
- It does not enable PayPal, expose PayPal UI, import evidence, call PayPal/QPay/LianLian, write `mall_payment_attempt`, or mutate orders, callbacks, chats, files, shipments, funds, tickets, statistics, signoff rows, or evidence rows.

## PayPal Sandbox Evidence Gate

`payment-provider-paypal-sandbox-evidence-gate/run --fixture=1` generates a read-only PayPal Sandbox Evidence Gate report for future real HTTPS sandbox signoff.

- It enumerates the required non-sensitive evidence cases: sandbox credential reference, create order, approval return/capture, cancel return, completed webhook, duplicate webhook idempotency, amount mismatch rejection, invalid signature rejection, expired transmission rejection, backend payment-attempt visibility, and cleanup evidence.
- It records `sandbox_evidence_ready=0` until real PayPal sandbox evidence is attached from the test server.
- It verifies local preconditions: PayPal remains disabled, this document and `docs/mongoyia-payment-sandbox-evidence.md` carry the gate marker, dry-run evidence is PASS, PayPal UI stays hidden, and `PaymentController` still has no live PayPal provider URLs or credential reads.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md` and `.csv`.
- It does not call PayPal, QPay, LianLian, or any network service, and it does not mutate orders, payments, chats, funds, tickets, or statistics.

## PayPal Live Audit Write Implementation Gate

`payment-provider-paypal-live-audit-write-implementation-gate/run --fixture=1` generates a read-only PayPal Live Audit Write Implementation Gate report before any real PayPal audit writes are allowed.

- It records `live_audit_write_enabled=0` until official verification, sandbox evidence, UI controls, regression, and cleanup land together.
- It verifies the `PaymentAttempt` model can support future PayPal audit fields and that `PaypalWebhookAuditDryRunService` still provides eight future `provider=paypal` webhook rows: one success, six failed, and one ignored duplicate.
- It fixes the future write contract for PayPal create, return, cancel, and webhook events, including success/failed/ignored outcomes, amount/currency, business key, gateway ids/event id, redacted payload, and payload hash.
- It verifies duplicate webhook idempotency maps to `PaymentAttempt::RESULT_IGNORED`, generated-order cleanup scope exists, the PayPal sandbox evidence gate is PASS with `sandbox_evidence_ready=0`, PayPal UI remains hidden, and `PaymentController` still has no live PayPal provider URLs or credential reads.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-live-audit-write-implementation-gate-*.md` and `.csv`.
- It does not call PayPal, QPay, LianLian, or any network service, and it does not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Sandbox Evidence Signoff Gate

`payment-provider-paypal-sandbox-evidence-signoff-gate/run --fixture=1` generates a read-only PayPal Sandbox Evidence Signoff Gate report before real PayPal sandbox evidence can be accepted.

- It records `signoff_ready=0` until a real HTTPS PayPal sandbox evidence package and reviewer signoff are attached.
- It fixes the non-sensitive signoff manifest fields: `case_key`, `status`, `artifact_ref`, `artifact_sha256`, `redaction_status`, `reviewer`, `reviewed_at`, `environment_host`, and optional `notes`.
- It requires the same eleven sandbox evidence cases as the PayPal Sandbox Evidence Gate, including duplicate webhook idempotency and cleanup evidence.
- It verifies the PayPal sandbox evidence gate and PayPal live audit write implementation gate are PASS, while PayPal UI remains hidden and `PaymentController` still has no live PayPal provider URLs or credential reads.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md` and `.csv`.
- It does not import evidence artifacts, call PayPal, QPay, LianLian, or any network service, and it does not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Sandbox Evidence Manifest Validator

`payment-provider-paypal-sandbox-evidence-manifest-validator/run --fixture=1` generates a read-only PayPal Sandbox Evidence Manifest Validator report before a real evidence manifest can be reviewed.

- It records `manifest_accepted=0`; a valid manifest is format readiness only, not business signoff or PayPal enablement.
- It validates the same eleven sandbox evidence case keys as the signoff gate, exactly one row per case.
- It requires the non-sensitive manifest fields `case_key`, `status`, `artifact_ref`, `artifact_sha256`, `redaction_status`, `reviewer`, `reviewed_at`, `environment_host`, and optional `notes`.
- It rejects missing/duplicate/unknown case keys, invalid status values, non-SHA256 artifact hashes, unsafe artifact references, non-HTTPS/local hosts, and secret-like markers in manifest text.
- It verifies the PayPal sandbox evidence signoff gate is PASS while `signoff_ready=0`.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md` and `.csv`.
- It does not read, copy, import, hash, or store referenced artifacts, does not call PayPal, QPay, LianLian, or any network service, and does not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Sandbox Evidence Redaction Checklist

`payment-provider-paypal-sandbox-evidence-redaction-checklist/run --fixture=1` generates a read-only PayPal Sandbox Evidence Redaction Checklist report before any real evidence bundle can be reviewed.

- It records `evidence_bundle_accepted=0`; the checklist is policy readiness only, not business signoff or PayPal enablement.
- It validates twelve required redaction controls for credentials, OAuth tokens, authorization headers, PayPal transmission signatures, raw webhook payload PII, buyer data, merchant account identifiers, cookies/sessions, raw env files, private keys, database/Redis credentials, and internal infrastructure references.
- It requires the checklist fields `control_key`, `status`, `redaction_scope`, `required_redaction`, `allowed_evidence`, `forbidden_markers`, `reviewer`, `reviewed_at`, and optional `notes`.
- It verifies the PayPal sandbox evidence manifest validator is PASS while `manifest_accepted=0`.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md` and `.csv`.
- It does not read, copy, hash, import, or store evidence artifacts, does not call PayPal, QPay, LianLian, or any network service, and does not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Sandbox Evidence Bundle Review Readiness

`payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run --fixture=1` generates a read-only PayPal Sandbox Evidence Bundle Review Readiness report before a sanitized external evidence bundle can enter manual review.

- It records `bundle_review_ready=1` when local review prerequisites are ready, while `evidence_bundle_accepted=0` remains explicit.
- It requires PASS reports from the PayPal sandbox evidence manifest validator and redaction checklist.
- It fixes the review checklist for sanitized manifest reference, sanitized artifact hashes, reviewer assignment, HTTPS test-host traceability, storage boundary, rejection reason contract, cleanup reference, and final signoff pending state.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-*.md` and `.csv`.
- It does not read, copy, hash, import, or store evidence artifacts, does not call PayPal, QPay, LianLian, or any network service, and does not insert, update, or delete `mall_payment_attempt` rows, orders, callbacks, chats, files, shipments, funds, tickets, or statistics.

## PayPal Sandbox Evidence Bundle Review Signoff Gate

`payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate/run --fixture=1` generates a read-only PayPal Sandbox Evidence Bundle Review Signoff Gate report before external reviewers can manually sign off a sanitized PayPal sandbox evidence bundle.

- Gate marker: `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_SIGNOFF_GATE_V1`.
- It records `bundle_review_signoff_ready=1` when the local signoff workflow is ready, while `evidence_bundle_accepted=0` and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal sandbox evidence bundle review readiness report.
- It fixes signoff slots for business, security, and technical owners, plus sanitized manifest review, redaction exception review, cleanup evidence reference, rejection/rework loop, and manual final acceptance.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate-*.md` and `.csv`.
- It does not accept evidence, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, or statistic rows.

## PayPal Sandbox Evidence Signoff Import Dry Run

`payment-provider-paypal-sandbox-evidence-signoff-import-dry-run/run --fixture=1` validates the non-sensitive external signoff input template before any PayPal evidence reviewer record can be imported.

- Gate marker: `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1`.
- It records `signoff_input_valid=1` when the sample input rows pass validation, while `signoff_import_applied=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal sandbox evidence bundle review signoff gate report.
- It validates template fields for `bundle_id`, `test_host`, `manifest_ref`, `artifact_hash_ref`, `reviewer_role`, `reviewer_ref`, `decision`, `reason`, `reviewed_at`, `cleanup_ref`, `ticket_ref`, and `notes`.
- It requires sanitized business, security, and technical reviewer rows, safe references, HTTPS test-host traceability, and SHA256-shaped artifact hash references supplied by the external sanitization process.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-signoff-import-dry-run-*.md` and `.csv`.
- It does not import or persist signoff rows, accept evidence, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, or signoff rows.

## PayPal Sandbox Evidence Review Result Apply Gate

`payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run --fixture=1` validates the sanitized review-result apply plan before any PayPal evidence review result can be persisted.

- Gate marker: `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1`.
- It records `review_result_valid=1` when the sanitized review result metadata and dry-run apply plan pass validation, while `review_result_apply_allowed=0`, `review_result_apply_executed=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal sandbox evidence signoff import dry-run report.
- It validates approved business, security, and technical review-result rows, safe references, HTTPS test-host traceability, cleanup reference, and SHA256-shaped artifact hash references.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md` and `.csv`.
- It does not apply or persist review results, accept evidence, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, or signoff rows.

## PayPal External Evidence Collection Gate

`payment-provider-paypal-external-evidence-collection-gate/run --fixture=1` validates the non-sensitive input references required before a real test-server PayPal sandbox evidence collection can be performed externally.

- Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_COLLECTION_GATE_V1`.
- It records `collection_input_valid=1` and `external_collection_ready=1` when local input references pass validation, while `external_collection_started=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal sandbox evidence review-result apply gate report.
- It validates sanitized references for the HTTPS test domain, PayPal sandbox account, callback base, checkout flow, webhook events, payment-attempt audit view, cleanup plan, sanitized manifest, and reviewer signoff.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-collection-gate-*.md` and `.csv`.
- It does not start external collection, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, or signoff rows.

## PayPal External Evidence Manifest Import Dry Run

`payment-provider-paypal-external-evidence-manifest-import-dry-run/run --fixture=1` validates the sanitized manifest rows that would be produced by external PayPal sandbox evidence collection before any manifest row can be imported.

- Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1`.
- It records `manifest_input_valid=1` when the fixture manifest rows pass validation, while `manifest_import_allowed=0`, `manifest_import_executed=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal external evidence collection gate report.
- It validates sanitized manifest rows for eleven required PayPal sandbox evidence cases, including collection reference, test host, artifact reference, SHA256-shaped artifact hash, redaction status, collector role, cleanup reference, and ticket reference.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md` and `.csv`.
- It does not import or persist manifest rows, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, or signoff rows.

## PayPal External Evidence Manifest Review Readiness

`payment-provider-paypal-external-evidence-manifest-review-readiness/run --fixture=1` validates whether the sanitized external PayPal sandbox evidence manifest is ready to enter manual business/security/technical review.

- Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_READINESS_V1`.
- It records `manifest_review_ready=1` when local readiness checks pass, while `manifest_review_started=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal external evidence manifest import dry-run report.
- It verifies nine review readiness items covering the import dry-run dependency, sanitized manifest rows, collector/reviewer roles, rejection/rework template, artifact boundary, review-result schema, cleanup traceability, and pending final acceptance.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-review-readiness-*.md` and `.csv`.
- It does not start review, accept review results, accept evidence, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review rows.

## PayPal External Evidence Manifest Review Signoff Import Dry Run

`payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run/run --fixture=1` validates the sanitized external manifest review signoff template before any reviewer decision can be imported.

- Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_SIGNOFF_IMPORT_DRY_RUN_V1`.
- It records `manifest_review_signoff_input_valid=1` when the fixture signoff rows pass validation, while `manifest_review_signoff_import_applied=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal external evidence manifest review readiness report.
- It validates three sanitized reviewer rows for business, security, and technical roles, including review id, HTTPS test host, manifest reference, SHA256-shaped artifact hash reference, decision, reason, cleanup reference, and ticket reference.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run-*.md` and `.csv`.
- It does not import or persist signoff rows, accept review results, accept evidence, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review rows.

## PayPal External Evidence Manifest Review Result Apply Gate

`payment-provider-paypal-external-evidence-manifest-review-result-apply-gate/run --fixture=1` validates the read-only apply plan for externally reviewed PayPal sandbox evidence manifest results after signoff import dry-run passes.

- Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_RESULT_APPLY_GATE_V1`.
- It records `manifest_review_result_valid=1` when sanitized review-result metadata passes validation, while `manifest_review_result_apply_allowed=0`, `manifest_review_result_apply_executed=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0` remain explicit.
- It requires a PASS PayPal external evidence manifest review signoff import dry-run report.
- It validates three approved business/security/technical review-result rows with safe review, manifest, cleanup, ticket, and SHA256-shaped artifact hash references.
- It writes Markdown/CSV evidence under `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-review-result-apply-gate-*.md` and `.csv`.
- It does not apply or persist review results, accept manifest review results, accept evidence, read/copy/hash/import/store artifacts, call PayPal/QPay/LianLian, enable PayPal UI, allow `PAYPAL_ENABLED=true`, or insert/update/delete `mall_payment_attempt`, order, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review rows.

## PayPal Route Skeleton Gate

`payment-provider-route-skeleton-gate/run --fixture=1` generates a read-only PayPal Route Skeleton Gate report for the default-disabled PayPal route handlers.

- It documents the create, return, cancel, and webhook route contracts: `/mall/payment/paypal`, `/mall/payment/paypal-return`, `/mall/payment/paypal-cancel`, and `/mall/payment/paypal-webhook`.
- It verifies that the `PaymentController` handlers are guarded by `PAYPAL_ENABLED=false` and return safe disabled JSON responses until configured.
- It defines the future `provider=paypal` payment-attempt audit fields: provider, order identifiers, amount, currency, gateway order/transaction/event IDs, result, failure reason, redacted payload hash, and received timestamp.
- It records cleanup scopes for future `PAYPALRT-*` sandbox order fixtures, `provider=paypal` payment-attempt fixtures, dry-run gateway event IDs, and generated evidence references.
- It keeps all other enablement preconditions unsatisfied until official or reviewed webhook verification, payment-attempt audit writes, UI rollout controls, sandbox regression, and cleanup/rollback evidence land together.
- It does not expose PayPal UI, call payment providers, or mutate orders/payments/chats/funds.

## Implementation Gate

PayPal can be enabled only when all items below land in the same reviewed increment:

- Disabled route handlers already exist; live enablement still requires the remaining items below in the same reviewed increment.
- Webhook verification uses PayPal's official verification API or a documented local HMAC shim for test callbacks.
- Payment attempts record `provider=paypal`, create/return/cancel/webhook events, success/failure/ignored results, amount, currency, gateway transaction/event ID, business key, redacted payloads, and payload hash.
- Parent/child order status and inventory transitions remain identical to QPay/LianLian.
- Duplicate webhooks are idempotent and audited as ignored.
- Missing signature, invalid signature, expired timestamp or verification failure, amount mismatch, wrong merchant order, and non-success statuses are rejected and audited.
- Frontend/PWA payment UI exposes a stable PayPal marker and only appears when `PAYPAL_ENABLED=true`.
- Automated readiness/regression commands cover create, return, cancel, successful webhook, duplicate webhook, amount mismatch, invalid signature, and cleanup.
- Provider sandbox evidence is recorded without secrets, hashed with `artifact_sha256`, and reviewed with `redaction_status` before test-server signoff.

## Boundary Rule

No runtime enablement: `PAYPAL_ENABLED` must stay `false`, PayPal buttons must remain hidden, and `/mall/payment/paypal*` handlers must return disabled JSON until the implementation gate is complete.
