# Mongoyia Mongolian Review Evidence

This evidence step turns the Mongolian human-review workflow into a signoff report that can be included in production readiness. It is read-only: it does not translate text, import CSV rows, restore databases, or modify storefront content.

## Generate Evidence

Windows:

```powershell
.\console\shell\mongoyia-mongolian-review-evidence.ps1
```

Linux:

```sh
sh console/shell/mongoyia-mongolian-review-evidence.sh
```

Strict signoff mode:

```powershell
.\console\shell\mongoyia-mongolian-review-evidence.ps1 `
  -Reviewer "name-or-ticket" `
  -ReviewSignoff PASS `
  -ImageTextSignoff PASS `
  -RemainingRiskReference "ticket-or-sheet-reference" `
  -FailOnPending
```

```sh
REVIEWER="name-or-ticket" \
REVIEW_SIGNOFF=PASS \
IMAGE_TEXT_SIGNOFF=PASS \
REMAINING_RISK_REFERENCE="ticket-or-sheet-reference" \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-mongolian-review-evidence.sh
```

The script writes `runtime/handover/mongoyia-mongolian-review-evidence-*.md`.

## Inputs Read

- Latest `runtime/translation/mn-review*.csv` export.
- Latest review dry-run/check CSV report.
- Latest review import/apply CSV report, if corrections were applied.
- Latest translation audit report from `runtime/acceptance` or `runtime/handover`.
- Non-sensitive reviewer/signoff references supplied as command parameters.

Do not paste private data, real credentials, customer PII, provider secrets, database passwords, SSH keys, or real `.env` values into the evidence report.

## Required Review Loop

```sh
php yii mongoyia-translation-review/run --output=@runtime/translation/mn-review.csv --interactive=0
php yii mongoyia-translation-review/check --input=@runtime/translation/mn-review.csv --report=@runtime/translation/mn-review-check.csv --interactive=0
php yii mongoyia-translation-review/import --dryRun=1 --input=@runtime/translation/mn-review.csv --report=@runtime/translation/mn-review-import-dry-run.csv --interactive=0
php yii mongoyia-translation-review/import --dryRun=0 --input=@runtime/translation/mn-review.csv --report=@runtime/translation/mn-review-import-apply.csv --interactive=0
php yii mongoyia-translation-audit/run --interactive=0
```

Production launch requires native/business review for priority product/category text, storefront wording, and any images that contain embedded Chinese text. Machine translation remains acceptable only for test-server browsing unless explicitly signed off.
