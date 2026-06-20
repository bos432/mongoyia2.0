# Mongoyia Local Baseline

## Snapshot

Date: 2026-06-09

Local URL:

- Mall: `http://127.0.0.1:8089/`
- IM: `ws://127.0.0.1:8767`

Latest full local acceptance report:

```text
runtime/acceptance/mongoyia-acceptance-20260609-044446.md
```

Latest generated local signoff file:

```text
runtime/acceptance/mongoyia-signoff-20260609-044446.md
```

Latest generated local risk register:

```text
runtime/acceptance/mongoyia-risk-register-20260609-044446.md
```

Latest generated local delivery index:

```text
runtime/acceptance/mongoyia-delivery-index-20260609-044446.md
```

Latest validated local handover archive:

```text
runtime/handover/mongoyia-handover-20260609-073834.zip
runtime/handover/mongoyia-handover-20260609-073834.zip.sha256
runtime/handover/mongoyia-handover-20260609-073834
```

Local handover verification reports:

```text
runtime/handover/mongoyia-handover-verify-*.md
```

Latest local worktree inventory:

```text
runtime/handover/mongoyia-worktree-inventory-20260609-073834.md
```

Latest tracked source diff export:

```text
runtime/handover/mongoyia-source-tracked-diff-20260609-073834.patch
runtime/handover/mongoyia-source-tracked-diff-20260609-073834.patch.sha256
runtime/handover/mongoyia-source-diff-export-20260609-073834.md
```

Latest validated untracked source export:

```text
runtime/handover/mongoyia-untracked-source-20260609-073834.zip
runtime/handover/mongoyia-untracked-source-20260609-073834.zip.sha256
runtime/handover/mongoyia-untracked-source-export-20260609-073834.md
```

Use the adjacent `.sha256` file as the checksum source of truth.

The untracked source export contains reviewed source-like files only. SQL dumps, images, demo/runtime output, logs, `.well-known`, and backup `*-0.php` files are excluded for manual review.

Latest validated source handover archive:

```text
runtime/handover/mongoyia-source-handover-20260609-073834.zip
runtime/handover/mongoyia-source-handover-20260609-073834.zip.sha256
```

The source handover archive combines the tracked patch, reviewed untracked source archive, checksums, source reports, and worktree inventory into one reviewable handover unit. It is still not a database dump or full vendor/dependency package.

Latest validated test-server delivery archive:

```text
runtime/handover/mongoyia-test-server-delivery-20260609-073834.zip
runtime/handover/mongoyia-test-server-delivery-20260609-073834.zip.sha256
runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz
runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz.sha256
```

The test-server delivery archive wraps the handover archive, source handover archive, preflight report, and verification report for transfer. It intentionally excludes SQL dumps and real `.env` files.

Latest local test-server preflight report script smoke:

```text
runtime/handover/mongoyia-test-server-preflight-20260609-073834.md
```

This local report was generated with `Profile local`, `Strict 0`, `SkipConnectivity`, and `SkipApi` to verify the reporting wrapper itself. Final test-server preflight must use `Profile test`, `Strict 1`, the real HTTPS base URL, and no placeholder `.env` values.

Result: PASS

Profile: `local`

The report includes a `Signoff Summary` table with step count, cleanup verification, account/product context, and warning/failure extracts for signoff copying.

## Local Check Results

Deployment check:

```text
0 failure(s), 12 warning(s)
```

The 12 warnings are expected locally:

- PHP `IM_AUTH_SECRET` is the local placeholder.
- Python IM `IM_AUTH_SECRET` is the local placeholder.
- `IM_WEBSOCKET_URL` points to localhost.
- Local `IM_WEBSOCKET_URL` port `8767` matches Python IM `IM_PORT=8767`.
- `WEB_BASE_URL` is local while `STORE_PLATFORM_DOMAIN` is `www.mongoyia.com`.
- PHP `upload_max_filesize=2M` is below the 6M test/prod requirement; local smoke accepts the PHP-layer upload error while test/prod must let oversized chat images reach application validation.
- QPay sandbox values are empty.
- LianLian sandbox values are empty.
- QPay/LianLian callback HMAC secrets are empty.

Security scan:

```text
0 failure(s), 0 warning(s)
```

API smoke:

```text
/api, /api/site/index, and /api/v1/default/index return HTTP 200 JSON. /api/site/profile returns HTTP 401 without a PHP/Yii fatal error.
```

Data readiness:

```text
0 failure(s), 0 warning(s)
```

Local store host cleanup has been applied. `fb_store.host_name` no longer contains old handover domains or platform domains on non-platform store rows, and `mongoyia-host-cleanup/run` dry-run reports no pending cleanup.

Handover package check:

```text
0 failure(s)
```

Translation readiness:

```text
0 failure(s), 0 warning(s)
```

Catalog readiness:

```text
0 failure(s), 3 warning(s). Active catalog has 27 products across 3 stores. Product categories have valid active parents. Backend zero-price save/status protections pass. Products 89,102,103 still have zero price and need business pricing or deactivation confirmation.
```

Local catalog cleanup has been applied for orphan active categories. `mongoyia-catalog-cleanup/run` dry-run reports no orphan category cleanup pending and still reports zero-price products only.
Zero-price products are blocked from cart/checkout purchase paths, but product detail and chat/customer-service entry remain available for review and merchant follow-up.
Backend product save/status paths also block zero-price products from being saved or switched back to active status. Existing zero-price active products remain a business pricing/deactivation task and are intentionally not auto-modified locally.

