# Mongoyia Test Server Runbook

For the package index, see `docs/mongoyia-package-index.md`.
For the short operator command list, see `docs/mongoyia-test-server-run-sheet.md`.

## Scope

This runbook is for restoring and accepting the Mongoyia test server. It does not claim production launch readiness.

For the current acceptance scope and remaining production risks, see `docs/mongoyia-delivery-status.md`.

Use the main PHP project at `funboot_K84jE/funboot` and the Python IM project at `im后端/im后端`.

Current verified receiver package for this sprint:

- Windows: `runtime/handover/mongoyia-test-server-delivery-20260618-121618.zip`
- Linux: `runtime/handover/mongoyia-test-server-delivery-20260618-121618.tar.gz`
- ZIP SHA256: `9dbc4839d604525d7a3243203fb556da9c661f3e37e7df2d754d05a8d09a41cd`
- TAR.GZ SHA256: `5d144b50a406cb12aca8261ad42a35c5a4ad3a0c19424b29f644562b60740e52`
- Local P2 readiness: `runtime/handover/mongoyia-p2-readiness-20260618-121712.md`, result `WARN` with `0 failure(s)`. The pending items are real HTTPS BaseUrl, real WSS IM URL, and backup reference/artifact.

Before running a non-dry-run restore, fill and review `docs/mongoyia-test-server-inputs.md` and `docs/mongoyia-external-integration-inputs.md`. Do not paste real passwords, API keys, private keys, payment credentials, callback secrets, or SSH secrets into those documents; record only owner, status, hostnames, ticket IDs, and where the secret was provisioned.

## 1. Restore

Restore the database dump to `outer`, then run migrations:

```bash
php yii migrate/up --interactive=0
```

Copy environment templates and replace every `replace-with-*` value:

```bash
cp .env.test.example .env
cp ../../im后端/im后端/.env.test.example ../../im后端/im后端/.env
```

After editing the real `.env` files, generate a shareable redacted configuration report:

```powershell
.\console\shell\mongoyia-env-redacted-report.ps1 -Profile test
```

```bash
PROFILE=test sh console/shell/mongoyia-env-redacted-report.sh
```

After restoring the baseline SQL and running migrations, prepare the acceptance-only users required by the smoke suite:

```bash
php yii mongoyia-acceptance-fixture/run --apply=1 --interactive=0
```

Before switching restore to apply mode, run the hard input gate. It fails on placeholder values, local-only HTTPS/WSS URLs, production domains (`mongoyia.com` or `www.mongoyia.com`) in test profile, weak or mismatched IM secrets, missing payment callback secrets, and PHP/Python IM database mismatches:

```powershell
.\console\shell\mongoyia-test-server-input-gate.ps1 `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>"
```

```bash
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
sh console/shell/mongoyia-test-server-input-gate.sh
```

When the delivery archive, SQL dump, checksum sidecar, and backup reference are known, run the same gate with restore inputs enabled before apply mode:

```powershell
.\console\shell\mongoyia-test-server-input-gate.ps1 `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "<dump.sql>" `
  -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" `
  -Database outer `
  -BackupReference "snapshot-or-ticket-id" `
  -RequireRestoreInputs
