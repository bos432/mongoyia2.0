# Mongoyia Test Server Receiver Guide

This guide is for the person who receives `mongoyia-test-server-delivery-*.zip` or `mongoyia-test-server-delivery-*.tar.gz` on a test server. Use `.zip` on Windows receivers and `.tar.gz` on Linux receivers.

The delivery archive intentionally excludes SQL dumps, real `.env` files, uploaded files, generated assets, vendor dependencies, and production secrets.

## Receive Order

1. Copy the delivery archive and its `.sha256` sidecar to the test server.
2. Verify and extract the delivery archive with the receiver script.
3. Generate a restore command plan with `mongoyia-test-server-restore-plan`.
4. Restore the database dump separately to `outer`.
5. Apply or review the source handover archive according to the team's source-control process.
6. Create PHP and Python IM `.env` files from `.env.test.example`.
7. Run migrations.
8. Run strict preflight.
9. Run full acceptance only after preflight is PASS.

After creating real `.env` files, generate a redacted environment report for review:

```powershell
.\console\shell\mongoyia-env-redacted-report.ps1 -Profile test
```

```bash
PROFILE=test sh console/shell/mongoyia-env-redacted-report.sh
```

The report is written under `runtime/handover/` and redacts passwords, secrets, tokens, auth headers, API keys, and payment credentials.

Before running restore with `-Apply` or `APPLY=1`, run the hard input gate. In test profile it rejects placeholder hosts, local-only URLs, production domains (`mongoyia.com` or `www.mongoyia.com`), missing restore files, SQL checksum mismatches, unsafe database names, and missing backup evidence:

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

The restore orchestrator also runs this gate with restore inputs automatically in apply mode, then runs `mongoyia-test-server-go-no-go` before database restore. Do not skip the input gate in a normal test restore. A documented emergency bypass must skip the whole apply-safety gate with `-SkipApplySafety -SkipApplySafetyConfirm SKIP_RESTORE_APPLY_SAFETY` or `SKIP_APPLY_SAFETY=1 SKIP_APPLY_SAFETY_CONFIRM=SKIP_RESTORE_APPLY_SAFETY`. Even with that emergency bypass, apply mode still blocks production `BaseUrl`/`ImUrl` unless `-AllowProductionDomainForTest` or `ALLOW_PRODUCTION_DOMAIN_FOR_TEST=1` is used for an approved exception.

Generate a receiver-side command plan before switching from dry-run to apply:

```powershell
.\console\shell\mongoyia-test-server-restore-plan.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "<dump.sql>" `
  -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -BackupReference "snapshot-or-ticket-id"
```

```bash
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
SQL_DUMP_PATH=<dump.sql> \
SQL_CHECKSUM_PATH=runtime/handover/<dump.sql>.sha256 \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
BACKUP_REFERENCE=snapshot-or-ticket-id \
sh console/shell/mongoyia-test-server-restore-plan.sh
```

When a Windows operator generates the plan for a Linux receiver, pass `-LinuxDeliveryArchivePath`, `-LinuxSqlDumpPath`, `-LinuxSqlChecksumPath`, `-LinuxBackupArtifactPath`, and `-LinuxBackupChecksumPath` if the upload paths differ from the default receiver-side relative paths.

The generated restore plan includes restore/preflight commands plus full acceptance commands. Use the acceptance variant only after backup, input gate, dry-run, and restore/preflight approval; it adds `-RunAcceptance -CleanupAfterRun` or `RUN_ACCEPTANCE=1 CLEANUP_AFTER_RUN=1`.

At any point, update `mongoyia-external-integration-inputs.md` with non-sensitive server/payment/IM/backup ownership, then generate a handoff status summary to see the latest delivery archive, SQL checksum, receiver status, restore dry-run status, preflight report, acceptance/signoff evidence, and remaining external inputs:

```powershell
.\console\shell\mongoyia-handoff-status.ps1
```

```bash
sh console/shell/mongoyia-handoff-status.sh
```

For Linux `.tar.gz` delivery archives, add validation to the same status command:

```bash
VALIDATE_DELIVERY=1 sh console/shell/mongoyia-handoff-status.sh
```

If the status report shows `env redacted report` as `WARN_LOCAL_EXPECTED`, `acceptance report` as `PASS_LOCAL_ONLY`, or `restore plan` as `PENDING_EXTERNAL_INPUTS`, that comes from the local smoke environment and missing real test-server inputs. Test-server acceptance is still blocked until `external test-server inputs` is no longer `PENDING` and the real `profile=test --strict=1` gates pass.

After restore, strict preflight, full acceptance, payment sandbox checks, and IM WSS checks are complete, first record the non-sensitive payment provider evidence:

```powershell
.\console\shell\mongoyia-payment-sandbox-evidence.ps1 `
  -BaseUrl "https://<test-domain>" `
  -QpaySignoff PASS `
  -QpayReference "ticket-or-screenshot-id" `
  -LianlianSignoff PASS `
  -LianlianReference "ticket-or-screenshot-id" `
  -FailOnPending
