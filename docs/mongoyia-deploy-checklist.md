# Mongoyia Test Deployment Checklist

For the package index, see `docs/mongoyia-package-index.md`.
For the step-by-step test-server runbook, see `docs/mongoyia-test-server-runbook.md`.
For the BaoTa demo deployment on `demo2026.mongoyia.com`, see `docs/mongoyia-baota-deploy-demo2026.md`.
For current scope and remaining production risks, see `docs/mongoyia-delivery-status.md`.

## Environment

- Restore database backup to `outer`.
- Run `php yii migrate/up --interactive=0`.
- Copy `funboot/.env.test.example` to `funboot/.env` and replace every `replace-with-*` value.
- Copy `im后端/im后端/.env.test.example` to `im后端/im后端/.env` and replace every `replace-with-*` value.
- Set `YII_DEBUG=false` and `YII_ENV=test` for the test-server profile.
- Set `DEFAULT_ROUTE=mall`, `DEFAULT_STORE_ID=5`, and `MALL_PLATFORM_OPERATOR_STORE_IDS=5`.
- Keep `BACKEND_ONLY_DOMAINS` empty unless a test domain is intentionally backend-only.
- Keep `HOST_ROUTE_MAP` empty unless extra domains must map to non-default routes. Use `domain:route` pairs when needed.
- Keep `LEGACY_HOST_DOMAINS` populated with old handover domains so they cannot select old stores.
- Set `STORE_PLATFORM_DOMAIN` to the test domain.
- Set `WEB_BASE_URL` to the same test origin used by PHP console jobs.
- Replace every template `example.com` host before running `profile=test` or `profile=prod`; deploy checks fail if template hosts remain.
- If backend store management regenerates `frontend/runtime/host.php`, confirm platform domains map to `mall` and legacy domains are absent.
- Backend store editing rejects legacy handover domains and prevents platform domains from being saved on non-platform stores; data-readiness reports any historical `fb_store.host_name` legacy rows that still need cleanup.
- Set `IM_WEBSOCKET_URL` to the test WebSocket URL, not `127.0.0.1`.
- Set Python IM `IM_HOST` to a bind host such as `0.0.0.0` or `127.0.0.1`, not a URL.
- Set Python IM `IM_PORT` to the service listen port, and keep local localhost `IM_WEBSOCKET_URL` ports aligned with it.
- Set the same long random `IM_AUTH_SECRET` in PHP `.env` and Python IM `.env`.
- Confirm PHP `.env` and Python IM `.env` point to the same restored database host, port, database, and username.
- Configure `REDIS_HOST`, `REDIS_PORT`, and `REDIS_DATABASE`.
- Configure `CHAT_UPLOAD_URL` and `UPLOAD_HTTP_PREFIX` as root-relative paths or absolute HTTPS URLs on the test domain.
- Install/enable PHP runtime requirements: `json`, `redis`, `curl`, `libxml`, `dom`, `gd`, `fileinfo`, `openssl`, `mbstring`, `pdo_mysql`, plus `getimagesize`, `fsockopen`, `hash_hmac`, and `random_bytes`.
- Ensure the PHP/web-server user can write to `runtime`, `frontend/runtime`, `web/assets`, `web/attachment`, and `web/attachment/chat`.
- Set PHP runtime `upload_max_filesize` and `post_max_size` to at least `6M`; chat image upload has a 5MB business limit and strict test/prod deploy checks require oversized files to reach application validation.

## Payment

- Keep `LIANLIAN_SANDBOX=true` on test unless testing production credentials explicitly.
- Set QPay/LianLian sandbox credentials only on test.
- Set `QPAY_AUTH_URL` and `QPAY_INVOICE_URL` from `.env`; do not edit PHP when switching sandbox/provider endpoints.
- Set callback base URLs to HTTPS URLs on the same real test domain as `STORE_PLATFORM_DOMAIN`; do not leave `example.com` hosts.
- Configure callback HMAC secrets and allowed IPs when provider callback ranges are confirmed.

## IM

- In `im后端/im后端`, run `pip install -r requirements.txt`.
- Copy `.env.example` to `.env` and set DB/IM values.
- Start IM using `scripts/start-im.ps1` on Windows or a systemd/Supervisor template on Linux.
- Verify with `scripts/status-im.ps1 -Healthcheck` or `python scripts/im-healthcheck.py --url ws://host:8767`.

## Acceptance Commands

One-command acceptance:

```bash
php yii mongoyia-acceptance/run --baseUrl=https://test.example.com --strict=1 --interactive=0
```

