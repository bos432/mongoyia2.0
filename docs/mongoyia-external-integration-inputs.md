# Mongoyia External Integration Inputs

This checklist is for the P2/P3 work that cannot be completed from the local handover machine alone. Do not paste real passwords, API keys, private keys, callback secrets, SSH keys, or payment credentials into this file. Record only owner, status, hostnames, ticket IDs, and where the secret was provisioned.

## Status Rules

| Status | Meaning |
|---|---|
| Pending | Input has not been supplied or has not been validated. |
| Provisioned | Value exists on the test server or provider portal, but acceptance has not passed yet. |
| PASS | The related gate or regression has passed on the real test server. |
| Blocked | External owner, provider, DNS/TLS, or infrastructure issue prevents execution. |
| Deferred | Reserved for a later phase and does not block the current test-server acceptance gate. |

## Test Server Restore Inputs

| Input | Required Evidence | Status | Owner | Notes |
|---|---|---:|---|---|
| Server access | SSH/RDP method, allowed IPs, and operator account confirmed outside this repo | Pending |  | Do not store passwords or private keys here. |
| Test HTTPS domain | Real non-production HTTPS host | Pending |  | Must not be `mongoyia.com` or `www.mongoyia.com` unless documented as an exception. |
| TLS certificate | Valid certificate and renewal owner | Pending |  |  |
| Database target | Database name, host/port, and restore user provisioned | Pending |  | Password goes only in server `.env`. |
| Redis target | Host/port/DB and password policy provisioned | Pending |  |  |
| Backup reference | Snapshot ID, backup artifact path, or change ticket | Pending |  | Required before restore apply. |
| Delivery package | `mongoyia-test-server-delivery-20260609-073834.zip` or `.tar.gz` plus sidecar checksum | Pending |  | Verify with receiver script before restore. |
| SQL baseline | `outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql` plus SHA256 sidecar | Pending |  | Expected SQL SHA256 is in `docs/mongoyia-test-server-inputs.md`. |

## Payment Sandbox Inputs

| Provider | Input | Required Evidence | Status | Owner | Notes |
|---|---|---|---:|---|---|
| QPay | Sandbox merchant authorization | `QPAY_AUTH_BASIC` provisioned in PHP `.env` | Pending |  | Secret value must not be copied into docs. |
| QPay | Sandbox invoice code | `QPAY_INVOICE_CODE` provisioned in PHP `.env` | Pending |  |  |
| QPay | Gateway endpoints | `QPAY_AUTH_URL` and `QPAY_INVOICE_URL` set to provider sandbox HTTPS endpoints | Pending |  |  |
| QPay | Callback URL | Provider portal points to `https://<test-domain>/mall/payment/qpay-callback` or agreed route | Pending |  | Host must match `STORE_PLATFORM_DOMAIN`. |
| QPay | Callback HMAC secret | `QPAY_CALLBACK_HMAC_SECRET` and `QPAY_CALLBACK_MAX_AGE_SECONDS` provisioned | Pending |  | Minimum 32 characters for HMAC secret. |
| QPay | Callback validation | Success, duplicate, expired timestamp, bad signature, and amount mismatch cases tested | Pending |  | Acceptance payment regression should pass. |
| QPay | Evidence report | `mongoyia-payment-sandbox-evidence` references reviewed | Pending |  | Store ticket/screenshot reference only, not secrets. |
| LianLian | Sandbox merchant ID | `LIANLIAN_MERCHANT_ID` provisioned in PHP `.env` | Pending |  |  |
| LianLian | Public/private keys | `LIANLIAN_PUBLIC_KEY` and `LIANLIAN_PRIVATE_KEY` provisioned in PHP `.env` | Pending |  | Private key must not be committed. |
| LianLian | Sandbox flag | `LIANLIAN_SANDBOX=true` on test server | Pending |  | Test gate fails if false. |
| LianLian | Callback URL | Provider portal points to `https://<test-domain>/mall/payment/lianlian-callback` or agreed route | Pending |  | Host must match `STORE_PLATFORM_DOMAIN`. |
| LianLian | Callback HMAC secret | `LIANLIAN_CALLBACK_HMAC_SECRET` and `LIANLIAN_CALLBACK_MAX_AGE_SECONDS` provisioned | Pending |  | Minimum 32 characters for HMAC secret. |
| LianLian | Callback validation | Success, duplicate, expired timestamp, bad signature, and amount mismatch cases tested | Pending |  | Acceptance payment regression should pass. |
| LianLian | Evidence report | `mongoyia-payment-sandbox-evidence` references reviewed | Pending |  | Store ticket/screenshot reference only, not keys. |
| PayPal | Reserved provider contract | `payment-provider-readiness/run` PASS with `PAYPAL_ENABLED=false` | Deferred |  | Future Phase 6 provider; not required for current test-server acceptance. |
| PayPal | Sandbox credentials | `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, and `PAYPAL_WEBHOOK_ID` provisioned only after implementation starts | Deferred |  | Do not place values in docs or source. |
| PayPal | Webhook validation | Create/return/cancel/webhook regression and sandbox evidence completed | Deferred |  | Required before enabling `PAYPAL_ENABLED=true`. |

## IM WSS Inputs

| Input | Required Evidence | Status | Owner | Notes |
|---|---|---:|---|---|
| Browser WSS URL | `IM_WEBSOCKET_URL=wss://<test-domain>/<im-path>` in PHP `.env` | Pending |  | Host must match `STORE_PLATFORM_DOMAIN`. |
| Reverse proxy route | Nginx/Apache/IIS forwards the WSS path to Python IM bind host/port | Pending |  | Preserve upgrade headers. |
| Python bind config | `IM_HOST` is a bind host and `IM_PORT` is an integer | Pending |  | `IM_HOST` must not be a URL. |
| Shared IM secret | Same long `IM_AUTH_SECRET` in PHP and Python IM `.env` | Pending |  | Do not store the value in docs. |
| Database consistency | Python IM DB host/port/name/user matches PHP restored `outer` DB | Pending |  |  |
| Chat table | `IM_CHAT_TABLE` equals PHP `DB_TABLE_PREFIX` + `chat` | Pending |  | Usually `fb_chat`. |
| Service manager | systemd or Supervisor unit installed and enabled | Pending |  | Use provided templates. |
| WSS healthcheck | `im-healthcheck.py` passes against the public WSS URL | Pending |  |  |
| IM regression | Healthcheck, chat regression, auth/payload rejection, and lightweight concurrency pass | Pending |  |  |
| IM evidence report | `mongoyia-im-wss-evidence` references reviewed | Pending |  | Store ticket/config reference only, not secrets. |

