# Mongoyia Test Server Inputs

Use this checklist before switching from receiver/restore dry-run to real test-server restore. Do not paste real passwords, API keys, tokens, callback secrets, or private keys into this file.

## Package Baseline

| Item | Expected Value | Status | Owner | Notes |
|---|---|---:|---|---|
| Delivery archive | `runtime/handover/mongoyia-test-server-delivery-20260609-073834.zip` or `.tar.gz` | Pending |  | Use `.zip` on Windows and `.tar.gz` on Linux. |
| Delivery SHA256 | Use the adjacent `.sha256` sidecar and the latest validated handoff status report | Pending |  | Do not copy hashes from inside the delivery archive; archive hashes change when docs are regenerated. |
| SQL dump | `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql` | Pending |  |  |
| SQL SHA256 | `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256` with `254044ee74325ff9cad39595ee2310d046a48760a950e598bfe5e0636eb5f379` | Pending |  | Copy this sidecar next to the SQL dump before restore dry-run/apply. |
| Source handover archive | `runtime/handover/mongoyia-source-handover-20260609-073834.zip` | Pending |  |  |
| Handoff status report | `runtime/handover/mongoyia-handoff-status-20260609-073834-validated.md` | Pending |  |  |

## Server Inputs

| Input | Required Value | Status | Owner | Notes |
|---|---|---:|---|---|
| Test server access | SSH/RDP account and allowed IPs | Pending |  |  |
| OS and web server | Linux/Windows, Nginx/Apache/IIS details | Pending |  |  |
| PHP runtime | PHP version and enabled extensions | Pending |  |  |
| MySQL/MariaDB | Host, port, database, user provisioned | Pending |  |  |
| Redis | Host, port, DB, password policy | Pending |  |  |
| Writable paths | `runtime`, `frontend/runtime`, `web/assets`, `web/attachment`, `web/attachment/chat` | Pending |  |  |
| Backup plan | Snapshot or dump before destructive restore | Pending |  |  |

## Domain And TLS Inputs

| Input | Required Value | Status | Owner | Notes |
|---|---|---:|---|---|
| Test domain | Real HTTPS host, not `example.com` | Pending |  |  |
| Production domain guard | Test host is not `mongoyia.com` or `www.mongoyia.com` | Pending |  | Override requires a documented exception. |
| TLS certificate | Valid certificate and renewal owner | Pending |  |  |
| PHP base URL | `WEB_BASE_URL=https://<test-domain>` and host matches `STORE_PLATFORM_DOMAIN` | Pending |  |  |
| Store domain | `STORE_PLATFORM_DOMAIN=<test-domain>` | Pending |  | Must be the same host as `WEB_BASE_URL`. |
| IM WSS URL | `IM_WEBSOCKET_URL=wss://<test-domain>/<im-path>` and restore `ImUrl` host matches `STORE_PLATFORM_DOMAIN` | Pending |  | Required by input gate. |
| Reverse proxy | WSS path routes to Python IM bind host/port | Pending |  |  |

## PHP `.env` Inputs

| Input | Required Value | Status | Owner | Notes |
|---|---|---:|---|---|
| Profile | `PROFILE=test`, `YII_ENV=test`, `YII_DEBUG=false` | Pending |  |  |
| Database | DSN, username, password provisioned in `.env` | Pending |  |  |
| Platform routing | `DB_TABLE_PREFIX`, `DEFAULT_STORE_ID`, `DEFAULT_ROUTE=mall`, `MALL_PLATFORM_MODE`, and `MALL_PLATFORM_OPERATOR_STORE_IDS` set | Pending |  | Required by input gate and strict deploy check. |
| Redis | Host, port, DB, password provisioned in `.env` | Pending |  |  |
| Upload URL | `UPLOAD_HTTP_PREFIX` and `CHAT_UPLOAD_URL` are root-relative or HTTPS URLs on the test domain | Pending |  |  |
| IM auth secret | Same long random value as Python IM | Pending |  |  |
| QPay sandbox | Sandbox merchant config provisioned in `.env` | Pending |  |  |
| LianLian sandbox | Sandbox merchant config provisioned in `.env`, with `LIANLIAN_SANDBOX=true` for test and `false` for prod | Pending |  | Required by input gate and strict deploy check. |
| Callback base URLs | `QPAY_CALLBACK_BASE` and `LIANLIAN_CALLBACK_BASE` are HTTPS URLs on the same host as `STORE_PLATFORM_DOMAIN` | Pending |  | Required by input gate and strict deploy check. |
| Callback secrets | HMAC/signature secrets provisioned in `.env` | Pending |  |  |
| Callback max age | `QPAY_CALLBACK_MAX_AGE_SECONDS` and `LIANLIAN_CALLBACK_MAX_AGE_SECONDS` are positive integers | Pending |  | Required by input gate and strict deploy check. |

