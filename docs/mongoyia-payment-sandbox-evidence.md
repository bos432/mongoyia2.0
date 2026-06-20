# Mongoyia Payment Sandbox Evidence

This read-only evidence report is for P2 payment sandbox signoff. It does not call QPay, call LianLian, create orders, trigger callbacks, or store secrets. It only records the latest acceptance/payment regression evidence and the non-sensitive provider signoff references.

## Local Callback Readiness

Before real provider sandbox evidence is available, generate a non-sensitive local/test callback readiness report:

```bash
php yii mongoyia-payment-callback-readiness/run --baseUrl=http://127.0.0.1:8089 --profile=local --interactive=0
```

The command writes `runtime/handover/mongoyia-payment-callback-readiness-*.md`. It checks payment callback code hardening markers, env-template callback keys, latest payment regression evidence, latest PWA payment UI evidence, and pending provider sandbox signoff. It does not call QPay/LianLian, create orders, trigger callbacks, or print secrets.

## Generate Evidence

Windows:

```powershell
.\console\shell\mongoyia-payment-sandbox-evidence.ps1 `
  -BaseUrl "https://<test-domain>" `
  -QpaySignoff PASS `
  -QpayReference "ticket-or-screenshot-id" `
  -LianlianSignoff PASS `
  -LianlianReference "ticket-or-screenshot-id" `
  -FailOnPending
```

Linux:

```sh
BASE_URL=https://<test-domain> \
QPAY_SIGNOFF=PASS \
QPAY_REFERENCE=ticket-or-screenshot-id \
LIANLIAN_SIGNOFF=PASS \
LIANLIAN_REFERENCE=ticket-or-screenshot-id \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-payment-sandbox-evidence.sh
```

The script writes `runtime/handover/mongoyia-payment-sandbox-evidence-*.md`.

## Required Cases

For both QPay and LianLian, record non-sensitive evidence for:

- sandbox merchant/invoice/payment creation
- success callback
- duplicate success callback
- amount mismatch rejection
- bad or missing signature rejection
- expired timestamp rejection
- backend payment attempt visibility
- generated test data cleanup

Do not store real payment credentials, private keys, callback HMAC secrets, auth headers, raw provider payload secrets, SSH keys, or real `.env` files in the report.

## PayPal Sandbox Evidence Gate

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1

MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1

MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1

Before enabling PayPal or placing PayPal buttons in any UI, generate the local read-only gate:

```bash
php yii payment-provider-paypal-sandbox-evidence-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md`. The expected local result is `PASS` with `sandbox_evidence_ready=0`, because real PayPal sandbox evidence still requires a test HTTPS domain, PayPal sandbox credentials, and provider callback execution.

For PayPal, record non-sensitive evidence for:

- sandbox credential reference, without copying client secrets or webhook secrets
- create order or payment request
- approval return and capture success
- cancel return with unchanged order/payment state
- completed webhook with official verification result redacted
- duplicate webhook idempotency
- amount mismatch rejection
- invalid or missing transmission signature rejection
- expired transmission timestamp rejection
- backend payment-attempt visibility for success, failed, and ignored events
- generated test data cleanup

## PayPal Sandbox Evidence Signoff Gate

Before accepting real PayPal sandbox evidence, generate the read-only signoff gate:

```bash
php yii payment-provider-paypal-sandbox-evidence-signoff-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md`. The expected local result is `PASS` with `signoff_ready=0`, because the real signoff still requires sanitized artifacts from the HTTPS test domain and reviewer approval.

The future signoff manifest must include:

| Field | Required | Notes |
|---|---:|---|
| `case_key` | 1 | One of the required PayPal sandbox evidence case keys. |
| `status` | 1 | `ready`, `rejected`, or `pending_external`. |
| `artifact_ref` | 1 | Ticket id, sanitized file name, or evidence bundle reference. |
| `artifact_sha256` | 1 | SHA256 of the sanitized artifact, not a secret value. |
| `redaction_status` | 1 | Confirms secrets, auth headers, and private payload fields are redacted. |
| `reviewer` | 1 | Technical or business reviewer name/role. |
| `reviewed_at` | 1 | Review timestamp in the test-server timezone. |
| `environment_host` | 1 | HTTPS test host where evidence was collected. |
| `notes` | 0 | Non-sensitive remarks or rejection reason. |

Do not store PayPal secrets, client secrets, webhook secrets, auth headers, raw provider private payloads, SSH keys, or real `.env` files in signoff evidence.

## PayPal Sandbox Evidence Manifest Validator

Before importing or accepting any real PayPal sandbox evidence package, validate the non-sensitive manifest in dry-run mode:

```bash
php yii payment-provider-paypal-sandbox-evidence-manifest-validator/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md`. The expected local fixture result is `PASS` with `manifest_accepted=0`, because the command only validates manifest structure and redaction/hash metadata.

The validator checks:

- exactly one row for each of the eleven required PayPal sandbox evidence cases
- required fields are present
- `status` is `ready`, `rejected`, or `pending_external`
- `artifact_sha256` is a 64-character SHA256 hex digest
- `redaction_status` is `redacted`, `not_applicable`, or `rejected`
- `environment_host` is HTTPS and not localhost
- `artifact_ref` is a sanitized reference, not a local file path
- manifest text does not contain secret-like markers such as auth headers, client secrets, private keys, SSH keys, cookies, or `.env` references

The validator does not read, copy, import, hash, or store referenced artifacts. Real evidence files must remain outside the repository until they are sanitized, hashed, reviewed, and explicitly approved for archival.

## PayPal Sandbox Evidence Redaction Checklist

Before reviewing a real PayPal sandbox evidence bundle, validate the redaction checklist in dry-run mode:

```bash
php yii payment-provider-paypal-sandbox-evidence-redaction-checklist/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md`. The expected local fixture result is `PASS` with `evidence_bundle_accepted=0`, because the command validates the redaction policy checklist only and does not accept evidence artifacts.

The checklist covers:

- PayPal client secrets and OAuth tokens
- authorization and PayPal transmission signature headers
- raw webhook payload PII and buyer personal data
- merchant account identifiers
- cookies, sessions, CSRF tokens, and browser local auth data
- raw `.env` files, server credentials, private keys, database/Redis passwords, internal IPs, and absolute server paths

The checklist does not read, copy, hash, import, or store evidence artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, and does not enable PayPal UI.

## PayPal Sandbox Evidence Bundle Review Readiness

Before a sanitized PayPal sandbox evidence bundle enters manual review, generate the read-only review readiness report:

```bash
php yii payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-*.md`. The expected local fixture result is `PASS` with `bundle_review_ready=1` and `evidence_bundle_accepted=0`: the review workflow is ready, but real evidence is still external and not accepted by this command.

The review readiness gate checks that the manifest validator and redaction checklist both have PASS reports, that raw artifacts remain outside the repository, that sanitized artifact references and hashes are review-only metadata, and that final signoff remains pending external business/technical approval.

The gate does not read, copy, hash, import, or store evidence artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, and does not enable PayPal UI.

## PayPal Sandbox Evidence Bundle Review Signoff Gate

Before external reviewers manually sign off a sanitized PayPal sandbox evidence bundle, generate the read-only signoff workflow gate:

```bash
php yii payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-signoff-gate-*.md`. The expected local fixture result is `PASS` with `bundle_review_signoff_ready=1`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the signoff workflow is ready, but real evidence and reviewer signatures remain external.

Gate marker: `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_SIGNOFF_GATE_V1`.

The signoff gate checks that bundle review readiness has a PASS report, that business/security/technical signoff slots are defined, that cleanup and rejection/rework references exist, and that final acceptance remains manual.

The gate does not accept evidence, read/copy/hash/import/store evidence artifacts, call PayPal, QPay, or LianLian, write `mall_payment_attempt`, or enable PayPal UI.

## PayPal Sandbox Evidence Signoff Import Dry Run

Before any external reviewer signoff rows are imported, validate the non-sensitive input template in dry-run mode:

```bash
php yii payment-provider-paypal-sandbox-evidence-signoff-import-dry-run/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-signoff-import-dry-run-*.md`. The expected local fixture result is `PASS` with `signoff_input_valid=1`, `signoff_import_applied=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the template is valid, but no reviewer rows are persisted and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1`.

The dry-run template includes `bundle_id`, `test_host`, `manifest_ref`, `artifact_hash_ref`, `reviewer_role`, `reviewer_ref`, `decision`, `reason`, `reviewed_at`, `cleanup_ref`, `ticket_ref`, and `notes`. The fixture covers sanitized business, security, and technical reviewer rows.

The dry-run does not import or persist signoff rows, accept evidence, read/copy/hash/import/store evidence artifacts, call PayPal, QPay, or LianLian, write `mall_payment_attempt`, or enable PayPal UI.

## PayPal Sandbox Evidence Review Result Apply Gate

Before any PayPal evidence review result is persisted, validate the read-only apply gate:

```bash
php yii payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md`. The expected local fixture result is `PASS` with `review_result_valid=1`, `review_result_apply_allowed=0`, `review_result_apply_executed=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the sanitized review result metadata is valid, but no review result is applied, no evidence is accepted, and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1`.