```

```bash
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
SQL_DUMP_PATH=<dump.sql> \
SQL_CHECKSUM_PATH=runtime/handover/<dump.sql>.sha256 \
DATABASE=outer \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
BACKUP_REFERENCE=snapshot-or-ticket-id \
REQUIRE_RESTORE_INPUTS=1 \
sh console/shell/mongoyia-test-server-input-gate.sh
```

To summarize the latest handoff artifacts and missing external inputs, first update the non-sensitive rows in `docs/mongoyia-external-integration-inputs.md`, then run:

```powershell
.\console\shell\mongoyia-handoff-status.ps1
```

```bash
sh console/shell/mongoyia-handoff-status.sh
```

To also validate the latest Linux delivery archive while generating the handoff status:

```bash
VALIDATE_DELIVERY=1 sh console/shell/mongoyia-handoff-status.sh
```

In the handoff status report, `WARN_LOCAL_EXPECTED` on the local redacted `.env` report, `PASS_LOCAL_ONLY` on the local acceptance report, and `restore plan = PENDING_EXTERNAL_INPUTS` are informational. The remaining handoff warning should be `external test-server inputs = PENDING` until the real test host, HTTPS/WSS domain, `.env` values, database credentials, payment sandbox values, and manual QA owners are supplied.

Minimum test profile expectations:

- `YII_DEBUG=false`
- `YII_ENV=test`
- `DEFAULT_ROUTE=mall`
- `DEFAULT_STORE_ID=5`
- `BACKEND_ONLY_DOMAINS=` unless a domain is intentionally backend-only.
- `HOST_ROUTE_MAP=` unless extra domains need explicit `domain:route` routing.
- `LEGACY_HOST_DOMAINS` includes old handover domains so they cannot select old stores.
- `STORE_PLATFORM_DOMAIN=<test-domain>`
- `WEB_BASE_URL=https://<test-domain>`
- Do not use `mongoyia.com` or `www.mongoyia.com` for a test restore. The input gate and restore apply safety gate block those production domains by default.
- Replace template hosts such as `test.mongoyia.example.com`; `profile=test/prod` deploy checks fail on `example.com` hosts.
- Regenerated `frontend/runtime/host.php` maps platform domains to `mall` and does not contain legacy domains.
- `IM_WEBSOCKET_URL=wss://<test-domain>/<im-path>`
- Python IM `.env` `IM_HOST` is a bind host such as `0.0.0.0` or `127.0.0.1`, not a URL.
- Python IM `.env` `IM_PORT` is an integer from `1` to `65535`; local `IM_WEBSOCKET_URL` localhost ports must match it.
- PHP and Python `IM_AUTH_SECRET` are the same long random value.
- PHP `.env` `DB_DSN/DB_USERNAME` and Python IM `.env` `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME` point to the same restored database.
- `CHAT_UPLOAD_URL` and `UPLOAD_HTTP_PREFIX` are root-relative paths or absolute HTTPS URLs on the test domain.
- QPay/LianLian sandbox credentials and callback HMAC secrets are set.
- `QPAY_CALLBACK_BASE` and `LIANLIAN_CALLBACK_BASE` are HTTPS URLs on the same real host as `STORE_PLATFORM_DOMAIN`, not template/example hosts.
- Callback max age is greater than `0`.
- PHP extensions/functions are available: `json`, `redis`, `curl`, `libxml`, `dom`, `gd`, `fileinfo`, `openssl`, `mbstring`, `pdo_mysql`, `getimagesize`, `fsockopen`, `hash_hmac`, `random_bytes`.
- Writable paths are available for the PHP/web-server user: `runtime`, `frontend/runtime`, `web/assets`, `web/attachment`, and `web/attachment/chat`.
- PHP runtime `upload_max_filesize` and `post_max_size` are at least `6M` so chat images above the 5MB business limit reach application validation and return the localized oversize error.

## 2. Start Services

Start PHP/Nginx/Apache according to the server setup.

Start Redis and verify it accepts connections.

Install and start Python IM:

```bash
cd ../../im后端/im后端
pip install -r requirements.txt
python scripts/im-healthcheck.py --url wss://<test-domain>/<im-path>
```

Use the provided systemd or Supervisor templates in `im后端/im后端/deploy/` when running IM as a service.

## 3. Preflight

From `funboot_K84jE/funboot`, run:

Windows:

```powershell
.\console\shell\mongoyia-test-profile-preflight.ps1
```

Linux:

```bash
sh console/shell/mongoyia-test-profile-preflight.sh
```

These wrappers run `deploy-check/run --profile=test --strict=1` against the current PHP and Python IM `.env` files. Use them before the longer command chain.

After the preflight passes, run the dry-run wrapper. It checks package completeness, security scan, host/catalog cleanup dry-run, data readiness, catalog readiness, translation dirty-data audit, translation readiness, order integrity, payment audit, optional API smoke, and generated test-data cleanup verification. It does not create checkout, payment, or chat regression data.

Windows:

```powershell
.\console\shell\mongoyia-test-server-dry-run.ps1 `
  -BaseUrl "https://<test-domain>"
```

Linux:

```bash
BASE_URL=https://<test-domain> sh console/shell/mongoyia-test-server-dry-run.sh
```

To produce a single markdown report for deployment review, run:

```powershell
.\console\shell\mongoyia-test-server-preflight-report.ps1 `
  -BaseUrl "https://<test-domain>"
```

