# Mongoyia Test Server Run Sheet

This is the short operator checklist for a test-server restore. For details and troubleshooting, see `docs/mongoyia-test-server-runbook.md`.

## 1. Files To Transfer

Copy these files to the test server together:

- `runtime/handover/mongoyia-test-server-delivery-20260609-073834.zip` for Windows, or `runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz` for Linux.
- The adjacent delivery archive `.sha256` sidecar.
- `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql`.
- `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256`.

Do not place real `.env` files, SQL dumps, uploads, vendor dependencies, generated assets, or secrets inside the delivery archive.

## 2. Required Inputs

Prepare these values before apply mode:

- Test base URL: `https://<test-domain>`.
- IM WebSocket URL: `wss://<test-domain>/<im-path>`.
- Database name: `outer`.
- Backup reference: snapshot id, ticket id, or DBA confirmation.
- PHP `.env` created from `.env.test.example`.
- Python IM `.env` created from `im后端/im后端/.env.test.example`.
- Optional Google translation proxy: `GOOGLE_TRANSLATE_PROXY` only if PHP cURL cannot reach Google directly.

The test input gate rejects placeholder hosts, local-only HTTPS/WSS URLs, production domains, weak or mismatched IM secrets, missing payment callback secrets, and PHP/Python IM database mismatches.
Track the non-sensitive external readiness rows in `docs/mongoyia-external-integration-inputs.md` before confirming `EXTERNAL_TEST_INPUTS_CONFIRMED`.

## 3. Windows Commands

Run from `funboot_K84jE/funboot`:

```powershell
.\console\shell\mongoyia-validate-test-server-delivery.ps1 `
  -ArchivePath "runtime\handover\mongoyia-test-server-delivery-20260609-073834.zip"

.\console\shell\mongoyia-test-server-input-gate.ps1 `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-20260609-073834.zip" `
  -SqlDumpPath "<path>\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql" `
  -SqlChecksumPath "<path>\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256" `
  -Database outer `
  -BackupReference "<snapshot-or-ticket-id>" `
  -RequireRestoreInputs

.\console\shell\mongoyia-test-server-restore.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-20260609-073834.zip" `
  -SqlDumpPath "<path>\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql" `
  -SqlChecksumPath "<path>\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256" `
  -Database outer `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -BackupReference "<snapshot-or-ticket-id>" `
  -RunReceiver `
  -RunMigrate `
  -RunPreflight
```

Then run acceptance:

```powershell
php yii mongoyia-acceptance-fixture/run --apply=1 --interactive=0

.\console\shell\mongoyia-acceptance.ps1 `
  -BaseUrl "https://<test-domain>" `
  -Profile test `
  -Strict `
  -CleanupAfterRun `
  -ImUrl "wss://<test-domain>/<im-path>"

php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

Focused translation dry-run/apply, if content needs to be rechecked after restore:

```powershell
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=product --ids=90,102 --fields=name,brief --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=category --ids=94,106 --fields=name,brief --dryRun=1 --interactive=0
```

## 4. Linux Commands

Run from `funboot_K84jE/funboot`:

```bash
sh console/shell/mongoyia-validate-test-server-delivery.sh \
  runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz

DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz \
SQL_DUMP_PATH=<path>/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql \
SQL_CHECKSUM_PATH=<path>/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256 \
DATABASE=outer \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
BACKUP_REFERENCE=<snapshot-or-ticket-id> \
REQUIRE_RESTORE_INPUTS=1 \
sh console/shell/mongoyia-test-server-input-gate.sh

DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz \
SQL_DUMP_PATH=<path>/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql \
SQL_CHECKSUM_PATH=<path>/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256 \
DATABASE=outer \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
BACKUP_REFERENCE=<snapshot-or-ticket-id> \
RUN_RECEIVER=1 \
RUN_MIGRATE=1 \
RUN_PREFLIGHT=1 \
sh console/shell/mongoyia-test-server-restore.sh
```

Then run acceptance:

```bash
php yii mongoyia-acceptance-fixture/run --apply=1 --interactive=0

PROFILE=test \
STRICT=1 \
CLEANUP_AFTER_RUN=1 \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
sh console/shell/mongoyia-acceptance.sh

php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

Focused translation dry-run/apply, if content needs to be rechecked after restore:

```bash
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=product --ids=90,102 --fields=name,brief --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=category --ids=94,106 --fields=name,brief --dryRun=1 --interactive=0
```

## 5. Expected Result

- Delivery validation prints `Archive validation: PASS`.
- Restore plan/input gate result is `READY` before apply mode.
- Acceptance produces reports under `runtime/acceptance/`.
- Cleanup verification reports zero pending generated rows/files.
- Translation batch reports are written to `runtime/translation/mall-translate-fill-*.md`.
- Handoff status may remain `WARN (3)` until real test domain, IM WSS path, payment sandbox callback inputs, secrets, and backup reference are provided and tracked in `docs/mongoyia-external-integration-inputs.md`.