The gate requires a PASS PayPal sandbox evidence signoff import dry-run report. It validates approved business, security, and technical review-result rows, safe references, HTTPS test-host traceability, cleanup reference, and SHA256-shaped artifact hash references.

The gate does not apply or persist review results, accept evidence, read/copy/hash/import/store evidence artifacts, call PayPal, QPay, or LianLian, write `mall_payment_attempt`, or enable PayPal UI.

## PayPal External Evidence Collection Gate

Before collecting real PayPal sandbox evidence on a test server, validate the non-sensitive collection input references:

```bash
php yii payment-provider-paypal-external-evidence-collection-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-collection-gate-*.md`. The expected local fixture result is `PASS` with `collection_input_valid=1`, `external_collection_ready=1`, `external_collection_started=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the input references are ready for an external manual collection process, but this command does not start collection, accept evidence, or enable PayPal.

Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_COLLECTION_GATE_V1`.

The gate requires a PASS PayPal sandbox evidence review-result apply gate report. It validates sanitized references for the HTTPS test domain, PayPal sandbox account, callback base, checkout flow, webhook events, payment-attempt audit view, cleanup plan, sanitized manifest, and reviewer signoff.

The gate does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, and does not enable PayPal UI.

## PayPal External Evidence Manifest Import Dry Run

After external collection produces a sanitized manifest reference, validate the manifest import input in dry-run mode:

```bash
php yii payment-provider-paypal-external-evidence-manifest-import-dry-run/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md`. The expected local fixture result is `PASS` with `manifest_input_valid=1`, `manifest_import_allowed=0`, `manifest_import_executed=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the sanitized manifest rows are valid, but no row is imported, no evidence is accepted, and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1`.

The dry-run requires a PASS PayPal external evidence collection gate report. It validates eleven sanitized case rows covering sandbox credentials, create order, approval/capture, cancel return, completed webhook, duplicate webhook idempotency, amount mismatch rejection, invalid signature rejection, expired transmission rejection, backend payment-attempt visibility, and generated test-data cleanup.

The dry-run does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, and does not enable PayPal UI.

## PayPal External Evidence Manifest Review Readiness

After the manifest import dry-run passes, validate that the sanitized manifest is ready for manual review:

```bash
php yii payment-provider-paypal-external-evidence-manifest-review-readiness/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-review-readiness-*.md`. The expected local fixture result is `PASS` with `manifest_review_ready=1`, `manifest_review_started=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the sanitized manifest can enter external manual review, but no review is started, no evidence is accepted, and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_READINESS_V1`.

The readiness gate requires a PASS PayPal external evidence manifest import dry-run report. It verifies nine review readiness items covering sanitized rows, collector/reviewer roles, rejection/rework handling, artifact access boundaries, review-result schema, cleanup traceability, and final acceptance pending.

The gate does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not start or accept a review, and does not enable PayPal UI.

## PayPal External Evidence Manifest Review Signoff Import Dry Run

After the manifest review readiness gate passes, validate the non-sensitive reviewer signoff template:

```bash
php yii payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run-*.md`. The expected local fixture result is `PASS` with `manifest_review_signoff_input_valid=1`, `manifest_review_signoff_import_applied=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the external review signoff input is valid, but no reviewer row is imported, no manifest review is accepted, no evidence is accepted, and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_SIGNOFF_IMPORT_DRY_RUN_V1`.

The dry-run requires a PASS PayPal external evidence manifest review readiness report. It validates business, security, and technical reviewer rows with safe references, HTTPS test-host traceability, SHA256-shaped artifact hash references, cleanup references, and non-sensitive reasons.

The dry-run does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not import signoff rows, and does not enable PayPal UI.

## PayPal External Evidence Manifest Review Result Apply Gate

After the manifest review signoff import dry-run passes, validate the disabled apply plan for review results:

```bash
php yii payment-provider-paypal-external-evidence-manifest-review-result-apply-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-external-evidence-manifest-review-result-apply-gate-*.md`. The expected local fixture result is `PASS` with `manifest_review_result_valid=1`, `manifest_review_result_apply_allowed=0`, `manifest_review_result_apply_executed=0`, `manifest_review_accepted=0`, `evidence_bundle_accepted=0`, and `paypal_enablement_allowed=0`: the sanitized review-result metadata is valid, but no review result is applied, no review is accepted, no evidence is accepted, and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_RESULT_APPLY_GATE_V1`.

The gate requires a PASS PayPal external evidence manifest review signoff import dry-run report. It validates business, security, and technical approval rows plus a single dry-run apply plan row with all apply, acceptance, and PayPal enablement flags false.

The gate does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not apply review results, and does not enable PayPal UI.

## PayPal Live Provider Implementation Evidence Dry Run

After external manifest review-result apply evidence passes, validate the non-sensitive implementation evidence plan:

```bash
php yii payment-provider-paypal-live-provider-implementation-evidence-dry-run/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md`. The expected local fixture result is `PASS` with `live_provider_implementation_evidence_valid=1`, `live_provider_implementation_evidence_applied=0`, `live_provider_implementation_ready=0`, and `paypal_enablement_allowed=0`: the implementation evidence plan is valid, but no runtime PayPal provider code is implemented and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1`.