```bash
BASE_URL=https://<test-domain> sh console/shell/mongoyia-test-server-preflight-report.sh
```

The report is written under `runtime/handover/mongoyia-test-server-preflight-*.md` and includes command output for deployment configuration, package/security checks, handover archive validation, source handover validation, data/catalog/translation-audit/translation-readiness/order/payment checks, optional API smoke, and generated test-data cleanup verification.

After the handover archive, source handover archive, and preflight report are ready, create one delivery wrapper for transfer to the test-server receiver:

```powershell
.\console\shell\mongoyia-test-server-delivery-archive.ps1
.\console\shell\mongoyia-validate-test-server-delivery.ps1
```

```bash
sh console/shell/mongoyia-test-server-delivery-archive.sh
sh console/shell/mongoyia-validate-test-server-delivery.sh
```

The delivery wrapper includes the handover archive, source handover archive, preflight report, and handover verification report. The PowerShell wrapper creates both `.zip` and `.tar.gz` when `tar` is available; use `.zip` on Windows receivers and `.tar.gz` on Linux receivers. It still intentionally excludes SQL dumps and real `.env` files.

On the receiving test server, verify and unpack the delivery wrapper before restore:

```powershell
.\console\shell\mongoyia-test-server-receiver.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -BaseUrl "https://<test-domain>"
```

```bash
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
BASE_URL=https://<test-domain> \
sh console/shell/mongoyia-test-server-receiver.sh
```

The receiver script validates checksums, extracts the delivery package to `runtime/handover/receiver-*`, verifies the nested handover/source archives, checks the included preflight PASS marker, and writes `RECEIVER_STATUS.md`. See `docs/mongoyia-test-server-receiver.md` for the receiver-side restore order.

For a receiver-side dry-run of the restore sequence:

Create a SQL checksum manifest before copying the dump to the receiver:

```powershell
.\console\shell\mongoyia-sql-dump-manifest.ps1 `
  -SqlDumpPath "<dump.sql>" `
  -Database outer
```

Generate a restore command plan on the receiver before switching to apply mode:

```powershell
.\console\shell\mongoyia-test-server-restore-plan.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "<dump.sql>" `
  -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" `
  -Database outer `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -BackupReference "snapshot-or-ticket-id"
```

```bash
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
SQL_DUMP_PATH=<dump.sql> \
SQL_CHECKSUM_PATH=runtime/handover/<dump.sql>.sha256 \
DATABASE=outer \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
BACKUP_REFERENCE=snapshot-or-ticket-id \
sh console/shell/mongoyia-test-server-restore-plan.sh
```

Generate the final receiver-side go/no-go checklist before apply mode:

```powershell
.\console\shell\mongoyia-test-server-go-no-go.ps1
```

```bash
sh console/shell/mongoyia-test-server-go-no-go.sh
```

`NO-GO` means do not run apply. `GO-WITH-WARNINGS` still requires owner approval. Apply is allowed only when the real input gate, receiver validation, SQL checksum, restore plan, restore dry-run, and external test-server inputs are all approved. Restore apply mode runs the same go/no-go checklist automatically before database restore unless the full emergency apply-safety bypass is used.

When the plan is generated on Windows for a Linux test server, the PowerShell planner prints Windows commands with local paths and Bash commands with Linux/test-server paths. Override those Bash paths with `-LinuxDeliveryArchivePath`, `-LinuxSqlDumpPath`, `-LinuxSqlChecksumPath`, `-LinuxBackupArtifactPath`, and `-LinuxBackupChecksumPath` if the files will be uploaded to different locations.

The restore plan includes both restore/preflight commands and full acceptance commands. Use the acceptance variant only after backup, input gate, dry-run, and restore/preflight approval; it adds `-RunAcceptance -CleanupAfterRun` or `RUN_ACCEPTANCE=1 CLEANUP_AFTER_RUN=1`.

```powershell
.\console\shell\mongoyia-test-server-restore.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "<dump.sql>" `
  -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" `
  -Database outer `
  -BaseUrl "https://<test-domain>" `
  -RunReceiver `
  -RunMigrate `
  -RunPreflight
