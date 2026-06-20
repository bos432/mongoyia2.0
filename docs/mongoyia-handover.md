# Mongoyia test handover notes

## Environment

Copy `.env.example` to `.env` and adjust values per machine. Local/test/prod should differ only by `.env`.

Important keys:

- `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`: MariaDB/MySQL connection.
- `DEFAULT_STORE_ID=5`, `DEFAULT_ROUTE=mall`, `STORE_PLATFORM_DOMAIN=www.mongoyia.com`: Mongoyia platform store defaults.
- `MALL_PLATFORM_MODE=true`: front mall shows active products across merchants.
- `MALL_PLATFORM_STORE_IDS=`: optional comma-separated allowlist. Leave empty to include all active products.
- `MALL_PLATFORM_OPERATOR_STORE_IDS=5`: backend store ids that can operate the platform mall and view all seller child orders.
- `UPLOAD_HTTP_PREFIX=/attachment/`: public upload URL prefix.
- `IM_WEBSOCKET_URL`, `IM_HOST`, `IM_PORT`, `IM_CHAT_TABLE`: customer-service WebSocket and chat table config.
- `FRONTEND_TRANSLATE_ENABLED=false`: keep full-page runtime translation off by default.
- `GOOGLE_TRANSLATE_PROXY=`: optional proxy for PHP cURL when running translation batches from networks that cannot reach Google directly.
- `QPAY_*`, `LIANLIAN_*`: payment sandbox/prod values. Do not hardcode new secrets in PHP.

## Restore

Use the current database dump as the test baseline:

```bash
mysql -u outer -p -e "CREATE DATABASE IF NOT EXISTS outer DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u outer -p outer < ../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql
```

Start PHP from `web` with a local port, for example:

```bash
php -S 127.0.0.1:8089 -t web web/index.php
```

## Deployment Check

Run the deployment configuration check after copying `.env`, restoring the database, starting Redis, and starting Python IM:

```bash
php yii deploy-check/run --interactive=0
```

The command checks:

- Required PHP `.env` keys for database, Redis, Mongoyia platform mode, upload URL, IM WebSocket, and IM auth.
- Required Python IM `.env` keys and whether PHP/Python `IM_AUTH_SECRET` match.
- Database, Redis, and IM socket connectivity.
- Required Mongoyia migrations and schema changes for parent orders, stock idempotency, payment attempts, and IM chat context/read-state.
- Payment provider credentials and callback HMAC configuration presence.

Useful options:

```bash
php yii deploy-check/run --strict=1 --interactive=0
php yii deploy-check/run --profile=test --strict=1 --interactive=0
php yii deploy-check/run --phpEnv=.env.test.example --imEnv="../../im后端/im后端/.env.test.example" --skipConnectivity=1 --interactive=0
```

`--phpEnv` and `--imEnv` let you validate template files without replacing the active local `.env`. `--skipConnectivity=1` checks file shape and secrets only, without opening DB, Redis, or IM sockets.

Current local baseline result on 2026-06-08:

```text
0 failure(s), 11 warning(s)
```

The remaining local warnings are expected for this machine: local IM secret, localhost WebSocket URL while the platform domain is `www.mongoyia.com`, empty QPay/LianLian credentials, and empty callback HMAC secrets.

For a test server or production-like environment, use strict mode:

```bash
php yii deploy-check/run --profile=test --strict=1 --interactive=0
```

Strict mode returns a non-zero exit code for either failures or warnings. The `test` profile additionally requires HTTPS/WSS URLs, real IM secrets, sandbox payment credentials, callback HMAC secrets, and callback replay windows.

## Translation Fill

The mall translation command uses `common\helpers\GoogleTranslate` with the `gtx` endpoint by default. `--allStores=1` scans active real seller stores, so products owned by sellers such as store `9` and `13` are not skipped by the platform store id.

Single platform store:

```bash
php yii mall-translate/fill --storeId=5 --targets=en,mn --limit=50
```

All active mall stores:

```bash
php yii mall-translate/fill --allStores=1 --targets=en,mn
```

Focused product/category batches:

```bash
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=product --ids=90,102 --fields=name,brief --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=category --ids=94,106 --fields=name,brief --dryRun=1 --interactive=0
php yii mall-translate/fill --allStores=1 --targets=en,mn --models=category --ids=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --fields=name --dryRun=1 --interactive=0
```

Dry run:

```bash
php yii mall-translate/fill --allStores=1 --targets=en,mn --dryRun=1 --limit=20
```

Reports are written to `runtime/translation/mall-translate-fill-*.md`. If PHP cURL times out while browser/system requests can reach Google, set `GOOGLE_TRANSLATE_PROXY` in `.env` or pass `--proxy=<host:port>` for that run.

Audit the current translation baseline before and after fill:

```bash
php yii mongoyia-translation-audit/run --interactive=0
php yii mongoyia-translation-readiness/run --strict=1 --productIds=90,102 --categoryIds=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --interactive=0
```

`mongoyia-translation-audit/run` is report-only. It flags missing rows, Chinese residue in non-Chinese targets, same-as-source rows, and duplicate active `fb_base_lang` rows. Same-as-source category samples such as test/demo labels are content QA warnings unless the project owner confirms they should be translated.

Migration `m260608_190000_mongoyia_focused_translations` seeds the verified en/mn baseline for products `90/102`, focused categories `94/106`, and homepage core categories `93-114` after a fresh SQL restore. Full product/category coverage still requires a controlled batch run and Mongolian manual QA.

## IM

The Python IM service reads `.env` from its current working directory.

```bash
pip install websockets aiomysql
python main.py
```

Make sure its `DB_*` and `IM_*` values match the PHP `.env`.
`IM_CHAT_TABLE` defaults to `${DB_TABLE_PREFIX}chat`; set it explicitly when the restored database uses a different table name.

Current local IM baseline:

- Python IM source: `../../im后端/im后端/main.py`.
- PHP user chat page: `/mall/chat/index?gid=<product_id>`.
- PHP seller service page: `/backend/mall/kf/index`.
- Chat storage table: `fb_chat`.
- WebSocket URL: `IM_WEBSOCKET_URL=ws://127.0.0.1:8767`.
- WebSocket auth secret: `IM_AUTH_SECRET` must match between PHP `.env` and Python IM `.env`.
- Chat image upload URL: `CHAT_UPLOAD_URL=/mall/chat/upload`.
- Chat product/store context migration: `m260608_184000_mongoyia_chat_context`.
- Chat read-state migration: `m260608_185000_mongoyia_chat_read_state`.
- IM Windows scripts:
  - `../../im后端/im后端/scripts/start-im.ps1`
  - `../../im后端/im后端/scripts/stop-im.ps1`
  - `../../im后端/im后端/scripts/status-im.ps1`
  - `../../im后端/im后端/scripts/im-healthcheck.py`
- IM Linux deploy templates:
  - `../../im后端/im后端/deploy/mongoyia-im.service.example`
  - `../../im后端/im后端/deploy/supervisor-mongoyia-im.conf.example`

## Acceptance Smoke

Minimum test pages:

- `/`
- `/product/90?lang=en`
- `/product/90?lang=mn`
- `/category/view?keyword=`
- `/mall/cart/index`
- `/mall/default/login`
- IM chat page and WebSocket connection

Minimum order checks:

- Add a product with no SKU to cart.
- Add a product with SKU to cart.
- Checkout a cart containing products from different product `store_id` values.
- Verify `fb_mall_order_product.store_id` keeps each product owner store.
- Verify payment callback does not use real production credentials on test.

Automated frontend smoke:

```bash
php yii mall-smoke-test/run --interactive=0
php yii mall-smoke-test/run --baseUrl=https://test.example.com --productIds=90,102 --interactive=0
```

The smoke command checks homepage, product detail in Chinese/English/Mongolian, cart, login, and customer-service chat entry. It fails on non-2xx/3xx responses and obvious Yii/PHP fatal markers.

Automated backend smoke:

```bash
php yii backend-smoke-test/run --interactive=0
php yii backend-smoke-test/run --baseUrl=https://test.example.com --interactive=0
```

The backend smoke command logs in with the platform and seller handover test accounts, then checks dashboard, product list, order list, order-product list, payment-attempt list for platform, and the customer-service workbench role rendering.

One-command acceptance:

```bash
php yii mongoyia-acceptance/run --interactive=0
php yii mongoyia-acceptance/run --baseUrl=https://test.example.com --profile=test --strict=1 --interactive=0
```

Wrapper scripts are available for repeated test-server runs:

```powershell
.\console\shell\mongoyia-acceptance.ps1 -BaseUrl "https://test.example.com" -Profile test -Strict -CleanupAfterRun -ImUrl "wss://test.example.com/ws-im"
```

```bash
PROFILE=test STRICT=1 CLEANUP_AFTER_RUN=1 BASE_URL=https://test.example.com IM_URL=wss://test.example.com/ws-im sh console/shell/mongoyia-acceptance.sh
```

The acceptance command runs deployment configuration check, IM protocol healthcheck, IM chat regression, frontend smoke, backend smoke, and payment regression in sequence. Local mode can run without `--strict`; test server final acceptance should use `--profile=test --strict=1`.

It also runs a data-readiness check before page/payment smoke tests:

```bash
php yii mongoyia-data-readiness/run --interactive=0
```

This verifies the platform store, backend test accounts, payment test user, test products, seller stores, stock, IM merchant/product context, and basic `fb_base_lang` coverage. Override IDs/usernames on `mongoyia-acceptance/run` when the test server uses different acceptance data.

Standalone IM regression:

```bash
cd ../../im后端/im后端
python scripts/im-regression.py --url ws://127.0.0.1:8767 --merchant-uid 37 --product-id 102 --store-id 9
```

It opens a user WebSocket and a merchant WebSocket, sends a user message, verifies merchant receipt, sends a merchant reply, verifies user receipt, checks chat history, and confirms the merchant chat list includes the test session.

Acceptance reports:

```bash
php yii mongoyia-acceptance/run --baseUrl=https://test.example.com --strict=1 --reportPath=runtime/acceptance/mongoyia-test-report.md --interactive=0
```

The report is Markdown and records each step's command, exit code, duration, working directory, and output. Password, secret, and token arguments are redacted. Local generated reports are written under `runtime/acceptance/`.

Generated test-data cleanup:

```bash
php yii mongoyia-test-cleanup/run --interactive=0
php yii mongoyia-test-cleanup/run --apply=1 --olderThanHours=1 --interactive=0
```

The cleanup command is dry-run by default. It only targets generated `REGPAY-*` orders/payment audit rows/order products, generated IM `im_regression_*`, `im_concurrency_*`, or `healthcheck_*` chat rows, browser chat smoke messages whose content starts with `im_regression_browser_`, and uploaded chat smoke files matching `web/attachment/chat/YYYY/MM/DD/chat_smoke_*.png`. For generated orders with stock deducted but not refunded, cleanup restores stock first and then soft-deletes the generated order rows.

## Payment Acceptance

Test payments should use sandbox credentials only. Keep QPay and LianLian secrets in `.env`; never place real keys in PHP or SQL dumps.

Payment callbacks now reject unsafe success attempts unless all checks pass:

- POST callback status must be successful: `SUCCESS`, `PAID`, `PAY_SUCCESS`, `PS`, or `COMPLETED`.
- Callback amount must be present and match the parent order amount within `0.01`.
- Callback merchant transaction id must match the expected local id: QPay `1234567{id}`, LianLian `Test-pay{id}`.
- Optional HMAC callback signature can be enforced with `QPAY_CALLBACK_HMAC_SECRET` and `LIANLIAN_CALLBACK_HMAC_SECRET`.
- Optional callback source allowlist can be enforced with `QPAY_CALLBACK_ALLOWED_IPS` and `LIANLIAN_CALLBACK_ALLOWED_IPS`. Use comma-separated IPv4 addresses or CIDR ranges.
- Optional replay window can be enforced with `QPAY_CALLBACK_MAX_AGE_SECONDS` and `LIANLIAN_CALLBACK_MAX_AGE_SECONDS`. Set `0` to disable it.
- Parent and child orders can only transition to paid from unpaid, paying, or already paid.
- Refund or invalid payment states cannot be revived by a delayed success callback.
- Stock deduction is guarded by `stock_deducted_at` and only runs once.
- Callback processing uses MySQL `GET_LOCK` to avoid concurrent double-processing.
- `fb_mall_payment_attempt` records create, return, query, and callback audit events.