```

```bash
BASE_URL=https://<test-domain> \
QPAY_SIGNOFF=PASS \
QPAY_REFERENCE=ticket-or-screenshot-id \
LIANLIAN_SIGNOFF=PASS \
LIANLIAN_REFERENCE=ticket-or-screenshot-id \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-payment-sandbox-evidence.sh
```

Record the non-sensitive IM WSS evidence after the public-domain healthcheck/regression/concurrency and reverse-proxy/TLS/service checks pass:

```powershell
.\console\shell\mongoyia-im-wss-evidence.ps1 `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -WssSignoff PASS `
  -ReverseProxyReference "ticket-or-config-reference" `
  -TlsReference "certificate-ticket-reference" `
  -ServiceManagerReference "systemd-or-supervisor-reference" `
  -FailOnPending
```

```bash
IM_URL=wss://<test-domain>/<im-path> \
WSS_SIGNOFF=PASS \
REVERSE_PROXY_REFERENCE=ticket-or-config-reference \
TLS_REFERENCE=certificate-ticket-reference \
SERVICE_MANAGER_REFERENCE=systemd-or-supervisor-reference \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-im-wss-evidence.sh
```

After the Mongolian review CSV workflow is complete, record the non-sensitive reviewer and image-text signoff evidence:

```powershell
.\console\shell\mongoyia-mongolian-review-evidence.ps1 `
  -Reviewer "name-or-ticket" `
  -ReviewSignoff PASS `
  -ImageTextSignoff PASS `
  -RemainingRiskReference "ticket-or-sheet-reference" `
  -FailOnPending
```

```sh
REVIEWER=name-or-ticket \
REVIEW_SIGNOFF=PASS \
IMAGE_TEXT_SIGNOFF=PASS \
REMAINING_RISK_REFERENCE=ticket-or-sheet-reference \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-mongolian-review-evidence.sh
```

Then collect the latest non-sensitive reports into one P2 review archive:

```powershell
.\console\shell\mongoyia-p2-evidence-pack.ps1 -FailOnPending
```

```bash
FAIL_ON_PENDING=1 sh console/shell/mongoyia-p2-evidence-pack.sh
```

The payment evidence, IM WSS evidence, and P2 evidence pack must not contain real `.env` files, secrets, SSH keys, raw payment credentials, callback HMAC secrets, IM auth secrets, DB passwords, auth headers, or private keys.

## Windows Receiver

From `funboot_K84jE/funboot`:

```powershell
.\console\shell\mongoyia-test-server-receiver.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -BaseUrl "https://<test-domain>" `
  -RunPreflight
```

If the archive has not been copied into the project yet, pass an absolute `-DeliveryArchivePath`.

## Linux Receiver

From `funboot_K84jE/funboot`:

```bash
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
BASE_URL=https://<test-domain> \
RUN_PREFLIGHT=1 \
sh console/shell/mongoyia-test-server-receiver.sh
```

## Database Restore

Restore the SQL dump outside the delivery archive. You can use the restore orchestrator in dry-run mode first:

Generate and send a SQL checksum sidecar before transfer:

```powershell
.\console\shell\mongoyia-sql-dump-manifest.ps1 `
  -SqlDumpPath "outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql" `
  -Database outer
```

```bash
SQL_DUMP_PATH=outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql \
DATABASE=outer \
sh console/shell/mongoyia-sql-dump-manifest.sh
```

```powershell
.\console\shell\mongoyia-test-server-restore.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql" `
  -SqlChecksumPath "runtime\handover\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256" `
  -Database outer `
  -MysqlUser <user> `
  -BaseUrl "https://<test-domain>" `
  -RunReceiver `
  -RunMigrate `
  -RunPreflight
