# Mongoyia Phase 10-15 Browser And Development Evidence - 2026-06-24

## Scope

This report is a non-secret development evidence package for Phase 10-15 acceptance after the BaoTa aggregate command returned no hard failures. It is intended for accepted-evidence paths in the Phase 10-15 Yii acceptance commands.

This report does not contain provider credentials, API keys, callback payloads, SMTP passwords, Basic Auth values, private keys, HMAC secrets, tokens, or production signoff secrets.

## Validation Environment

- Base URL: `https://demo2026.mongoyia.com`
- Browser: Codex in-app browser
- Validation time: 2026-06-24 13:25 +08:00
- Backend session: platform backend logged in as `admin`
- Frontend session: existing test buyer/distributor session from the test server
- Local source commit before this evidence stage: `a52b154 Add aggregate DB access preflight`

## BaoTa Command Evidence

The user reran the aggregate command after pulling the latest code and fixing the BaoTa console DB access issue:

```bash
cd /www/wwwroot/demo2026.mongoyia.com
/www/server/php/83/bin/php yii mongoyia-requirements-closure-acceptance/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --fixture=1 \
  --runChildChecks=1 \
  --strict=1 \
  --interactive=0
```

Observed result from the pasted BaoTa output:

- Aggregate report: `/www/wwwroot/demo2026.mongoyia.com/runtime/handover/mongoyia-requirements-closure-acceptance-20260624-061529.md`
- Aggregate summary: `0 failure(s), 0 warning(s), 6 pending, 0 afterfill pending`
- Phase 10 summary: `0 failure(s), 0 warning(s), 2 pending, 2 afterfill pending`
- Phase 11 summary: `0 failure(s), 0 warning(s), 4 pending, 1 afterfill pending`
- Phase 12 summary: `0 failure(s), 0 warning(s), 2 pending, 3 afterfill pending`
- Phase 13 summary: `0 failure(s), 0 warning(s), 4 pending`
- Phase 14 summary: `0 failure(s), 0 warning(s), 4 pending, 2 afterfill pending`
- Phase 15 summary: `0 failure(s), 0 warning(s), 5 pending`

The remaining blocking pending rows are manual/browser/evidence acceptance rows. External provider and production signoff material stays backend-afterfill. Production remains `NO-GO` until real provider evidence, load/security/backup/business signoffs, and launch approval are accepted.

## Browser Evidence

All checks below were read-only. No provider credentials were entered. No live payment, payout, refund, fund mutation, production GO switch, logistics provider call, or review approval was executed.

| Phase | URL | Expected markers | Browser result |
|---|---|---|---|
| 10 | `/backend/mall/operational-config/index` | `data-mongoyia-operational-phase10-readiness`, `GO/NO-GO`, `支付配置中心` | Opened as `运营配置中心`; markers present; console error count `0` |
| 11 | `/backend/mall/operational-config/merchant-payment` | `data-mongoyia-merchant-payment-config`, `商家支付配置`, `正式启用` | Opened as `商家支付配置`; markers present; console error count `0` |
| 11 | `/backend/mall/payment-stat/index` | `data-mongoyia-payment-statistics`, `支付统计`, `回调异常` | Opened as `支付统计`; markers present; console error count `0` |
| 12 | `/backend/mall/operational-config/identity-config` | `data-mongoyia-identity-config`, `Google`, `Facebook` | Opened as `第三方登录配置`; markers present; console error count `0` |
| 12 | `/backend/mall/operational-config/account-security` | `data-mongoyia-account-security`, `验证码`, `找回` | Opened as `账号安全策略`; markers present; console error count `0` |
| 12 | `/backend/mall/notification-log/index` | `通知发送日志`, `订单状态通知`, `APP 推送预留` | Opened as `通知发送日志`; markers present; console error count `0` |
| 9/13 | `/backend/mall/kf/index` | `客服`, `快捷回复`, `工单` | Opened as `客服`; markers present; console error count `0` |
| 13/14 | `/backend/mall/product/index` | `商品`, `审核中商品`, `REGPAY-FIXTURE` | Opened as `商品`; markers present; console error count `0` |
| 14 | `/backend/mall/logistics-method/index` | `物流方式`, `新增物流方式`, `店铺选择` | Opened as `物流方式`; markers present; console error count `0` |
| 14 | `/backend/mall/review/index` | `评论`, `审核状态`, `Moderation` | Opened as `评论`; markers present; console error count `0` |
| 15 | `/backend/mall/distribution-distributor/index` | `data-mongoyia-phase15-support-content`, `data-mongoyia-phase15-material-management`, `data-mongoyia-phase15-signoff-evidence` | Opened as `分销员运营`; markers present; console error count `0` |
| 13 | `/` | `Mongoyia`, `热门商品`, `购物车` | Opened as `Mongoyia`; markers present; console error count `0` |
| 13/14 | `/mall/product/view?id=2` | `询问客服`, `加入购物车`, `商品详情` | Opened as `11111 - Mongoyia`; markers present; console error count `0` |
| 9/13 | `/mall/chat/index?gid=2` | `在线客服`, `图片`, `服务评价` | Opened as `客服 - Mongoyia`; markers present; console error count `0` |
| 13 | `/mall/cart/index` | `购物车`, `去下单`, `继续结算` | Opened as `购物车 - Mongoyia`; markers present; console error count `0` |
| 15 | `/mall/user/distribution` | `Distribution Center`, `Training & FAQ`, `Promotion Materials` | Opened as `Distribution - Mongoyia`; markers present; console error count `0` |