Automated local regression:

```bash
php yii mall-payment-test/run --interactive=0
```

The command creates temporary parent/child orders with `REGPAY-*` order numbers and posts real local callbacks to the configured `--baseUrl` (default `http://127.0.0.1:8089`). It covers:

- QPay success and duplicate success callbacks.
- QPay paid duplicate callbacks with mismatched amount are rejected and audited.
- QPay amount mismatch.
- QPay delayed success callback against a refunded order.
- QPay missing and wrong merchant transaction id.
- LianLian success and duplicate success callbacks.
- LianLian paid duplicate callbacks with mismatched amount are rejected and audited.
- LianLian amount mismatch.
- Paid parent-order refund, seller child-order refund sync, and stock return.
- Duplicate refund idempotency and invalid refund rejection for unpaid/child orders.
- Paid parent-order shipment, seller child-order shipment sync, and duplicate shipment idempotency.
- Seller child-order receipt sync back to parent order, plus invalid shipment/receipt rejection for unpaid/refunded orders.

Historical paid orders that predate `fb_mall_payment_attempt` can be reviewed with:

```bash
php yii mongoyia-payment-audit-backfill/run --interactive=0
```

This command is dry-run by default. Only use `--apply=1` after business approval to insert synthetic `legacy/backfill` success audit rows for historical paid parent orders.

Backend order acceptance:

- `/backend/mall/order/index` shows whether a row is a parent payment order or seller child order.
- Parent rows show seller child-order count.
- Child rows link back to the parent payment order.
- `/backend/mall/order/view?id=<id>` shows order relationship, seller child orders, and recent payment attempts.
- Payment attempt links use the parent payment order id for child orders.
- Parent order details show seller order products, true seller store, product id, SKU, quantity, price, and line amount.
- Seller child-order details show their own order products.
- Platform operators, configured by `MALL_PLATFORM_OPERATOR_STORE_IDS`, can view parent payment orders, all seller child orders, and payment attempt audit logs.
- Seller backend users can only list and open their own seller child orders (`parent_id > 0` and matching `store_id`).
- Seller backend users cannot open parent payment orders, other seller child orders, or platform payment attempt audit details by direct URL.
- Seller backend users can open the shipment form for their own seller child orders.
- Seller backend users cannot edit, delete, import, export, refund, batch-edit fields, or batch-change status for orders.
- Platform operators can use full order management actions, including parent-order refund.
- `/backend/mall/order-product/index` and `/backend/mall/order-product/js` are also scoped by platform/seller role.
- Seller backend users can only see their own paid order-product rows and their own sales statistics.
- Platform operators can see order-product rows and sales statistics across sellers.
- Seller backend users cannot edit, delete, export, or batch-change order-product rows by direct URL.

Backend product/category acceptance:

- `/backend/mall/product/index` now uses `MALL_PLATFORM_OPERATOR_STORE_IDS` instead of guessing a store from `fb_store.user_id`.
- Platform operators can list, view, edit, approve, and export products across seller stores.
- Seller backend users can list, view, edit, and export only their own products.
- Seller backend users cannot open or edit another seller's product by direct URL.
- Product edit keeps the product's true `store_id` when a platform operator edits seller-owned products.
- Product SKU, tag, attribute label, and parameter writes use the product owner store instead of the current operator store.
- Product save no longer dumps raw validation errors with `var_dump(...); exit()` on failure.
- Product save tolerates missing tag arrays and missing/deleted attribute sets instead of throwing PHP warnings/errors.
- `/backend/mall/category/index` reads the platform category tree from the configured platform operator store, currently store `5`.
- Platform operators can create, edit, delete, import, and export platform categories.
- Seller category management is blocked at controller level. The current seller roles do not expose category as a normal menu path.

Backend upload/product image acceptance:

- Local uploads now honor `UPLOAD_HTTP_PREFIX=/attachment/` instead of forcing the current request host into saved URLs.
- `UPLOAD_TAKEOVER_URL` can still take over the upload URL prefix when explicitly configured.
- Local uploaded image rows store portable URLs such as `/attachment/images/2026/06/08/image_260608_904568241915953152.jpg`.
- Existing absolute online attachment URLs still render through the normal image helper path; this change only affects newly uploaded files.
- Attachment create failure no longer dumps raw validation errors with `var_dump(...); exit()`.
- Upload failure now returns a clear application error if the file cannot be written or the attachment row cannot be saved.
- The uploader image path type check no longer assigns `uploadType` accidentally.
- Seller store 9 uploaded `petever1.jpg` through `/backend/file/image`; the response returned HTTP 200, attachment id `371`, store `9`, created_by `37`, and a `/attachment/...` URL.
- The physical image exists under `web/attachment/images/2026/06/08/` and `http://127.0.0.1:8089/attachment/images/2026/06/08/image_260608_904568241915953152.jpg` returned HTTP 200.
- Seller store 9 created temporary product `CODEX-IMAGE-*` through `/backend/mall/product/edit` using that uploaded URL for `thumb`, `image`, and `images`.
- The temporary image product was saved as store `9`, category `61`, status `0` pending approval, with all image fields using `/attachment/...`.
- Temporary `CODEX-IMAGE-*` products were soft-deleted after the smoke test; active `CODEX-IMAGE-*` product count is `0`.

IM/customer-service acceptance:

- `frontend/modules/mall/controllers/ChatController.php` now resolves product seller service id with parameterized queries and returns a clear 404 for missing/invalid product ids.
- User chat page now responds to WebSocket heartbeat messages, preventing the Python IM server from disconnecting active users after the heartbeat timeout.
- User and seller chat pages both understand Yii upload responses shaped as `{code, data: {url}}`.
- Python IM now returns errors with `type=error`, so the frontend can show processing failures.
- Chat image upload now uses `/mall/chat/upload`, a restricted public image-only endpoint for customer-service attachments. It allows only real image files up to 5 MB and saves them under `/attachment/chat/YYYY/MM/DD/`.
- The seller customer-service workbench `/backend/mall/kf/index` now auto-connects to the configured IM WebSocket after page load.
- The seller workbench now has a quieter connection state, local session search, empty-state text, local unread badges, and auto-reconnect after accidental WebSocket disconnects.
- The seller workbench no longer depends on the browser global `event` when opening a chat session.
- User and seller chat pages render text messages through DOM text nodes instead of injecting message content as HTML.
- User and seller chat pages only preview same-origin `/attachment/...` chat images, preventing arbitrary image URL injection in the chat bubble.
- The user chat input height auto-resize no longer overrides send-button state updates.
- `fb_chat` now has `product_id` and `store_id`, so customer-service messages can keep the product and seller-store context from `/mall/chat/index?gid=<product_id>`.
- Python IM detects whether `product_id` and `store_id` exist. It writes and returns them when present, and remains compatible with an unmigrated old `fb_chat` table.
- User chat sends `product_id` and `store_id` with text and image messages.
- Seller chat list, chat header, seller replies, and chat history now preserve and display the latest product/store context for the session.
- `fb_chat` now has `user_read_at` and `merchant_read_at`, so unread state survives seller workbench refresh and user chat refresh.
- Python IM now returns `unread_count` in chat lists and supports a `mark_read` message for live open conversations.
- Seller workbench unread badges use server `unread_count`, with local WebSocket increments only as a short-lived UI bridge before the next list refresh.
- User chat and seller chat both mark messages as read when chat history is opened; live messages are also marked read when the relevant chat window is already open.
- Platform customer-service operators now connect to IM with `userType=platform`.
- Platform customer-service chat list can see sessions across seller service users, while ordinary seller users still see only their own sessions.
- Platform customer-service replies preserve the real seller `uid`, so replies remain in the original user-to-seller conversation instead of becoming platform-owned messages.
- Platform customer-service receives live user messages broadcast from all seller conversations.
- The platform workbench displays seller/store ownership in the chat list and header using the backend store user-id map.
- IM WebSocket initial handshake now supports HMAC auth tokens. When `IM_AUTH_SECRET` is configured, unsigned clients are rejected.
- User chat obtains an IM auth token from `/mall/chat/token`; backend seller/platform workbenches receive their token from server-rendered PHP.
- User IM tokens bind the browser user id to the product seller `uid`, product id, and store id. A user token for one seller cannot send chat messages to another seller.
- Seller/platform IM tokens bind the backend user id and role. Platform tokens are required before `userType=platform` can connect.
- `/mall/chat/index?gid=102` returned HTTP 200 locally and rendered `merchantId: 37`, matching product 102's seller service user.
- `/backend/mall/kf/index` returned HTTP 200 for seller `zhishichanquan / 123456` and rendered `const userId = 37`.
- Python IM was started locally on port `8767` with PID `35068`.
- WebSocket simulation passed: user `codex_im_1780915007` sent to merchant `37`, merchant replied, both sides received messages, and history returned both rows. Inserted message ids were `57` and `58`.
- A second IM regression inserted message id `59` and confirmed user echo plus merchant receive.
- A seller-workbench regression passed with user `codex_im_workbench_1780915680`: user message, seller reply, and two-row history all returned through `ws://127.0.0.1:8767`.
- A UI-safety regression passed with user `codex_im_ui_1780915995`: a text message containing `<script>alert(1)</script>` round-tripped through IM and is now safe-rendered by the PHP pages.
- Migration `m260608_184000_mongoyia_chat_context` was applied locally. `fb_chat` columns are now `id, from, uid, product_id, store_id, content, type, time, uuid`.
- Python IM was restarted locally on port `8767` with PID `54240` after the context change.
- Context regression passed with user `codex_im_ctx_1780916452`: user message, merchant receive, chat list, merchant reply, user receive, and history all returned `product_id=102` and `store_id=9`.
- Database rows `64` and `65` for `codex_im_ctx_1780916452` both stored `product_id=102` and `store_id=9`.
- Browser verification for `/mall/chat/index?gid=102` showed `商品 #102 · 店铺 #9` and the chat input was connected.
- Seller backend HTTP verification for `/backend/mall/kf/index` returned HTTP 200 and included the context-aware workbench code for user `37`.
- Migration `m260608_185000_mongoyia_chat_read_state` was applied locally. `fb_chat` now includes `user_read_at` and `merchant_read_at`.
- Python IM was restarted locally on port `8767` with PID `51372` after the read-state change.
- Unread regression passed with user `codex_im_read_1780916907`: seller chat list showed unread `1` after a user message, `chat_history` returned `read_count=1`, and the seller list returned unread `0` after opening the conversation.
- The same regression confirmed user-side read state: after a seller reply, user `chat_history` returned `read_count=1`.
- Database rows `66` and `67` for `codex_im_read_1780916907` stored `product_id=102`, `store_id=9`, `user_read_at`, and `merchant_read_at`.
- Seller backend HTTP verification for `/backend/mall/kf/index` returned HTTP 200 and included the unread-count and `mark_read` workbench code for user `37`.
- IM now has `requirements.txt`, Windows start/stop/status scripts, a WebSocket+DB health check, and Linux systemd/Supervisor templates.
- Windows script-managed IM start was verified locally. PID file `run/im.pid` was created and the final script-managed IM process listened on port `8767` with PID `46136`.
- `scripts/status-im.ps1 -Healthcheck` returned `OK ws://127.0.0.1:8767`.
- `scripts/stop-im.ps1 -Force` and `scripts/start-im.ps1` were both exercised locally; the IM process restarted successfully.
- Operations regression passed with user `codex_im_ops_1780917288`: unread count, read count, and product/store context still worked after script-managed restart.
- Platform customer-service regression passed with users `codex_platform_fix_a_1780917632` and `codex_platform_fix_b_1780917632`: platform IM received live messages for seller users `37` and `41`, chat list showed both seller sessions, opening seller `41` history marked unread `1` to `0`, and platform reply reached the user while preserving seller uid `41`.
- Backend platform account `codex_platform_backend_test_5 / CodexTest123` rendered `/backend/mall/kf/index` as `userType=platform` and included store names for seller ownership.
- Backend seller account `zhishichanquan / 123456` rendered `/backend/mall/kf/index` as `userType=merchant` and did not include the platform store map.
- PHP `.env` and Python IM `.env` were both configured with `IM_AUTH_SECRET=local-im-auth-secret` locally.
- IM was restarted locally with signed handshakes enabled; final signed-mode PID was `14788`.
- Unsigned platform connection was rejected with `Missing auth token`.
- `/mall/chat/token` returned a signed user token for product `102` and user `codex_token_http`.
- Signed IM regression passed with user `codex_auth_1780918219`: user message, merchant receive, platform receive, platform history read, and platform reply all worked while preserving seller uid `37`, product `102`, and store `9`.
- Unauthorized target regression passed: a user token scoped to seller `37` was rejected when trying to send to seller `41` with `Unauthorized target_uid`.
- Public chat upload accepted `petever1.jpg`, returned `/attachment/chat/2026/06/08/chat_260608_114020_c1acy1idbon9.jpg`, and that image returned HTTP 200.