```

Add `-Apply` only after the SQL dump path, `.env` files, database credentials, test domain, IM WSS URL, payment sandbox callback inputs, and backup/snapshot are confirmed in `docs/mongoyia-external-integration-inputs.md`. In apply mode, the restore orchestrator requires `-BackupConfirmed`, a `-BackupArtifactPath` or `-BackupReference`, `-ApplyConfirm RESTORE_OUTER_TEST_SERVER`, `-RunMigrate`, `-RunPreflight`, `-BaseUrl`, and `-ImUrl`, then automatically runs `mongoyia-test-server-input-gate` and `mongoyia-test-server-go-no-go` before database restore. If the handoff status still reports external inputs as pending, the Apply command must include `-ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED` or `EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED` only after the real values are supplied and approved. Do not skip the input gate in a normal test restore. A documented emergency bypass must skip the whole apply-safety gate with `-SkipApplySafety -SkipApplySafetyConfirm SKIP_RESTORE_APPLY_SAFETY` or `SKIP_APPLY_SAFETY=1 SKIP_APPLY_SAFETY_CONFIRM=SKIP_RESTORE_APPLY_SAFETY`; even then, apply mode still refuses `mongoyia.com` or `www.mongoyia.com` for `BaseUrl`/`ImUrl` unless `-AllowProductionDomainForTest` or `ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1` is also passed for an approved exception.

Only use the production-domain override for a deliberate, documented exception. Add the override to the normal apply command only after approval is recorded:

```powershell
# Add to the normal apply command:
-AllowProductionDomainForTest
```

```bash
ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1 \
# plus the normal APPLY=1 restore variables
sh console/shell/mongoyia-test-server-restore.sh
```

Windows apply example:

```powershell
.\console\shell\mongoyia-test-server-restore.ps1 `
  -Apply `
  -BackupConfirmed `
  -BackupReference "snapshot-or-ticket-id" `
  -ApplyConfirm RESTORE_OUTER_TEST_SERVER `
  -ExternalInputsConfirmed `
  -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "<dump.sql>" `
  -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" `
  -Database outer `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -RunReceiver `
  -RunMigrate `
  -RunPreflight
```

Linux apply example:

```bash
APPLY=1 \
BACKUP_CONFIRMED=1 \
BACKUP_REFERENCE=snapshot-or-ticket-id \
APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER \
EXTERNAL_INPUTS_CONFIRMED=1 \
EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED \
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
SQL_DUMP_PATH=<dump.sql> \
SQL_CHECKSUM_PATH=runtime/handover/<dump.sql>.sha256 \
DATABASE=outer \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
RUN_RECEIVER=1 \
RUN_MIGRATE=1 \
RUN_PREFLIGHT=1 \
sh console/shell/mongoyia-test-server-restore.sh
```

```bash
php yii deploy-check/run --profile=test --strict=1 --interactive=0
php yii mongoyia-package-check/run --interactive=0
php yii mongoyia-security-scan/run --strict=1 --interactive=0
php yii mongoyia-host-cleanup/run --interactive=0
php yii mongoyia-catalog-cleanup/run --interactive=0
php yii mongoyia-data-readiness/run --interactive=0
php yii mongoyia-catalog-readiness/run --interactive=0
php yii mongoyia-translation-audit/run --interactive=0
php yii mongoyia-translation-readiness/run --strict=1 --interactive=0
php yii mongoyia-order-integrity/run --interactive=0
php yii mongoyia-payment-audit/run --interactive=0
php yii mongoyia-payment-audit-backfill/run --interactive=0
php yii api-smoke-test/run --baseUrl=https://<test-domain> --interactive=0
```

Expected result:

- `deploy-check`: `0 failure(s), 0 warning(s)`
- `mongoyia-package-check`: `0 failure(s)`
- `mongoyia-security-scan`: `0 failure(s), 0 warning(s)`
- `mongoyia-data-readiness`: `0 failure(s), 0 warning(s)`
- `mongoyia-catalog-readiness`: `0 failure(s)`; backend zero-price protections pass, and zero-price or missing-image warnings must be recorded if present.
- `mongoyia-translation-audit`: `0 failure(s)`; same-as-source or dirty-content warnings must be recorded for content QA.
- `mongoyia-translation-readiness`: `0 failure(s), 0 warning(s)`
- `mongoyia-order-integrity`: `0 failure(s)`; legacy parent-order line warnings must be recorded if present.
- `mongoyia-payment-audit`: `0 failure(s)`; legacy paid-order audit warnings must be recorded if present.
- `mongoyia-payment-audit-backfill`: dry-run only unless business explicitly approves `--apply=1`; it inserts synthetic `legacy/backfill` success audit rows and does not change orders, order products, inventory, or payment status.
- `api-smoke-test`: public API endpoints return HTTP 200 JSON, protected API endpoint returns HTTP 401/403, and no `/api` endpoint returns 500.

