# Mongoyia Stabilization Progress - 2026-06-18

This report records the current local stabilization pass for test-server acceptance preparation. It is not a production launch signoff.

## Baseline

- Local mall URL: `http://127.0.0.1:8089/`
- Local PHP server: running with `runtime/local-router.php`
- Local IM URL: `ws://127.0.0.1:8767`
- MariaDB, Redis, PHP, and Python IM are running locally.
- PHP built-in server is started with `upload_max_filesize=8M` and `post_max_size=8M` for chat upload validation.

## Fixed In This Pass

| Priority | Issue | Fix | Verification |
|---|---|---|---|
| P0 | Local static resources were routed through Yii and rendered the mall as unstyled HTML. | Added static-file pass-through in `runtime/local-router.php`. | Mall CSS/JS requests return HTTP 200. |
| P0 | Local backend smoke could not reach the backend app because `/backend/*` entered the frontend app. | Routed `/backend` and `/backend/*` to `web/backend/index.php` in the local router. | `php yii backend-smoke-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0` PASS. |
| P0 | Local IM service was not running. | Started Python IM with the existing local `.env`. | `scripts/status-im.ps1 -Healthcheck` PASS and `im-regression.py` PASS. |
| P1 | Local `.env` had missing QPay endpoint keys, causing local deploy-check failures unrelated to secrets. | Added default QPay auth and invoice endpoint URLs. | Local deploy-check no longer fails for missing QPay endpoint keys. |
| P1 | Regression tests generated temporary orders, payment attempts, and IM messages. | Ran generated test-data cleanup with `--apply=1`. | `mongoyia-test-cleanup/run --failOnPending=1` reports zero pending generated rows/files. |

## Current Verification Results

| Check | Result | Notes |
|---|---:|---|
| Local page smoke | PASS | Home, product 90/102, category, cart, payment page, and chat entry return HTTP 200. |
| Frontend smoke | PASS | Includes language paths, chat upload, localized errors, zero-price cart block, and host-route smoke. |
| Backend smoke | PASS | Platform and seller login, dashboard, products, orders, payment attempts, customer service config, and tenant isolation pass. |
| IM healthcheck/regression | PASS | WebSocket health, valid chat flow, auth rejection, scope rejection, and payload rejection pass. |
| Payment regression | PASS | QPay/LianLian callback success, duplicate, amount mismatch, refund, shipment, and invalid-path checks pass locally. |
| Data readiness | PASS with warnings | 0 failures; legacy host/platform-domain cleanup warnings remain in local restored data. |
| Translation readiness | PASS with warnings | Focused products/categories pass; broader product/category coverage still needs batch translation and human review. |
| Order integrity | PASS with warning | New parent/child order checks pass; legacy parent order-product rows remain as historical data risk. |
| Payment audit | PASS | 0 failures in current checked scope. |
| Catalog readiness | PASS with warnings | Zero-price products 89/102/103 and orphan category-parent warnings remain as business data tasks. |
| Security scan | PASS | 0 failures, 0 warnings. |
| Generated test-data cleanup | PASS | 0 pending generated orders, order products, payment attempts, stock refunds, chat messages, or chat files. |

## Remaining Test-Server Blockers

| Priority | Blocker | Owner | Needed |
|---|---|---|---|
| P0 | Real test profile `.env` | Project/ops | `YII_ENV=test`, `WEB_BASE_URL=https://<test-domain>`, `STORE_PLATFORM_DOMAIN=<test-domain>`, non-placeholder secrets. |
| P0 | Real WSS endpoint | Ops | `IM_WEBSOCKET_URL=wss://<test-domain>/<im-path>` and reverse proxy to Python IM. |
| P0 | Payment sandbox credentials | Business/payment owner | QPay basic/invoice code, LianLian merchant keys, callback HMAC secrets, max-age values. |
| P0 | Test server restore | Ops/project | Upload delivery package, SQL dump, checksum, run dry-run restore, then apply restore. |
| P1 | Legacy store host cleanup on test data | Project/data | Run `mongoyia-host-cleanup/run` dry-run/apply after restore if warnings remain. |
| P1 | Catalog business cleanup | Business/project | Confirm prices or deactivation for zero-price products; apply category cleanup if orphan-parent warnings remain. |
| P1 | Broader translation coverage | Content/project | Run translation fill dry-run/apply for broader product/category coverage, then rerun audit/readiness. |
| P3 | Mongolian human review | Business/content | Native review of product/category/UI text before production. |

## Next Commands

```powershell
php -d upload_max_filesize=8M -d post_max_size=8M yii deploy-check/run --interactive=0
php yii mall-smoke-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii backend-smoke-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0
python ..\..\im后端\im后端\scripts\im-regression.py --url ws://127.0.0.1:8767 --merchant-uid 37 --product-id 102 --store-id 9
php yii mall-payment-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0
php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

Final test-server acceptance still requires:

```bash
php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --interactive=0
```