## Python IM `.env` Inputs

| Input | Required Value | Status | Owner | Notes |
|---|---|---:|---|---|
| Bind host | `0.0.0.0` or `127.0.0.1`, not a URL | Pending |  | Required by input gate and strict deploy check. |
| Bind port | Integer from `1` to `65535` | Pending |  |  |
| Chat table | `IM_CHAT_TABLE` equals PHP `DB_TABLE_PREFIX` + `chat` | Pending |  |  |
| Message limits | `IM_MAX_TEXT_MESSAGE_LENGTH` is `1-10000`; `IM_MAX_IMAGE_MESSAGE_LENGTH` is `1-8192` | Pending |  | Required by input gate and strict deploy check. |
| Database | Same restored `outer` database as PHP | Pending |  |  |
| Redis | Same Redis policy as PHP unless intentionally isolated | Pending |  |  |
| IM auth secret | Same long random value as PHP | Pending |  |  |
| Service manager | systemd or Supervisor configured | Pending |  |  |

## Acceptance Owners

| Area | Owner | Status | Notes |
|---|---|---:|---|
| Payment sandbox callback QA |  | Pending |  |
| Backend platform admin QA |  | Pending |  |
| Backend seller operation QA |  | Pending |  |
| IM customer-service QA |  | Pending |  |
| Mongolian language QA |  | Pending |  |
| Product image/content review |  | Pending |  |
| Final test-server signoff |  | Pending |  |

## Go/No-Go Gate

Before running restore with `-Apply` or `APPLY=1`, confirm:

- Delivery archive checksum is PASS.
- SQL dump checksum is PASS using `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256`.
- Real PHP `.env` and Python IM `.env` exist on the test server.
- Restore plan result is `READY`; `BaseUrl` and `ImUrl` hosts point to the same test domain.
- `mongoyia-test-server-input-gate` passes with the real HTTPS `BaseUrl`, WSS `ImUrl`, delivery archive, SQL dump, SQL checksum, target database, and backup reference/artifact; `BaseUrl` and `ImUrl` hosts must match `STORE_PLATFORM_DOMAIN`.
- Restore apply does not use `-SkipInputGate` or `SKIP_INPUT_GATE=1`; input gate is mandatory unless the whole apply safety gate is explicitly bypassed for a documented emergency.
- Emergency bypass of apply safety requires `-SkipApplySafetyConfirm SKIP_RESTORE_APPLY_SAFETY` or `SKIP_APPLY_SAFETY_CONFIRM=SKIP_RESTORE_APPLY_SAFETY`, and must be documented in the restore ticket.
- No `example.com`, local-only host, production domain, or placeholder secret remains in test `.env`.
- Database backup/snapshot is complete.
- Restore apply command includes `-BackupConfirmed -BackupReference ... -ApplyConfirm RESTORE_OUTER_TEST_SERVER -DeliveryArchivePath ... -SqlDumpPath ... -SqlChecksumPath ... -RunReceiver -RunMigrate -RunPreflight -BaseUrl ... -ImUrl ...` or `BACKUP_CONFIRMED=1 BACKUP_REFERENCE=... APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER DELIVERY_ARCHIVE_PATH=... SQL_DUMP_PATH=... SQL_CHECKSUM_PATH=... RUN_RECEIVER=1 RUN_MIGRATE=1 RUN_PREFLIGHT=1 BASE_URL=... IM_URL=...`.
- Restore dry-run has been reviewed.
- `deploy-check/run --profile=test --strict=1 --interactive=0` is expected to run after restore.