The dry-run requires PASS PayPal live audit write implementation gate evidence and PASS external evidence manifest review-result apply gate evidence. It validates planned evidence rows for create order, capture return, cancel return, official webhook verification, payment-attempt writes, amount/currency checks, duplicate idempotency, order state/inventory protection, UI rollout, cleanup/rollback, and acceptance regression.

The dry-run does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not implement provider runtime code, and does not enable PayPal UI.

## PayPal Live Provider Implementation Evidence Signoff Gate

After the implementation evidence dry-run passes, validate the non-sensitive business/security/technical signoff metadata:

```bash
php yii payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md`. The expected local fixture result is `PASS` with `implementation_evidence_signoff_ready=1`, `implementation_evidence_accepted=0`, `live_provider_implementation_ready=0`, and `paypal_enablement_allowed=0`: the signoff metadata is valid, but no implementation evidence is accepted and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1`.

The signoff gate requires PASS PayPal live provider implementation evidence dry-run evidence. It validates safe references for business, security, and technical reviewer rows and keeps runtime, artifact, write, and provider-call boundaries disabled.

The signoff gate does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not implement provider runtime code, does not accept evidence, and does not enable PayPal UI.

## PayPal Live Execution Evidence Readiness Gate

After the implementation evidence signoff gate passes, validate the non-sensitive sandbox execution and live production readiness evidence checklist:

```bash
php yii payment-provider-paypal-live-execution-evidence-readiness-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md`. The expected local fixture result is `PASS` with `real_sandbox_live_evidence_ready=1`, `evidence_collection_started=0`, `sandbox_execution_evidence_accepted=0`, `live_production_evidence_accepted=0`, and `paypal_enablement_allowed=0`: the redacted evidence checklist is ready, but no real evidence is collected or accepted and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1`.

The readiness gate requires PASS PayPal live provider implementation evidence signoff gate evidence. It validates eight sandbox execution evidence references and four live production readiness references for checkout success/cancel, webhook success/duplicate/rejection, amount/currency mismatch, audit visibility, cleanup, credential holder, callback domain, monitoring/reconciliation, and cutover/rollback signoff.

The readiness gate does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not start collection, does not accept evidence, and does not enable PayPal UI.

## PayPal Live Execution Evidence Signoff Import Dry Run

After the live execution evidence readiness gate passes, validate the non-sensitive reviewer signoff import template:

```bash
php yii payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md`. The expected local fixture result is `PASS` with `live_execution_signoff_input_valid=1`, `signoff_import_applied=0`, `sandbox_execution_evidence_accepted=0`, `live_production_evidence_accepted=0`, and `paypal_enablement_allowed=0`: the reviewer input template is valid, but no signoff is imported, no evidence is accepted, and PayPal remains disabled.

Gate marker: `MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1`.

The dry-run requires PASS PayPal live execution evidence readiness gate evidence. It validates business, security, technical, and ops reviewer rows with safe references for sandbox cleanup, live rollback, external ticket, and source readiness report.

The dry-run does not read, copy, hash, import, or store referenced artifacts. It does not call PayPal, QPay, or LianLian, does not write `mall_payment_attempt`, does not persist reviewer rows, does not accept evidence, and does not enable PayPal UI.

## PayPal Final Go/No-Go Gate

After the live verification enablement gate passes, generate the read-only final decision report:

```bash
php yii payment-provider-paypal-final-go-no-go-gate/run --fixture=1 --interactive=0
```

This writes `runtime/handover/mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md`. The expected local fixture result is `PASS` with `Final decision: NO-GO`, `go_allowed=0`, and `final_decision_no_go=1`: the evidence chain is coherent, but real sandbox/live evidence acceptance and runtime implementation are still pending.

Gate marker: `MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1`.

The gate does not import signoff rows, accept evidence, read/copy/hash/import/store referenced artifacts, call PayPal/QPay/LianLian, write `mall_payment_attempt`, mutate business data, expose PayPal UI, or enable PayPal.