Expected result: deployment check has no failures or warnings, IM healthcheck passes, IM chat regression passes, frontend smoke test passes, backend smoke test passes, and payment regression passes.
When `--cleanupAfterRun=1` is used, acceptance also runs a cleanup verification step and fails if generated regression data remains.

The deployment check also verifies required PHP runtime extensions/functions, writable runtime/assets/attachment directories, required Mongoyia migrations, parent/child order columns, stock idempotency columns, payment-attempt audit columns/indexes, IM chat context/read-state columns/indexes, Python IM bind host/port shape, local IM WebSocket port alignment, payment callback base URL HTTPS/domain readiness, and PHP upload limits for chat image validation.

The acceptance command also runs a security/hardcode scan. It fails on committed local secrets or remote database hardcoding, and strict mode fails on legacy domain/brand warnings.

The acceptance command also runs a data-readiness check before page/payment smoke tests. It verifies the platform store, backend test accounts, payment test user, test products, seller stores, stock, IM merchant/product context, and basic `fb_base_lang` coverage.

Catalog readiness also verifies backend zero-price save/status protection, so remaining zero-price products are tracked as business data warnings rather than sellable products.

By default, the command writes a Markdown report to `runtime/acceptance/`. To choose a fixed report path:

```bash
php yii mongoyia-acceptance/run \
  --baseUrl=https://test.example.com \
  --strict=1 \
  --profile=test \
  --reportPath=runtime/acceptance/mongoyia-test-report.md \
  --interactive=0
```

Use `--noReport=1` only when running in CI or a disposable local check where no acceptance report is needed.

To clean generated regression orders/messages automatically after a successful acceptance run:

```bash
php yii mongoyia-acceptance/run \
  --baseUrl=https://test.example.com \
  --strict=1 \
  --profile=test \
  --cleanupAfterRun=1 \
  --interactive=0
```

Windows PowerShell wrapper:

```powershell
.\console\shell\mongoyia-acceptance.ps1 `
  -BaseUrl "https://test.example.com" `
  -Profile test `
  -Strict `
  -CleanupAfterRun `
  -ImUrl "wss://test.example.com/ws-im"
```

Linux shell wrapper:

```bash
PROFILE=test \
STRICT=1 \
CLEANUP_AFTER_RUN=1 \
BASE_URL=https://test.example.com \
IM_URL=wss://test.example.com/ws-im \
sh console/shell/mongoyia-acceptance.sh
```

Individual commands:

```bash
sh console/shell/mongoyia-test-profile-preflight.sh
php yii deploy-check/run --interactive=0
php yii deploy-check/run --strict=1 --interactive=0
php yii deploy-check/run --profile=test --strict=1 --interactive=0
php yii mongoyia-security-scan/run --interactive=0
php yii mongoyia-data-readiness/run --interactive=0
python ../../im后端/im后端/scripts/im-healthcheck.py --url ws://host:8767
python ../../im后端/im后端/scripts/im-regression.py --url ws://host:8767 --merchant-uid 37 --product-id 102 --store-id 9
php yii mall-smoke-test/run --baseUrl=https://test.example.com --interactive=0
php yii backend-smoke-test/run --baseUrl=https://test.example.com --interactive=0
php yii mall-payment-test/run --baseUrl=https://test.example.com --interactive=0
```

Before replacing template values, you can validate that the template is intentionally rejected by the test profile without touching local `.env`:

```bash
php yii deploy-check/run \
  --phpEnv=.env.test.example \
  --imEnv="../../im后端/im后端/.env.test.example" \
  --profile=test \
  --strict=1 \
  --skipConnectivity=1 \
  --interactive=0
```

Expected template result: failures for placeholder secrets, `example.com` hosts, and any local PHP runtime limits that do not meet test/prod requirements. Replace template values and rerun the preflight wrapper until it reports `0 failure(s), 0 warning(s)`.

Frontend smoke defaults to product `90` and chat product `102`. Override them if test data changes:

```bash
php yii mall-smoke-test/run \
  --baseUrl=https://test.example.com \
  --productIds=90,102 \
  --categoryId=2 \
  --interactive=0
```

The second product id is also used as the default zero-price cart-protection sample. Override it when the test server uses a different business sample:

```bash
php yii mall-smoke-test/run \
  --baseUrl=https://test.example.com \
  --productIds=90,102 \
  --zeroPriceProductId=102 \
  --interactive=0
```

Backend smoke defaults to the local handover test accounts. Override them if the test server uses different accounts:

```bash
php yii backend-smoke-test/run \
  --baseUrl=https://test.example.com \
  --platformUsername=codex_platform_backend_test_5 \
  --platformPassword=CodexTest123 \
  --sellerUsername=zhishichanquan \
  --sellerPassword=123456 \
  --interactive=0