Local backend test accounts:

```text
platform: codex_platform_backend_test_5 / CodexTest123
seller store 9: zhishichanquan / 123456
seller store 13: 创客 / 123456
admin: admin / 123456
```

Local backend visibility checks performed against parent order `437`, seller store 9 child order `438`, seller store 13 child order `439`, and payment attempt `100`:

- Platform test account returned HTTP 200 for parent `437`, child `438`, child `439`, payment attempt `100`, and `/backend/mall/order/index`.
- Seller store 9 returned HTTP 200 for child `438`, and was redirected/blocked for parent `437`, child `439`, and payment attempt `100`.
- Seller store 13 returned HTTP 200 for child `439`, and was redirected/blocked for parent `437`, child `438`, and payment attempt `100`.
- Seller order list `/backend/mall/order/index` returned HTTP 200 for both store 9 and store 13 when requested after a fresh login.
- Seller store 9 order list showed shipment links only; row-level edit/delete/refund links and toolbar create/import/export links were hidden.
- Seller store 9 direct requests to order export, import, field edit, status edit, and delete returned HTTP 403.
- Temporary permission test order `REGPERM-*` parent `476` / child `477` remained active after seller store 9 attempted a direct delete request.
- Seller store 9 `/backend/mall/order-product/index` and `/backend/mall/order-product/js` returned HTTP 200 with store 9 data only and no store 13 data.
- Seller store 9 could open order-product `436` and was redirected/blocked from store 13 order-product `437`.
- Seller store 9 direct requests to order-product edit, delete, export, and status edit returned HTTP 403.
- Platform test account could open order-product rows for both store 9 and store 13.
- Platform product list returned HTTP 200 and showed store 9 product `102` and store 13 product `105`.
- Platform product view/edit returned HTTP 200 for store 9 product `102` and store 13 product `105`.
- Seller store 9 product list returned HTTP 200, showed store 9 product `102`, and did not show store 13 product `105`.
- Seller store 9 direct edit for store 13 product `105` returned HTTP 403.
- Seller store 13 product list returned HTTP 200, showed store 13 product `105`, and did not show store 9 product `102`.
- Platform category edit for platform category `2` returned HTTP 200 in the local browser session.
- Seller store 9 created temporary product `CODEX-SMOKE-*` through `/backend/mall/product/edit`; it was saved as store `9` with status `0` pending approval.
- Platform account edited that temporary seller product through `/backend/mall/product/edit?id=<id>`; it remained store `9` and moved to status `1`.
- Temporary `CODEX-*` products were soft-deleted after the smoke test; active `CODEX-*` product count is `0`.

Required migration for sales-stat permission:

```bash
php yii migrate/up --interactive=0
```

This applies pending local migrations, including:

- `m260608_183000_mongoyia_order_product_stats_permission`, assigning `/mall/order-product/js` to store roles `50` and `55`. Code-level store scoping still prevents sellers from seeing other sellers' statistics.
- `m260608_184000_mongoyia_chat_context`, adding `product_id` and `store_id` to `fb_chat` for product-detail customer-service context.
- `m260608_185000_mongoyia_chat_read_state`, adding `user_read_at` and `merchant_read_at` to `fb_chat` for persistent unread counts.

After applying chat-related migrations on a test server, restart Python IM so it reloads the detected chat columns.

IM auth setup:

```env
# PHP .env and Python IM .env must match.
IM_AUTH_SECRET=replace-with-a-long-random-secret
IM_AUTH_TOKEN_TTL=3600
```

