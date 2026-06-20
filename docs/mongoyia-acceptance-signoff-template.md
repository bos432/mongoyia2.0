# Mongoyia Test Acceptance Signoff Template

## Basic Info

| Item | Value |
|---|---|
| Test date |  |
| Tester |  |
| Test server domain |  |
| PHP project path |  |
| Python IM path |  |
| Database dump used |  |
| Git/package version |  |
| Acceptance report path |  |

## Environment

| Item | Expected | Actual | Result |
|---|---|---|---|
| `YII_DEBUG` | `false` |  |  |
| `DEFAULT_ROUTE` | `mall` |  |  |
| `STORE_PLATFORM_DOMAIN` | test domain |  |  |
| `WEB_BASE_URL` | `https://<test-domain>` |  |  |
| Template/example hosts | no `example.com` hosts in test/prod profile |  |  |
| `BACKEND_ONLY_DOMAINS` | empty unless intentionally backend-only |  |  |
| `HOST_ROUTE_MAP` | empty or valid `domain:route` entries |  |  |
| `LEGACY_HOST_DOMAINS` | old handover domains listed |  |  |
| `frontend/runtime/host.php` | platform maps to `mall`, no legacy domains |  |  |
| `IM_WEBSOCKET_URL` | `wss://<test-domain>/<im-path>` |  |  |
| Python IM `IM_HOST` | bind host, not URL |  |  |
| Python IM `IM_PORT` | integer `1-65535` |  |  |
| PHP/Python `IM_AUTH_SECRET` | same long secret |  |  |
| PHP/Python IM database | same DB host/port/database/username |  |  |
| `CHAT_UPLOAD_URL` | root-relative or HTTPS URL on test domain |  |  |
| `UPLOAD_HTTP_PREFIX` | root-relative or HTTPS URL on test domain |  |  |
| `IM_MAX_TEXT_MESSAGE_LENGTH` | configured, default `2000` |  |  |
| `IM_MAX_IMAGE_MESSAGE_LENGTH` | configured, default `2048` |  |  |
| Redis | reachable |  |  |
| PHP runtime requirements | required extensions/functions available |  |  |
| Writable app paths | `runtime`, `frontend/runtime`, `web/assets`, `web/attachment`, `web/attachment/chat` |  |  |
| PHP `upload_max_filesize` | at least `6M` |  |  |
| PHP `post_max_size` | at least `6M` |  |  |
| QPay sandbox values | configured |  |  |
| LianLian sandbox values | configured |  |  |
| `LIANLIAN_SANDBOX` | `true` on test |  |  |
| QPay/LianLian callback base URLs | HTTPS URLs on same real test domain |  |  |
| Callback HMAC secrets | configured |  |  |
| Callback max-age seconds | greater than `0` |  |  |

## Automated Acceptance

Generate a short signoff file from the latest acceptance report, then attach it to the signed record:

```bash
php yii mongoyia-signoff/run --interactive=0
```

You can also copy the `Signoff Summary` section from the generated acceptance report into the notes field manually.

Command:

```bash
php yii mongoyia-acceptance/run \
  --baseUrl=https://<test-domain> \
  --profile=test \
  --strict=1 \
  --cleanupAfterRun=1 \
  --interactive=0
```

| Step | Expected | Actual | Result |
|---|---|---|---|
| Deployment configuration | 0 failures, 0 warnings |  |  |
| Security hardcode scan | 0 failures, 0 warnings |  |  |
| Data readiness | 0 failures, 0 warnings |  |  |
| IM healthcheck | pass |  |  |
| IM chat/auth/scope/payload regression | pass |  |  |
| Frontend smoke | pass |  |  |
| Backend smoke | pass |  |  |
| Payment regression | pass |  |  |
| Generated test-data cleanup | pass |  |  |
| Generated test-data cleanup verification | 0 pending generated rows/files |  |  |

## Manual Spot Checks

| Area | URL / Action | Result | Notes |
|---|---|---|---|
| Home page | `/` |  |  |
| Product EN | `/product/90?lang=en` |  |  |
| Product MN | `/product/90?lang=mn` |  |  |
| Cart | `/mall/cart/index` |  |  |
| Login | `/mall/default/login` |  |  |
| User chat | `/mall/chat/index?gid=102` |  |  |
| User chat EN | `/mall/chat/index?gid=102&lang=en` |  |  |
| User chat MN | `/mall/chat/index?gid=102&lang=mn` |  |  |
| Public layout MN | header/cart/breadcrumb/search/Cookie bar are Mongolian |  |  |
| Chat AJAX language | token/upload URLs carry current `lang` |  |  |
| Chat token/upload errors | `?lang=en` and `?lang=mn` errors are localized |  |  |
| Platform backend | `/backend/site/info` |  |  |
| Seller backend | `/backend/site/info` |  |  |
| Payment attempts | `/backend/mall/payment-attempt/index` |  |  |
| Customer-service workbench | `/backend/mall/kf/index` |  |  |

## Test Accounts

| Role | Username | Result | Notes |
|---|---|---|---|
| Platform backend | `codex_platform_backend_test_5` |  |  |
| Seller backend | `zhishichanquan` |  |  |
| Admin fallback | `admin` |  |  |

Do not write real passwords into the final signed copy unless the document is stored securely.

## Generated Data Cleanup

The acceptance command should be run with `--cleanupAfterRun=1`, which applies cleanup and then verifies that generated data is gone. Re-check after acceptance:

```bash
php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
```

Expected result:

| Row type | Expected count after cleanup | Actual |
|---|---:|---:|
| `REGPAY-*` orders | 0 |  |
| order products | 0 |  |
| payment attempts | 0 |  |
| stock refunds pending | 0 |  |
| generated IM messages | 0 |  |
| generated chat smoke files | 0 |  |

## Remaining Issues

| Severity | Area | Issue | Owner | Target date |
|---|---|---|---|---|
|  |  |  |  |  |

## Signoff

| Role | Name | Decision | Date |
|---|---|---|---|
| Technical acceptance |  |  |  |
| Product/business acceptance |  |  |  |
| Operations acceptance |  |  |  |

Acceptance decision:

- [ ] Accepted for test-server handover.
- [ ] Accepted with listed issues.
- [ ] Rejected, rerun acceptance after fixes.
