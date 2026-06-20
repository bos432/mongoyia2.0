#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
STAMP=${STAMP:-$(date +%Y%m%d-%H%M%S)}
OUTPUT_DIR=${OUTPUT_DIR:-runtime/handover}

cd "$ROOT"

OUTPUT_ROOT="$ROOT/$OUTPUT_DIR"
STAGE="$OUTPUT_ROOT/mongoyia-untracked-source-$STAMP"
BUNDLE_PATH=${BUNDLE_PATH:-$OUTPUT_ROOT/mongoyia-untracked-source-$STAMP.tar.gz}
REPORT_PATH=${REPORT_PATH:-$OUTPUT_ROOT/mongoyia-untracked-source-export-$STAMP.md}

normalize_path() {
  printf '%s\n' "$1" | sed 's#\\#/#g; s#^\./##'
}

is_forbidden_source() {
  path=$(normalize_path "$1")
  case "$path" in
    .well-known/*|demo/*|runtime/*|web/.well-known/*) return 0 ;;
    194.sql|petever1.jpg|web/log.txt|web/success.php) return 0 ;;
    frontend/modules/mall/controllers/PaymentController-0.php) return 0 ;;
    web/resources/mall/default/views/payment/succeeded-0.php) return 0 ;;
    *.sql|*.jpg|*.jpeg|*.png|*.gif|*.webp|*.zip|*.tar|*.gz|*.rar|*.7z|*.log) return 0 ;;
  esac
  return 1
}

is_allowed_source() {
  path=$(normalize_path "$1")
  if is_forbidden_source "$path"; then
    return 1
  fi
  case "$path" in
    .env.example|.env.test.example|MONGOYIA_README.md) return 0 ;;
    backend/modules/mall/controllers/*.php|backend/modules/mall/views/*.php|backend/modules/mall/views/*/*.php) return 0 ;;
    common/helpers/*.php|common/messages/*/*.php|common/models/*.php|common/models/*/*.php) return 0 ;;
    console/controllers/*.php|console/migrations/m260608_*.php|console/shell/mongoyia-*.ps1|console/shell/mongoyia-*.sh) return 0 ;;
    docs/mongoyia-*.md) return 0 ;;
    frontend/modules/mall/controllers/*.php) return 0 ;;
    web/resources/mall/default/views/*.php|web/resources/mall/default/views/*/*.php) return 0 ;;
  esac
  return 1
}

copy_to_stage() {
  rel=$(normalize_path "$1")
  if [ ! -f "$ROOT/$rel" ]; then
    echo "ERROR untracked source file is missing: $rel" >&2
    exit 1
  fi
  mkdir -p "$(dirname "$STAGE/$rel")"
  cp "$ROOT/$rel" "$STAGE/$rel"
}