If `IM_AUTH_SECRET` is empty, Python IM falls back to the inherited unsigned handshake. Use that only for emergency local debugging.

Windows IM operations:

```powershell
cd "..\..\im后端\im后端"
pip install -r requirements.txt
Copy-Item .env.example .env
.\scripts\start-im.ps1
.\scripts\status-im.ps1 -Healthcheck
.\scripts\stop-im.ps1 -Force
```

Linux IM operations:

```bash
cd /opt/mongoyia/im
pip3 install -r requirements.txt
cp .env.example .env
python3 scripts/im-healthcheck.py --url ws://127.0.0.1:8767
```

For long-running Linux test servers, copy and adapt either `deploy/mongoyia-im.service.example` or `deploy/supervisor-mongoyia-im.conf.example`. Ensure log directories exist before enabling the service.

For a test server, run for example:

```bash
php yii mall-payment-test/run --baseUrl=https://test.example.com --interactive=0
```

Useful local checks:

```bash
curl -i -X POST "http://127.0.0.1:8089/mall/payment/succeeded?id=249" \
  -H "Content-Type: application/json" \
  --data '{"payment":{"status":"FAIL","transaction_id":"GW-TEST-249-001"},"merchant_order":{"merchant_transaction_id":"Test-pay249","order_amount":"1.00"}}'
```

Expected result: HTTP 400 with body `fail`, and one failed audit row with `gateway_transaction_id=GW-TEST-249-001`.

```bash
curl -i -X POST "http://127.0.0.1:8089/mall/payment/qpayres?id=249" \
  --data "payment_status=FAIL&amount=1.00&merchant_transaction_id=1234567249"
```

Expected result: HTTP 400 with body `FAIL`.

Optional HMAC signature test:

```bash
php -r 'function s(&$a){ksort($a);foreach($a as &$v){if(is_array($v)){s($v);}}}$ts=time();$payload=["get"=>["id"=>"249"],"post"=>[],"headers"=>["timestamp"=>(string)$ts],"raw"=>["payment"=>["status"=>"FAIL","transaction_id"=>"GW-TEST-249-001"],"merchant_order"=>["merchant_transaction_id"=>"Test-pay249","order_amount"=>"1.00"]]];s($payload);echo $ts."\n".hash_hmac("sha256", json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "local-test-secret");'
```

Set `LIANLIAN_CALLBACK_HMAC_SECRET=local-test-secret` and `LIANLIAN_CALLBACK_MAX_AGE_SECONDS=300`, then send the same JSON callback with headers `X-Mongoyia-Payment-Timestamp: <timestamp>` and `X-Mongoyia-Payment-Signature: <hash>`. A missing or wrong signature should return HTTP 400 with body `fail` and audit error `Payment callback signature is required` or `Invalid payment callback signature`. A missing or expired timestamp should return `Payment callback timestamp is required` or `Payment callback timestamp expired`.

When provider callback IP ranges are confirmed, set for example:

```env
LIANLIAN_CALLBACK_ALLOWED_IPS=127.0.0.1,10.0.0.0/8
QPAY_CALLBACK_ALLOWED_IPS=127.0.0.1
```

For repeated failed payment creation, hit `/mall/payment/qpay?id=249` or `/mall/payment/lianlian?id=249` several times while test credentials are empty. The audit table should create at most one matching `create failed` row per provider within five minutes.

Backend audit path:

```text
/backend/mall/payment-attempt/index
```

Use `Business Events` to group all attempts for the same provider/event/business key, and `Duplicates` to group identical callback payloads.

## Known Follow-up

The current test version has parent orders and seller child orders, with order items assigned to seller stores. Before production launch, continue polishing backend order views, refund flows, settlement, and payment provider signature verification against the final QPay/LianLian documents.

Customer-service follow-up before production:

- Enable one IM process supervisor on the actual test server, using either the included Windows scripts, systemd template, or Supervisor template.
- Decide whether platform客服 should reply as the seller, as now implemented for test acceptance, or as a visibly separate platform service identity.
- Rotate `IM_AUTH_SECRET` into a real long random secret on the test server and ensure it is not committed to source control.