If any command fails, fix the reported environment value, schema issue, account, product, IM context, or translation baseline before running full acceptance.

If `mongoyia-host-cleanup/run` reports old handover domains or platform domains on non-platform stores, review the rows and then run:

```bash
php yii mongoyia-host-cleanup/run --apply=1 --interactive=0
```

After apply, rerun `mongoyia-data-readiness/run`. The host cleanup only updates `fb_store.host_name` and regenerates `frontend/runtime/host.php`.

If `mongoyia-catalog-cleanup/run` reports active categories with missing or inactive parents, review the rows and then run:

```bash
php yii mongoyia-catalog-cleanup/run --apply=1 --interactive=0
```

After apply, rerun `mongoyia-catalog-readiness/run`. The catalog cleanup only changes orphan active categories to top-level `parent_id=0`; zero-price products are reported but not automatically priced or deactivated.
Frontend purchase paths and backend product save/status-change paths block zero-price products from being sold or reactivated. Treat any remaining zero-price warnings as business data that needs confirmed pricing or deactivation before final signoff.

## 4. Full Acceptance

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

Or run the final one-command handover wrapper after the test profile `.env` is ready:

```bash
PROFILE=test \
STRICT=1 \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
TESTER=<tester> \
NOTES=test-server \
sh console/shell/mongoyia-final-handover.sh
```

It runs acceptance with cleanup, then generates signoff, risk register, delivery index, and final cleanup verification.

Archive the handover docs, scripts, templates, and latest generated reports:

```powershell
powershell -ExecutionPolicy Bypass -File console\shell\mongoyia-archive-handover.ps1
```

The archive command writes a staging folder and `.zip` under `runtime/handover/`. It includes templates and handover artifacts only; it intentionally excludes real `.env`, vendor dependencies, uploaded files, and database dumps.
The archive command also writes a sidecar `.sha256` file. Keep the archive and checksum together when copying the handover package.

Validate a received archive before using it for handover:

```powershell
.\console\shell\mongoyia-validate-handover-archive.ps1 `
  -ArchivePath "runtime\handover\mongoyia-handover-<stamp>.zip"
```

```bash
ARCHIVE_PATH=runtime/handover/mongoyia-handover-<stamp>.tar.gz sh console/shell/mongoyia-validate-handover-archive.sh
```

Before sending the package to the receiver, run the final handover verification wrapper:

```powershell
.\console\shell\mongoyia-handover-verify.ps1 `
  -ArchivePath "runtime\handover\mongoyia-handover-<stamp>.zip"
```

The verification wrapper runs package checks, security scan, input-gate smoke, go/no-go smoke, generated test-data cleanup verification, and archive validation. It writes `runtime/handover/mongoyia-handover-verify-*.md`; keep that report with the archive and `.sha256` file as handover evidence.

The full acceptance chain runs:

1. Deployment configuration check.
2. Handover package check.
3. Security/hardcode scan.
4. Data-readiness check.
5. Catalog-readiness check.
6. Translation dirty-data audit.
7. Translation-readiness check.
8. Order-integrity check.
9. Payment-audit check.
10. IM healthcheck, chat regression, auth/scope/payload rejection regression, and lightweight concurrency regression.
11. API smoke test.
12. Frontend smoke test, including English/Mongolian chat text consistency, public layout language consistency for header/cart/breadcrumb/search/Cookie bar, language-aware chat token/upload URLs, localized chat token/upload/type/invalid-image/oversize errors, successful chat image upload metadata/URL, localized chat script error fallback text, and zero-price cart-add rejection for the configured sample product.
13. Backend smoke test.
14. Payment callback regression, including callback HMAC and expired-timestamp positive/negative checks when QPay/LianLian callback secrets and max-age values are configured.
15. Generated test-data cleanup.
16. Generated test-data cleanup verification with `--failOnPending=1`.

