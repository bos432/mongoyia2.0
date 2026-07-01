# Mongoyia Server Readiness Evidence - 2026-07-02

## Summary

- Evidence type: BaoTa/test-server deployment and readiness output.
- Server path: `/www/wwwroot/demo2026.mongoyia.com`
- Deployed commit: `305b608`
- Result: server-side deployment, migration, cache flush, PHP-FPM restart, mini-program compatibility readiness, and test-station access readiness completed.
- Production GO/NO-GO: remains `NO-GO`.

## Commands Run On BaoTa

```bash
cd /www/wwwroot/demo2026.mongoyia.com
git pull --ff-only
git rev-parse --short HEAD
/www/server/php/83/bin/php yii migrate/up --interactive=0
/www/server/php/83/bin/php yii cache/flush-all --interactive=0
/etc/init.d/php-fpm-83 restart

/www/server/php/83/bin/php /www/wwwroot/demo2026.mongoyia.com/yii mini-program-compat-readiness/run --strict=1 --interactive=0

/www/server/php/83/bin/php yii test-station-access-readiness/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --sellerUsername=zhishichanquan \
  --sellerPassword=123456 \
  --strict=1 \
  --interactive=0

/www/server/php/83/bin/php yii test-station-waf-diagnostics/run \
  --domain=demo2026.mongoyia.com \
  --baseUrl=https://demo2026.mongoyia.com \
  --interactive=0

/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \
  --generateTemplate=1 \
  --templatePath=runtime/handover/full-role-browser-evidence.md \
  --interactive=0
```

## Results

| Area | Result | Evidence |
|---|---|---|
| Git deployment | PASS | `git rev-parse --short HEAD` returned `305b608`. |
| Migrations | PASS | Yii reported no new migrations and system is up-to-date. |
| Yii cache flush | PASS | `cache` component processed. |
| PHP-FPM restart | PASS | php-fpm restarted successfully. |
| Mini-program compatibility readiness | PASS | `mini-program-compat-readiness/run --strict=1` passed with `0 failure(s), 0 warning(s)`; report `runtime/handover/mini-program-compat-readiness-20260701-190931.md`. |
| Public/frontend/API matrix | PASS | `test-station-access-readiness/run --strict=1` passed with `0 failure(s), 0 warning(s)`. |
| R1 chat compatibility deployment | PASS | Access readiness confirmed deployed markers and no deployed `URLSearchParams` marker. |
| Backend login/root access | PASS | Backend login CSRF and backend root script access passed. |
| Seller login/dashboard access | PASS | Seller login POST and seller dashboard access passed for `zhishichanquan`. |
| WAF diagnostics | WARN | `test-station-waf-diagnostics/run` completed with `0 failure(s), 9 warning(s), 60 evidence line(s)`. Access readiness already passed, so warnings are evidence for review, not a current acceptance blocker. |
| Browser evidence template | PENDING | Latest code includes the richer full-role evidence template; filled five-role browser evidence is still pending. |

## Remaining Acceptance Work

- Generate or merge the latest full-role browser evidence template, then fill `runtime/handover/full-role-browser-evidence.md` after right-side browser/manual validation for platform admin, seller, buyer, customer-service, and distributor.
- Run strict evidence validation:

```bash
/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \
  --evidencePath=runtime/handover/full-role-browser-evidence.md \
  --accepted=1 \
  --strict=1 \
  --interactive=0
```

- After strict browser evidence passes, rerun the aggregate Phase 10-15 closure command with the accepted browser evidence path options.

## Safety Notes

- No real payment, refund, withdrawal, logistics provider call, provider secret entry, fund mutation, stock mutation, review approval, payout approval, or production GO was executed by the commands above.
- `PHP Warning: Module "mbstring" is already loaded` appeared in CLI output; it did not stop Yii commands, but PHP configuration should be cleaned later to remove duplicate module loading.