## Production-Rehearsal Inputs

| Area | Input | Required Evidence | Status | Owner | Notes |
|---|---|---|---:|---|---|
| Backup | Database backup | Backup archive plus SHA256 sidecar generated outside web root | Pending |  | Run restore drill before relying on it. |
| Backup | Backup verification | `mongoyia-production-backup-verify` report is PASS | Pending |  | Confirms checksum sidecar and archive readability without modifying the database. |
| Backup | Upload backup | Upload archive generated if `web/attachment` is in scope | Pending |  |  |
| Health | Production health report | `mongoyia-production-health` report reviewed | Pending |  | Expected to fail locally until real prod-style config exists. |
| Monitoring | Monitor report | `mongoyia-production-monitor` report reviewed | Pending |  | Feed into scheduler/alerting after test restore. |
| Monitoring | Scheduled check | `mongoyia-production-scheduled-check` wired to cron/Task Scheduler and alerting | Pending |  | Alert on non-zero exit code or `Result: FAIL`. |
| Signoff | Evidence summary | `mongoyia-production-evidence-summary` reviewed | Pending |  | Strict mode should have no generated evidence pending before production. |
| Signoff | Go-live gate | `mongoyia-production-go-live-gate` reviewed with final manual approvals | Pending |  | Store owner/ticket references only, not secrets. |
| Mongolian | Review evidence | `mongoyia-mongolian-review-evidence` reviewed | Pending |  | Store reviewer/ticket/sheet references only, not customer data or secrets. |
| PHP runtime | Upload limits | `upload_max_filesize` and `post_max_size` are at least `6M` | Pending |  |  |
| Security | Secrets | No committed local secrets, weak callback secrets, or hardcoded payment credentials | Pending |  | `mongoyia-security-scan/run --strict=1` should pass. |
| Load | Baseline load smoke | `mongoyia-production-load-smoke` report reviewed | Pending |  | Non-destructive storefront GET checks plus optional IM concurrency. |
| Load | Formal load-test evidence | `mongoyia-production-load-test-evidence` report reviewed with browsing, checkout, payment callback, IM concurrency, datastore/resource, and rollback/monitoring signoffs | Pending |  | Required for production launch, not test-server acceptance. Store report/ticket reference only. |
| Rollback | Rollback plan | `docs/mongoyia-production-rollout-rollback.md` reviewed with restore point, database rollback rule, and DNS/app rollback owner documented | Pending |  |  |

## Execution Order

1. Fill the non-sensitive rows above and provision secrets only on the target server/provider portals.
2. Run `mongoyia-test-server-input-gate` with `-RequireRestoreInputs`.
3. Run `mongoyia-p2-readiness` with `-RequireExternalInputs`.
4. Generate and review the restore plan.
5. Run receiver, restore dry-run, go/no-go, then restore apply only after backup and external inputs are approved.
6. Run full acceptance with `PROFILE=test`, `STRICT=1`, `BASE_URL=https://<test-domain>`, and `IM_URL=wss://<test-domain>/<im-path>`.
7. Run production backup, backup-verify, health, monitor, load-smoke, and formal load-test evidence scripts as rehearsal evidence after test-server acceptance.