```

```bash
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
SQL_DUMP_PATH=outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql \
SQL_CHECKSUM_PATH=runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256 \
DATABASE=outer \
MYSQL_USER=<user> \
BASE_URL=https://<test-domain> \
RUN_RECEIVER=1 \
RUN_MIGRATE=1 \
RUN_PREFLIGHT=1 \
sh console/shell/mongoyia-test-server-restore.sh
```

After reviewing the dry-run output, real `.env` files, HTTPS/WSS URLs, payment sandbox callback inputs, and database backup/snapshot, execute with `-Apply` or `APPLY=1`. Apply mode requires the explicit backup confirmation, a backup artifact path or snapshot/ticket reference, confirmation phrase, migrations, strict preflight, go/no-go approval, and non-sensitive approval tracking in `mongoyia-external-integration-inputs.md`. If the handoff status still reports external inputs as pending, include `-ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED` or `EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED` only after the real test-server values are supplied and approved:

```powershell
.\console\shell\mongoyia-test-server-restore.ps1 `
  -Apply `
  -BackupConfirmed `
  -BackupReference "snapshot-or-ticket-id" `
  -ApplyConfirm RESTORE_OUTER_TEST_SERVER `
  -ExternalInputsConfirmed `
  -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-<stamp>.zip" `
  -SqlDumpPath "outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql" `
  -SqlChecksumPath "runtime\handover\outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256" `
  -Database outer `
  -MysqlUser <user> `
  -BaseUrl "https://<test-domain>" `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -RunReceiver `
  -RunMigrate `
  -RunPreflight
```

```bash
APPLY=1 \
BACKUP_CONFIRMED=1 \
BACKUP_REFERENCE=snapshot-or-ticket-id \
APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER \
EXTERNAL_INPUTS_CONFIRMED=1 \
EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED \
DELIVERY_ARCHIVE_PATH=runtime/handover/mongoyia-test-server-delivery-<stamp>.tar.gz \
SQL_DUMP_PATH=outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql \
SQL_CHECKSUM_PATH=runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256 \
DATABASE=outer \
MYSQL_USER=<user> \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
RUN_RECEIVER=1 \
RUN_MIGRATE=1 \
RUN_PREFLIGHT=1 \
sh console/shell/mongoyia-test-server-restore.sh
```

Manual restore example:

```bash
mysql --default-character-set=utf8mb4 -u <user> -p outer < outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql
php yii migrate/up --interactive=0
```

Use the real dump chosen for the test-server baseline. The current local baseline is `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql`.

## Required Receiver Checks

- Delivery archive checksum matches its `.sha256`.
- Nested handover archive checksum matches.
- Nested source handover archive checksum matches.
- Preflight report included in the delivery archive is marked PASS.
- No real `.env`, SQL dump, vendor directory, generated asset, upload, or secret payload is inside the delivery archive.
- PHP `.env` and Python IM `.env` point to the restored `outer` database and use the same `IM_AUTH_SECRET`.
- `mongoyia-test-server-input-gate` passes before apply mode.
- `mongoyia-test-server-input-gate-smoke` is included in the receiver package for validating the input-gate rules with synthetic PASS/FAIL env files; it does not use real `.env` files.
- `mongoyia-test-server-go-no-go-smoke` is included in the receiver package for validating the go/no-go external-input confirmation rules with synthetic reports; it does not use real `.env` files.
- `mongoyia-test-server-go-no-go` is generated and reviewed before apply mode; restore apply runs it again automatically before database restore, and `NO-GO` means do not run restore apply.
- Apply mode does not use `-SkipInputGate` or `SKIP_INPUT_GATE=1`; an emergency bypass must use the full apply-safety bypass plus `SKIP_RESTORE_APPLY_SAFETY` confirmation.
- Restore apply mode includes backup confirmation, backup artifact/reference, and the `RESTORE_OUTER_TEST_SERVER` confirmation phrase.
- Test profile deploy check passes with `profile=test` and `strict=1`.

## Full Acceptance

After receiver preflight is PASS:

```powershell
.\console\shell\mongoyia-acceptance.ps1 `
  -BaseUrl "https://<test-domain>" `
  -Profile test `
  -Strict `
  -CleanupAfterRun `
  -ImUrl "wss://<test-domain>/<im-path>"
```

```bash
PROFILE=test \
STRICT=1 \
CLEANUP_AFTER_RUN=1 \
BASE_URL=https://<test-domain> \
IM_URL=wss://<test-domain>/<im-path> \
sh console/shell/mongoyia-acceptance.sh
```

Generated acceptance reports are written under `runtime/acceptance/`.