```

Payment regression defaults to products `90,102`. If those products are unavailable or low-stock, the command automatically falls back to two active high-stock products, preferring different seller stores.

When `QPAY_CALLBACK_HMAC_SECRET` or `LIANLIAN_CALLBACK_HMAC_SECRET` is set, payment regression signs normal callbacks and also verifies that missing-signature and invalid-signature callbacks are rejected and written to `fb_mall_payment_attempt`.
When `QPAY_CALLBACK_MAX_AGE_SECONDS` or `LIANLIAN_CALLBACK_MAX_AGE_SECONDS` is greater than `0`, payment regression also verifies that expired-timestamp callbacks are rejected and audited.

For an explicit strict payment callback run:

```bash
php yii mall-payment-test/run \
  --baseUrl=https://test.example.com \
  --qpayCallbackHmacSecret="${QPAY_CALLBACK_HMAC_SECRET}" \
  --lianlianCallbackHmacSecret="${LIANLIAN_CALLBACK_HMAC_SECRET}" \
  --qpayCallbackMaxAgeSeconds="${QPAY_CALLBACK_MAX_AGE_SECONDS}" \
  --lianlianCallbackMaxAgeSeconds="${LIANLIAN_CALLBACK_MAX_AGE_SECONDS}" \
  --interactive=0
```

IM regression defaults to merchant user `37`, product `102`, and store `9`. Override them on the one-command acceptance if the test server uses different test data:

When `IM_AUTH_SECRET` is set, `im-regression.py` also verifies that missing-token, invalid-signature, expired-token, and wrong-user WebSocket authentication attempts are rejected. It also checks that a valid scoped user token cannot send to another merchant, spoof product/store context, or read/mark another merchant conversation. The same regression accepts local `/attachment/` image messages and rejects empty text, overlong text, invalid msg_type, script URLs, and remote image URLs without saving them to chat history.

```bash
php yii mongoyia-acceptance/run \
  --baseUrl=https://test.example.com \
  --strict=1 \
  --profile=test \
  --platformStoreId=5 \
  --paymentProductIds=90,102 \
  --imUrl=ws://host:8767 \
  --imMerchantUid=37 \
  --imProductId=102 \
  --imStoreId=9 \
  --interactive=0
```

## Test Data Cleanup

Acceptance commands generate `REGPAY-*` orders, related payment attempts/order products, and `im_regression_*`, `im_concurrency_*`, or `healthcheck_*` chat rows. Browser chat smoke messages use content prefix `im_regression_browser_`. Cleanup restores stock for generated orders that were deducted but not refunded, then soft-deletes the generated order data and deletes generated chat rows.

## Store Host Cleanup

Before strict test-server signoff, remove old handover domains from `fb_store.host_name` and remove platform domains from non-platform store rows:

```bash
php yii mongoyia-host-cleanup/run --interactive=0
php yii mongoyia-host-cleanup/run --apply=1 --interactive=0
php yii mongoyia-data-readiness/run --interactive=0
```

The cleanup command regenerates `frontend/runtime/host.php` after apply. It does not touch product, order, user, payment, or chat data.

## Catalog Cleanup

After restoring legacy data, inspect active catalog warnings:

```bash
php yii mongoyia-catalog-cleanup/run --interactive=0
php yii mongoyia-catalog-cleanup/run --apply=1 --interactive=0
php yii mongoyia-catalog-readiness/run --interactive=0
```

The cleanup command only promotes active categories with missing/inactive parents to top-level `parent_id=0`. It reports zero-price products but does not set prices or deactivate products; those need business confirmation.

Frontend cart and checkout paths block zero-price products from being purchased. Product detail and chat/customer-service pages can still open so operations can review the product and contact the merchant.

Backend product save and status-change paths also reject active products whose effective price is `0` or lower. After business confirms real prices or deactivation, rerun `mongoyia-catalog-readiness/run` and the frontend/backend smoke tests.

Check generated data without deleting:

```bash
php yii mongoyia-test-cleanup/run --interactive=0
```

Clean only older generated data after the report is accepted:

```bash
php yii mongoyia-test-cleanup/run --apply=1 --olderThanHours=1 --interactive=0
```

Clean all generated regression data immediately:

```bash
php yii mongoyia-test-cleanup/run --apply=1 --olderThanHours=0 --interactive=0
```

Fail a final signoff or CI job if generated regression data remains:

```bash
php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

Use `--includeChat=0` if you want to preserve IM regression chat rows for manual inspection.