Frontend language text smoke:

```text
product en, product mn, cart mn, chat en, and chat mn contain no visible Chinese text outside the language switch allowlist. Chat entry also validates product 102, store 9, merchant 37, WebSocket/upload/token config, localized customer-service labels/placeholders, language-aware token/upload URLs, signed `/mall/chat/token` response, localized English/Mongolian token/upload/type/invalid-image/oversize error messages, successful 1x1 PNG chat upload metadata/URL, and localized frontend WebSocket error fallback text in the chat page script. Public Mongolian layout smoke verifies header free-shipping text, cart label, breadcrumb home label, search placeholder, and Cookie bar text/button stay localized. Zero-price product 102 is rejected by `/mall/cart/edit-ajax` with a price business error and does not create cart rows. Host-route smoke verifies `mongoyia.com`, `mn.zlck888.com`, and `www.funpay.com` render Mongoyia mall content instead of legacy FunPay/site content.
```

Backend tenant isolation smoke:

```text
Platform backend can access cross-store records; seller backend can access own product and is blocked from other-store product, parent order, and other-store child order. Platform and seller customer-service workbenches render the expected IM identity, signed authToken, WebSocket/upload config, auto-connect script, and platform storeMap context.
```

IM auth regression:

```text
IM chat regression sends and receives user/merchant messages, verifies history/list context, and rejects missing-token, invalid-signature, expired-token, and wrong-user auth attempts when IM_AUTH_SECRET is configured. It also rejects scoped-token overreach: unauthorized target_uid, product_id, store_id, chat_history uid, and mark_read uid. Payload regression accepts local `/attachment/` image messages and rejects empty text, overlong text, invalid msg_type, script URLs, and remote image URLs without saving them to chat history.
```

Order integrity:

```text
New parent/child orders pass amount, quantity, order-product store, and product ownership checks. Legacy parent orders still have 241 active order-product rows and should be treated as historical cleanup/migration risk.
```

Payment audit:

```text
Recent successful payment attempts match paid parent orders and no gateway success transaction is shared across multiple orders. Legacy paid parent orders 263,261,258,255,252,247,240,237 are missing successful audit rows and should be treated as historical audit-coverage risk.
```

Payment callback HMAC regression:

```text
The normal local profile keeps QPay/LianLian callback HMAC secrets and max-age enforcement empty, so the payment regression reports those protection checks as skipped. A temporary local PHP service on port 8091 was run with QPAY_CALLBACK_HMAC_SECRET, LIANLIAN_CALLBACK_HMAC_SECRET, QPAY_CALLBACK_MAX_AGE_SECONDS, and LIANLIAN_CALLBACK_MAX_AGE_SECONDS set; signed QPay/LianLian callbacks passed, while missing-signature, invalid-signature, and expired-timestamp callbacks were rejected and audited.
```

Generated test-data cleanup dry-run:

```text
orders            0
order_products    0
payment_attempts  0
stock_refunds     0
chat_messages     0
chat_files        0
```

## Key Local Data

Products:

| Product | Store | Stock | Price | Use |
|---:|---:|---:|---:|---|
| 90 | 13 | 99999 | 8.00 | product/payment smoke |
| 102 | 9 | 99999 | 0.00 | IM/chat smoke |

Acceptance data:

- Platform backend: `codex_platform_backend_test_5`
- Seller backend: `zhishichanquan`
- Platform store id: `5`
- Payment user id: `71`
- IM merchant uid: `37`
- IM product id: `102`
- IM store id: `9`

Translation baseline:

- Active English rows in `fb_base_lang`: `776`
- Active Mongolian rows in `fb_base_lang`: `403`

## Local Command Used

```powershell
.\console\shell\mongoyia-acceptance.ps1 `
  -BaseUrl "http://127.0.0.1:8089" `
  -CleanupAfterRun
```

Use `-NoReport` only for disposable local checks where no report file is needed.

## Test Server Difference

The test server must not use the local profile as proof of acceptance. Test-server final acceptance must use:

```bash
php yii mongoyia-acceptance/run \
  --baseUrl=https://<test-domain> \
  --profile=test \
  --strict=1 \
  --cleanupAfterRun=1 \
  --interactive=0
```

Expected test-server result:

- Deployment check: `0 failure(s), 0 warning(s)`
- Security scan: `0 failure(s), 0 warning(s)`
- Data readiness: `0 failure(s), 0 warning(s)`
- Translation, IM healthcheck, chat, concurrency, API, frontend, backend, and payment regressions pass.
- Cleanup dry-run returns all generated counts as `0`.

The signoff template and `mongoyia-test-profile-preflight` wrappers track the same strict environment gates: host routing, legacy-domain isolation, real non-example test/prod hosts, HTTPS/WSS, Python IM bind host/port shape, local IM WebSocket port alignment, PHP/Python IM database consistency, chat/upload URL shape, PHP runtime requirements, writable runtime/assets/attachment paths, PHP upload limits, IM secrets, Redis, payment sandbox credentials, callback base URLs using HTTPS on the platform domain, HMAC secrets, and callback max-age.