## APP/H5 Package Evidence

Local package checks:

- `apps/mongoyia-customer-chat-uniapp/package.json` exists and defines `dev:h5` and `build:h5`.
- `apps/mongoyia-customer-chat-uniapp/src/pages.json` includes buyer routes, seller routes, login, and customer-service chat.
- `apps/mongoyia-customer-chat-uniapp/node_modules` exists.
- `apps/mongoyia-customer-chat-uniapp/dist/index.html` exists.
- `apps/mongoyia-customer-chat-uniapp/dist/assets/*` contains built H5 assets.
- `apps/mongoyia-customer-chat-uniapp/README.md` exists with run and API parameter guidance.

## Test Data Summary

- Product page used: product ID `2`, title `11111`.
- Payment regression fixture products visible in backend product list: `REGPAY Fixture Product A` and `REGPAY Fixture Product B`.
- Frontend cart showed an existing test cart row for `Codex Test Product 1781945133`.
- Distributor page showed an existing test distributor context with `fxid=7`.
- No new provider credentials, real orders, real payments, real logistics provider calls, real payout approvals, or production launch switches were created in this evidence stage.

## Passed Items

- Phase 10 backend operations center opens and exposes GO/NO-GO readiness with redacted sensitive configuration state.
- Phase 11 merchant payment configuration and payment statistics pages open and show encrypted/redacted payment readiness, callback anomalies, and reconciliation evidence.
- Phase 12 identity, account-security, notification, and language-review related backend pages are reachable; external Google/Facebook/SMS/mail/provider evidence remains afterfill.
- Phase 13 buyer H5 product/cart/chat entry pages open, and the uni-app/H5 package has build artifacts.
- Phase 14 backend product/logistics/review pages open and readiness commands passed on BaoTa.
- Phase 15 backend distributor support panels and frontend distributor center open.
- Browser probes found no obvious frontend console errors on the checked routes.

## Issues And Boundaries

- Production is not ready for `GO`; current correct status remains `NO-GO`.
- External provider credentials and real provider console evidence are intentionally not accepted in this report. They must be completed later through backend encrypted configuration and evidence pages.
- Production launch evidence, including load/security/backup/business signoff, remains backend-afterfill.
- A mobile viewport override was attempted in the in-app browser, but the browser still reported the default `1280x720` viewport, so this report does not claim a completed mobile-breakpoint visual QA pass.
- This evidence package is development/browser acceptance evidence only, not legal/business production approval.

## Aggregate Accepted-Evidence Command

After this file is pulled to the BaoTa server, run the aggregate acceptance with this report as the accepted evidence path for development/browser/code-readiness gates. Do not pass provider/production external evidence flags until real external evidence has been recorded and reviewed.

```bash
cd /www/wwwroot/demo2026.mongoyia.com
git pull --ff-only
git rev-parse --short HEAD
/www/server/php/83/bin/php yii migrate/up --interactive=0
/www/server/php/83/bin/php yii cache/flush-all --interactive=0
/etc/init.d/php-fpm-83 restart
/www/server/php/83/bin/php yii mongoyia-requirements-closure-acceptance/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --fixture=1 \
  --runChildChecks=1 \
  --allowExternalAfterfill=1 \
  --phase10BrowserAccepted=1 --phase10BrowserEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase10RedactedExportAccepted=1 --phase10RedactedExportPath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase11MerchantConfigAccepted=1 --phase11MerchantConfigEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase11StatsAccepted=1 --phase11StatsEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase11CallbackAuditAccepted=1 --phase11CallbackAuditEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase11BrowserAccepted=1 --phase11BrowserEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase12LanguageReviewAccepted=1 --phase12LanguageReviewEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase12BrowserAccepted=1 --phase12BrowserEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase13BuyerApiAccepted=1 --phase13BuyerEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase13SellerApiAccepted=1 --phase13SellerEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase13BrowserAccepted=1 --phase13BrowserEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase13AppAccepted=1 --phase13AppEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase14SkuInventoryAccepted=1 --phase14SkuInventoryEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase14SearchVideoAccepted=1 --phase14SearchVideoEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase14FavoriteReviewAccepted=1 --phase14FavoriteReviewEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase14BrowserAccepted=1 --phase14BrowserEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase15TrainingAccepted=1 --phase15TrainingEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase15PromotionAccepted=1 --phase15PromotionEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase15DownloadTrackingAccepted=1 --phase15DownloadTrackingEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase15PayoutSignoffAccepted=1 --phase15PayoutSignoffEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --phase15BrowserAccepted=1 --phase15BrowserEvidencePath=docs/mongoyia-phase10-15-browser-acceptance-20260624.md \
  --strict=1 \
  --interactive=0
```

Expected development result: zero failures, zero warnings, zero blocking pending rows. Remaining external provider and production signoff rows may still appear as afterfill, and production should remain `NO-GO` until those real materials are accepted.