Translation dirty-data audit warnings are included in the acceptance report. They do not fail acceptance unless `--translationAuditStrict=1` is set, so demo/test same-as-source labels can be tracked as content QA while hard readiness failures still block signoff.

Acceptance reports are written under `runtime/acceptance/` unless `--noReport=1` is used. The report starts with `Signoff Summary`, including profile, strict mode, executed step count, cleanup verification, account/product context, and warning/failure counts.

After a successful run, generate a short signoff file:

```bash
php yii mongoyia-signoff/run --interactive=0
```

The command uses the latest `runtime/acceptance/mongoyia-acceptance-*.md` report by default and writes `runtime/acceptance/mongoyia-signoff-*.md`.

Generate a risk register:

```bash
php yii mongoyia-risk-register/run --interactive=0
```

The risk register extracts warning/failure rows from the latest acceptance report and adds production-scope risks.

Generate a final handover index:

```bash
php yii mongoyia-delivery-index/run --interactive=0
```

The index points to the latest acceptance report, latest signoff file, latest PWA mobile UI evidence report/result, core docs, wrapper scripts, and remaining production-scope boundary.

## 5. Accounts And Defaults

Default acceptance values:

- Platform backend: `codex_platform_backend_test_5`
- Seller backend: `zhishichanquan`
- Platform store id: `5`
- Payment test user id: `71`
- Product ids: `90,102`
- IM merchant uid: `37`
- IM product id: `102`
- IM store id: `9`

If the test server uses different accounts or data, pass overrides to `mongoyia-acceptance/run` or the wrapper scripts.

## 6. Cleanup

The acceptance wrapper should be run with cleanup enabled. To inspect generated rows manually:

```bash
php yii mongoyia-test-cleanup/run --interactive=0
```

To clean generated regression rows:

```bash
php yii mongoyia-test-cleanup/run --apply=1 --olderThanHours=0 --interactive=0
```

To make CI or final signoff fail when generated rows/files remain:

```bash
php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

The cleanup command only targets generated `REGPAY-*` orders, generated payment audit rows/order products, generated IM `healthcheck_*`, `im_regression_*`, or `im_concurrency_*` chat rows, browser chat smoke messages whose content starts with `im_regression_browser_`, and uploaded chat smoke files matching `web/attachment/chat/YYYY/MM/DD/chat_smoke_*.png`. It restores stock for generated orders when needed before soft-deleting order rows.

## 7. Failure Triage

- Environment/profile failure: check `.env`, IM `.env`, domain, HTTPS/WSS, Redis, PHP upload limits, payment sandbox keys, callback HMAC secrets.
- Security scan failure: remove committed local secrets, old hardcoded domains, or remote database DSNs from source.
- Data-readiness failure: restore the expected database dump or override test account/product IDs.
- Catalog-readiness failure: inspect active products, stores, categories, SKU, stock, and images before frontend acceptance.
- Translation-audit/readiness failure: run `mall-translate/fill`, review dirty `fb_base_lang` rows, and manually sample product/category pages. Audit warnings for demo/test labels should be assigned to content QA unless they block launch copy.
- Order-integrity failure: inspect parent/child order totals, order-product store ownership, and product store ownership before accepting payment/order flows.
- Payment-audit failure: inspect `fb_mall_payment_attempt`, duplicate gateway transaction ids, and paid parent-order audit coverage.
- Legacy order cleanup: after business confirmation, use `php yii mongoyia-order-integrity/run --includeLegacy=1 --strict=1 --interactive=0` to measure old single-order rows that still need migration or archival.
- IM failure: check Python IM process, WebSocket proxy, `IM_AUTH_SECRET`, and `fb_chat` schema.
- API smoke failure: check `/api`, `/api/site/index`, `/api/v1/default/index`, web-server rewrite rules, API bootstrap config, and auth middleware before frontend acceptance.
- Frontend/backend smoke failure: open the reported path and check PHP/Yii errors.
- Payment regression failure: inspect recent `fb_mall_payment_attempt` rows and generated `REGPAY-*` orders before cleanup.

## 8. Current Local Baseline

On the local handover environment, the full PowerShell wrapper passes in `local` profile with cleanup enabled.

Local `deploy-check` still reports expected warnings because local uses localhost IM, local IM secret, and placeholder payment credentials. Final test-server acceptance must use `profile=test --strict=1` and should have no warnings.
