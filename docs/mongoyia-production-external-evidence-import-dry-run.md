# Mongoyia Production External Evidence Import Dry Run

This command validates the non-sensitive metadata manifest for production external evidence before any owner imports or accepts it. It is read-only and keeps production launch blocked.

Evidence marker: `MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1`

Yii command:

```bash
php yii mongoyia-production-external-evidence-import-dry-run/run --fixture=1 --interactive=0
```

Optional metadata manifest:

```bash
php yii mongoyia-production-external-evidence-import-dry-run/run --manifestPath=runtime/handover/production-evidence-manifest.json --interactive=0
```

The manifest is metadata only. It may contain ticket IDs, safe artifact references, SHA256 hashes, redaction status, owner role, review timestamp, and pending review decisions. It must not contain real `.env` values, provider credentials, private keys, callback secrets, database passwords, SSH secrets, customer data, raw screenshots, server paths, or artifact contents.

Required evidence keys:

- `prod_config_snapshot`
- `https_wss_dns_tls`
- `payment_production_credentials`
- `payment_callback_security`
- `im_wss_production`
- `backup_restore_drill`
- `monitoring_alert_route`
- `scheduled_check_registration`
- `formal_load_test`
- `security_hardening_review`
- `settlement_reconciliation_signoff`
- `mongolian_human_review`
- `rollback_launch_approval`

Boundary markers:

- `external_evidence_input_valid=1` means the sanitized metadata is valid.
- `evidence_import_allowed=0`
- `evidence_import_executed=0`
- `production_go_live_allowed=0`
- `production_final_no_go=1`

The command writes `runtime/handover/mongoyia-production-external-evidence-import-dry-run-*.md` plus a CSV companion. It does not read, copy, hash, import, or store evidence artifacts. It does not call QPay, LianLian, PayPal, IM, DNS, TLS, backup storage, or monitoring services. It does not create or update orders, payments, callbacks, chat records, files, shipment rows, fund logs, tickets, statistics, or signoff rows.

Use this dry-run before final production go-live review, then keep the final decision in `mongoyia-production-go-live-gate` as `NO-GO` until the real evidence summary and owner signoffs pass.