mkdir -p "$OUTPUT_ROOT"
case "$(cd "$OUTPUT_ROOT" && pwd)/" in
  "$ROOT"/*) ;;
  *) echo "ERROR output root is outside project root: $OUTPUT_ROOT" >&2; exit 1 ;;
esac
rm -rf "$STAGE"
mkdir -p "$STAGE"

STATUS_FILE="$OUTPUT_ROOT/mongoyia-untracked-source-$STAMP.status"
INCLUDED_FILE="$OUTPUT_ROOT/mongoyia-untracked-source-$STAMP.included"
EXCLUDED_FILE="$OUTPUT_ROOT/mongoyia-untracked-source-$STAMP.excluded"

git -c color.status=false status --short --untracked-files=all > "$STATUS_FILE"
: > "$INCLUDED_FILE"
: > "$EXCLUDED_FILE"

awk 'substr($0,1,3) == "?? " { print substr($0,4) }' "$STATUS_FILE" | sort -u |
while IFS= read -r rel; do
  rel=$(normalize_path "$rel")
  [ "$rel" = "" ] && continue
  if is_allowed_source "$rel"; then
    printf '%s\n' "$rel" >> "$INCLUDED_FILE"
  else
    printf '%s\n' "$rel" >> "$EXCLUDED_FILE"
  fi
done

INCLUDED_COUNT=$(wc -l < "$INCLUDED_FILE" | tr -d ' ')
EXCLUDED_COUNT=$(wc -l < "$EXCLUDED_FILE" | tr -d ' ')
if [ "$INCLUDED_COUNT" -eq 0 ]; then
  echo "ERROR no untracked source files matched the Mongoyia source whitelist." >&2
  exit 1
fi

while IFS= read -r rel; do
  if is_forbidden_source "$rel"; then
    echo "ERROR forbidden file matched source bundle whitelist: $rel" >&2
    exit 1
  fi
  copy_to_stage "$rel"
done < "$INCLUDED_FILE"

{
  echo "# Mongoyia Untracked Source Bundle Manifest"
  echo ""
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Source root: $ROOT"
  echo "- Bundle: $BUNDLE_PATH"
  echo "- Included untracked source files: $INCLUDED_COUNT"
  echo "- Excluded untracked entries: $EXCLUDED_COUNT"
  echo ""
  echo "This bundle is intentionally limited to untracked source, docs, templates, and handover scripts. It excludes SQL dumps, runtime output, uploaded/demo files, logs, images, .well-known, and backup controller/view copies."
  echo ""
  echo "## Included Files"
  echo ""
  sed 's#^#- `#; s#$#`#' "$INCLUDED_FILE"
  echo ""
  echo "## Excluded Untracked Entries"
  echo ""
  sed 's#^#- `#; s#$#`#' "$EXCLUDED_FILE"
} > "$STAGE/MANIFEST.md"

rm -f "$BUNDLE_PATH" "$BUNDLE_PATH.sha256"
tar -czf "$BUNDLE_PATH" -C "$STAGE" .

if command -v sha256sum >/dev/null 2>&1; then
  BUNDLE_HASH=$(sha256sum "$BUNDLE_PATH" | awk '{print tolower($1)}')
elif command -v shasum >/dev/null 2>&1; then
  BUNDLE_HASH=$(shasum -a 256 "$BUNDLE_PATH" | awk '{print tolower($1)}')
else
  echo "ERROR sha256sum or shasum is required." >&2
  exit 1
fi
printf '%s  %s\n' "$BUNDLE_HASH" "$(basename "$BUNDLE_PATH")" > "$BUNDLE_PATH.sha256"

{
  echo "# Mongoyia Untracked Source Export"
  echo ""
  echo "- Generated at: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "- Source root: $ROOT"
  echo "- Bundle: $BUNDLE_PATH"
  echo "- Bundle SHA256: $BUNDLE_HASH"
  echo "- Bundle size: $(wc -c < "$BUNDLE_PATH" | tr -d ' ') bytes"
  echo "- Included untracked source files: $INCLUDED_COUNT"
  echo "- Excluded untracked entries: $EXCLUDED_COUNT"
  echo ""
  echo "## Scope"
  echo ""
  echo "This archive complements the tracked patch generated by \`mongoyia-source-diff-export\`. It contains only safe untracked source files selected by a whitelist."
  echo ""
  echo "## Included Files"
  echo ""
  sed 's#^#- `#; s#$#`#' "$INCLUDED_FILE"
  echo ""
  echo "## Excluded Entries For Manual Review"
  echo ""
  sed 's#^#- `#; s#$#`#' "$EXCLUDED_FILE"
  echo ""
  echo "## Receiver Notes"
  echo ""
  echo "1. Apply the tracked patch first, then copy these untracked source files onto the same baseline."
  echo "2. Review excluded entries manually; do not include SQL dumps, real \`.env\`, uploaded files, generated \`runtime\`, logs, images, or \`.well-known\` content in source handover."
  echo "3. Run \`console/shell/mongoyia-validate-untracked-source.sh <bundle>\` after copying or receiving this archive."
} > "$REPORT_PATH"

rm -f "$STATUS_FILE" "$INCLUDED_FILE" "$EXCLUDED_FILE"

echo "Untracked source bundle: $BUNDLE_PATH"
echo "Untracked source checksum: $BUNDLE_PATH.sha256"
echo "Untracked source report: $REPORT_PATH"
echo "Included files: $INCLUDED_COUNT"
echo "Excluded entries: $EXCLUDED_COUNT"
