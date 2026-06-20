# Mongoyia Mongolian Review Workflow

This workflow turns the Mongolian machine-translation baseline into a controlled human-review loop. It is intended for test-server acceptance rehearsal and production content signoff.

## Scope

The review command covers active mall products and categories:

- Product fields: `name`, `brief`, and optionally `content`.
- Category fields: `name`, `brief`.
- Default target language: `mn`.

Machine translation is acceptable for test-server browsing. Production launch still requires native/business review for key storefront text, priority product pages, and any image assets that contain embedded Chinese text.

## Export

Small smoke export:

```sh
php yii mongoyia-translation-review/run --limit=20 --includeUeditor=0 --output=@runtime/translation/mn-review-smoke.csv --interactive=0
```

Full review export:

```sh
php yii mongoyia-translation-review/run --output=@runtime/translation/mn-review.csv --interactive=0
```

The export uses UTF-8 with BOM for spreadsheet compatibility. Human reviewers should fill `review_translation`; keep `mn_translation` as the current system value for comparison.

## Reviewer Columns

- `review_translation`: approved Mongolian text to import.
- `review_status`: optional value such as `approved`, `rewrite`, or `image-text`.
- `review_note`: optional business/content note.

If `review_translation` is empty, import falls back to `mn_translation`. Empty translation rows are skipped and recorded in the import report.

## Dry Run

Validate the reviewed CSV before changing the database:

```sh
php yii mongoyia-translation-review/check --input=@runtime/translation/mn-review.csv --report=@runtime/translation/mn-review-check.csv --interactive=0
```

Or use the import command in dry-run mode:

```sh
php yii mongoyia-translation-review/import --dryRun=1 --input=@runtime/translation/mn-review.csv --report=@runtime/translation/mn-review-import-dry-run.csv --interactive=0
```

Both commands produce a report with row status, message, old length, and new length.

## Apply

Apply reviewed rows only after the dry-run report has zero failures:

```sh
php yii mongoyia-translation-review/import --dryRun=0 --input=@runtime/translation/mn-review.csv --report=@runtime/translation/mn-review-import-apply.csv --interactive=0
```

The importer only writes active product/category rows and uses each source row's real `store_id`, so reviewed translations stay attached to the correct seller.

## Post-Apply Checks

Run the focused language readiness and smoke checks:

```sh
php yii mongoyia-translation-readiness/run --models=product,category --ids=90,102,94,106 --targets=mn --interactive=0
php yii mongoyia-translation-audit/run --interactive=0
php yii mall-smoke-test/run --baseUrl=http://127.0.0.1:8089 --interactive=0
```

Record the import report path, reviewer name, review date, and remaining content risks in the acceptance signoff.
