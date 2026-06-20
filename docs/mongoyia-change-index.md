# Mongoyia Change Index

This file maps the main Mongoyia handover changes by area. The git worktree contains many pre-existing handover changes; use this index as a review map, not as a complete git diff.

## Acceptance And Operations

Console checks and regressions:

- `console/controllers/DeployCheckController.php`
- `console/controllers/MongoyiaSecurityScanController.php`
- `console/controllers/MongoyiaDataReadinessController.php`
- `console/controllers/MongoyiaAcceptanceController.php`
- `console/controllers/MongoyiaTestCleanupController.php`
- `console/controllers/MallSmokeTestController.php`
- `console/controllers/BackendSmokeTestController.php`
- `console/controllers/MallPaymentTestController.php`
- `console/controllers/MallTranslateController.php`

Wrapper scripts:

- `console/shell/mongoyia-acceptance.ps1`
- `console/shell/mongoyia-acceptance.sh`

Environment templates:

- `.env.example`
- `.env.test.example`
- `../../im后端/im后端/.env.example`
- `../../im后端/im后端/.env.test.example`

## Database Migrations

- `console/migrations/m260608_150000_mongoyia_order_parent_id.php`
- `console/migrations/m260608_160000_mongoyia_order_stock_deducted_at.php`
- `console/migrations/m260608_170000_mongoyia_order_stock_refunded_at.php`
- `console/migrations/m260608_180000_mongoyia_payment_attempt.php`
- `console/migrations/m260608_181000_mongoyia_payment_attempt_permission.php`
- `console/migrations/m260608_182000_mongoyia_payment_attempt_business_key.php`
- `console/migrations/m260608_183000_mongoyia_order_product_stats_permission.php`
- `console/migrations/m260608_184000_mongoyia_chat_context.php`
- `console/migrations/m260608_185000_mongoyia_chat_read_state.php`
- `console/migrations/m260608_190000_mongoyia_focused_translations.php`

These cover parent/child orders, stock idempotency, payment audit, backend permissions, chat context/read-state, and the focused product/category translation baseline for products `90/102` and categories `94/106`.

## Mall Frontend

Main controllers:

- `frontend/modules/mall/controllers/BaseController.php`
- `frontend/modules/mall/controllers/CartController.php`
- `frontend/modules/mall/controllers/CategoryController.php`
- `frontend/modules/mall/controllers/ChatController.php`
- `frontend/modules/mall/controllers/DefaultController.php`
- `frontend/modules/mall/controllers/OrderController.php`
- `frontend/modules/mall/controllers/PaymentController.php`
- `frontend/modules/mall/controllers/ProductController.php`
- `frontend/modules/mall/controllers/UserController.php`
- `frontend/modules/mall/controllers/PayConstant.php`

Main view areas:

- `web/resources/mall/default/views/default/`
- `web/resources/mall/default/views/product/`
- `web/resources/mall/default/views/category/`
- `web/resources/mall/default/views/cart/`
- `web/resources/mall/default/views/order/`
- `web/resources/mall/default/views/payment/`
- `web/resources/mall/default/views/chat/`
- `web/resources/mall/default/views/layouts/`

## Backend Mall

Controllers:

- `backend/modules/mall/controllers/BaseController.php`
- `backend/modules/mall/controllers/CategoryController.php`
- `backend/modules/mall/controllers/KfController.php`
- `backend/modules/mall/controllers/OrderController.php`
- `backend/modules/mall/controllers/OrderProductController.php`
- `backend/modules/mall/controllers/PaymentAttemptController.php`
- `backend/modules/mall/controllers/ProductController.php`

View areas:

- `backend/modules/mall/views/kf/`
- `backend/modules/mall/views/order/`
- `backend/modules/mall/views/order-product/`
- `backend/modules/mall/views/payment-attempt/`
- `backend/modules/mall/views/product/`
- `backend/modules/mall/views/category/`

## Common Models And Helpers

- `common/helpers/MallPlatformHelper.php`
- `common/helpers/GoogleTranslate.php`
- `common/models/mall/Order.php`
- `common/models/mall/OrderBase.php`
- `common/models/mall/OrderProduct.php`
- `common/models/mall/PaymentAttempt.php`
- `common/models/mall/Product.php`
- `common/models/mall/ProductVisit.php`
- `common/models/mall/ProductVisitBase.php`
- `common/models/mall/UserCoupon.php`
- `common/models/Store.php`
- `common/models/StoreBase.php`
- `common/models/UserBase.php`

## IM Backend

Python IM source and operations:

- `../../im后端/im后端/main.py`
- `../../im后端/im后端/requirements.txt`
- `../../im后端/im后端/README.md`
- `../../im后端/im后端/scripts/start-im.ps1`
- `../../im后端/im后端/scripts/stop-im.ps1`
- `../../im后端/im后端/scripts/status-im.ps1`
- `../../im后端/im后端/scripts/im-healthcheck.py`
- `../../im后端/im后端/scripts/im-regression.py`
- `../../im后端/im后端/deploy/mongoyia-im.service.example`
- `../../im后端/im后端/deploy/supervisor-mongoyia-im.conf.example`

## Translation

- `console/controllers/MallTranslateController.php`
- `common/helpers/GoogleTranslate.php`
- `common/messages/mn/`
- `common/messages/en/`
- `common/models/base/LangBase.php`

Translation is test-acceptable but still needs broad Mongolian manual QA before production.

## Documentation

- `docs/mongoyia-package-index.md`
- `docs/mongoyia-development-progress.md`
- `docs/mongoyia-delivery-status.md`
- `docs/mongoyia-test-server-runbook.md`
- `docs/mongoyia-deploy-checklist.md`
- `docs/mongoyia-handover.md`
- `docs/mongoyia-change-index.md`

## Review Notes

- The `runtime/handover/mongoyia-handover-*.zip` archive is a handover documentation, scripts, templates, and report bundle. It is not a complete deployable source archive.
- Generate `runtime/handover/mongoyia-worktree-inventory-*.md` with `console/shell/mongoyia-worktree-inventory.ps1` before source-code handover.
- Generate `runtime/handover/mongoyia-source-tracked-diff-*.patch` with `console/shell/mongoyia-source-diff-export.ps1` when a tracked-file patch is needed. Add untracked delivery files separately after review.
- Generate `runtime/handover/mongoyia-untracked-source-*.zip` with `console/shell/mongoyia-untracked-source-export.ps1` for reviewed untracked source files, then validate it with `console/shell/mongoyia-validate-untracked-source.ps1`.
- Generate `runtime/handover/mongoyia-source-handover-*.zip` with `console/shell/mongoyia-source-handover-archive.ps1` to bundle the tracked patch, untracked source archive, checksums, and review reports as one source handover unit.
- Generate `runtime/handover/mongoyia-test-server-delivery-*.zip` with `console/shell/mongoyia-test-server-delivery-archive.ps1` to wrap the handover archive, source handover archive, preflight report, and verification report for transfer.
- Do not treat every dirty worktree file as a new change from this phase; many were already dirty in the handover.
- Review the listed files first when assessing Mongoyia acceptance readiness.
- Run `php yii mongoyia-acceptance/run --cleanupAfterRun=1 --interactive=0` locally before handoff.
- Run `profile=test --strict=1` only after a real test-domain `.env` is configured.
