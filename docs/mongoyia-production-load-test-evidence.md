# Mongoyia Production Load-Test Evidence

This read-only evidence step records the formal load-test review after the non-destructive `mongoyia-production-load-smoke` baseline. It does not generate traffic, create orders, trigger payment callbacks, or connect to IM.

Marker: `MONGOYIA_PRODUCTION_LOAD_TEST_EVIDENCE_V1`

## Generate Evidence

Local Yii fixture/readiness mode:

```bash
php yii mongoyia-production-load-test-evidence/run --fixture=1 --interactive=0
```

The fixture generates a local `WARN` report by design until formal load-test evidence and owner signoffs are complete.

Windows:

```powershell
.\console\shell\mongoyia-production-load-test-evidence.ps1
```

Linux:

```sh
sh console/shell/mongoyia-production-load-test-evidence.sh
```

Strict final review mode:

```powershell
.\console\shell\mongoyia-production-load-test-evidence.ps1 `
  -LoadTestReference "ticket-or-report-reference" `
  -BrowsingSignoff PASS `
  -CheckoutSignoff PASS `
  -PaymentCallbackSignoff PASS `
  -ImConcurrencySignoff PASS `
  -PeakUsers "agreed-peak" `
  -DurationMinutes "duration" `
  -P95Ms "p95-ms" `
  -ErrorRate "error-rate" `
  -Tester "owner-or-team" `
  -FailOnPending
```

```sh
LOAD_TEST_REFERENCE="ticket-or-report-reference" \
BROWSING_SIGNOFF=PASS \
CHECKOUT_SIGNOFF=PASS \
PAYMENT_CALLBACK_SIGNOFF=PASS \
IM_CONCURRENCY_SIGNOFF=PASS \
PEAK_USERS="agreed-peak" \
DURATION_MINUTES="duration" \
P95_MS="p95-ms" \
ERROR_RATE="error-rate" \
TESTER="owner-or-team" \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-production-load-test-evidence.sh
```

The Yii command and wrapper scripts write `runtime/handover/mongoyia-production-load-test-evidence-*.md`. The Yii command also writes a CSV companion file.

## Required Scope

- Storefront browsing: homepage, category, product detail, cart page.
- Checkout flow: cart, address, order creation, order state verification.
- Payment callback flow: success, duplicate success, amount mismatch, invalid signature, expired timestamp.
- IM WSS flow: connect, send, receive, reconnect, history load, concurrent users.

Store only ticket IDs, report paths, sheet references, or screenshot references. Do not paste customer data, provider secrets, callback payload secrets, private keys, SSH secrets, database passwords, or real `.env` values.
