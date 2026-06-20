param(
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [string]$AcceptancePath = "",
    [string]$SignoffPath = "",
    [string]$RiskPath = "",
    [string]$DeliveryIndexPath = "",
    [string]$ImRoot = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

$OutputRoot = Join-Path $Root $OutputDir
$Stage = Join-Path $OutputRoot "mongoyia-handover-$Stamp"
$ZipPath = Join-Path $OutputRoot "mongoyia-handover-$Stamp.zip"
$HashPath = "$ZipPath.sha256"

function Latest-File {
    param([string]$Pattern)
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/acceptance") -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Copy-ToStage {
    param([string]$Source, [string]$Relative)
    if ($Source -eq "" -or !(Test-Path -LiteralPath $Source -PathType Leaf)) {
        Write-Warning "Missing handover file: $Relative"
        return
    }
    $dest = Join-Path $Stage $Relative
    $destDir = Split-Path -Parent $dest
    if (!(Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Path $destDir | Out-Null
    }
    Copy-Item -LiteralPath $Source -Destination $dest -Force
}

function Source-Path {
    param([string]$Path)
    if ($Path -eq "") { return "" }
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Assert-StageFile {
    param([string]$Relative)
    $path = Join-Path $Stage $Relative
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Missing required staged handover file: $Relative"
    }
}

function Assert-ZipEntry {
    param($Zip, [string]$Relative)
    $expected = $Relative.Replace("\", "/")
    $entry = $Zip.Entries | Where-Object { $_.FullName.Replace("\", "/") -eq $expected } | Select-Object -First 1
    if ($null -eq $entry) {
        throw "Missing required handover archive entry: $Relative"
    }
}

function Resolve-ImRoot {
    if ($ImRoot -ne "") {
        return (Source-Path $ImRoot)
    }

    $handoverRoot = Resolve-Path (Join-Path $Root "../..")
    $directCandidate = Get-ChildItem -Path $handoverRoot -Directory -Filter "im*" -ErrorAction SilentlyContinue |
        ForEach-Object {
            Get-ChildItem -Path $_.FullName -Directory -Filter "im*" -ErrorAction SilentlyContinue
        } |
        Where-Object {
            (Test-Path -LiteralPath (Join-Path $_.FullName "main.py")) -and
            (Test-Path -LiteralPath (Join-Path $_.FullName "scripts\im-healthcheck.py"))
        } |
        Select-Object -First 1

    if ($null -ne $directCandidate) {
        return $directCandidate.FullName
    }

    $candidate = Get-ChildItem -Path $handoverRoot -Recurse -Filter "main.py" -File -ErrorAction SilentlyContinue |
        Where-Object {
            (Test-Path -LiteralPath (Join-Path $_.DirectoryName ".env.example")) -and
            (Test-Path -LiteralPath (Join-Path $_.DirectoryName "scripts\im-healthcheck.py"))
        } |
        Select-Object -First 1

    if ($null -eq $candidate) {
        return ""
    }

    return $candidate.DirectoryName
}

New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
if (Test-Path -LiteralPath $Stage) {
    Remove-Item -LiteralPath $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage | Out-Null

$projectFiles = @(
    "MONGOYIA_README.md",
    ".env.example",
    ".env.test.example",
    "docs/mongoyia-cn-overview.md",
    "docs/mongoyia-package-index.md",
    "docs/mongoyia-development-progress.md",
    "docs/mongoyia-delivery-status.md",
    "docs/mongoyia-test-server-runbook.md",
    "docs/mongoyia-test-server-receiver.md",
    "docs/mongoyia-test-server-inputs.md",
    "docs/mongoyia-external-integration-inputs.md",
    "docs/mongoyia-deploy-checklist.md",
    "docs/mongoyia-handover.md",
    "docs/mongoyia-change-index.md",
    "docs/mongoyia-acceptance-signoff-template.md",
    "docs/mongoyia-local-baseline.md",
    "docs/mongoyia-manual-qa-checklist.md",
    "docs/mongoyia-mongolian-review-workflow.md",
    "docs/mongoyia-mongolian-review-evidence.md",
    "docs/mongoyia-p2-evidence-pack.md",
    "docs/mongoyia-payment-sandbox-evidence.md",
    "docs/mongoyia-im-wss-evidence.md",
    "docs/mongoyia-production-readiness.md",
    "docs/mongoyia-production-scheduled-monitoring.md",
    "docs/mongoyia-production-load-test-evidence.md",
    "docs/mongoyia-production-evidence-summary.md",
    "docs/mongoyia-production-go-live-gate.md",
    "docs/mongoyia-production-rollout-rollback.md",
    "console/shell/mongoyia-acceptance.ps1",
    "console/shell/mongoyia-acceptance.sh",
    "console/shell/mongoyia-test-profile-preflight.ps1",
    "console/shell/mongoyia-test-profile-preflight.sh",
    "console/shell/mongoyia-test-server-dry-run.ps1",
    "console/shell/mongoyia-test-server-dry-run.sh",
    "console/shell/mongoyia-test-server-preflight-report.ps1",
    "console/shell/mongoyia-test-server-preflight-report.sh",
    "console/shell/mongoyia-test-server-go-no-go.ps1",
    "console/shell/mongoyia-test-server-go-no-go.sh",
    "console/shell/mongoyia-test-server-go-no-go-smoke.ps1",
    "console/shell/mongoyia-test-server-go-no-go-smoke.sh",
    "console/shell/mongoyia-test-server-receiver.ps1",
    "console/shell/mongoyia-test-server-receiver.sh",
    "console/shell/mongoyia-test-server-restore.ps1",
    "console/shell/mongoyia-test-server-restore.sh",
    "console/shell/mongoyia-test-server-restore-plan.ps1",
    "console/shell/mongoyia-test-server-restore-plan.sh",
    "console/shell/mongoyia-test-server-input-gate.ps1",
    "console/shell/mongoyia-test-server-input-gate.sh",
    "console/shell/mongoyia-test-server-input-gate-smoke.ps1",
    "console/shell/mongoyia-test-server-input-gate-smoke.sh",
    "console/shell/mongoyia-sql-dump-manifest.ps1",
    "console/shell/mongoyia-sql-dump-manifest.sh",
    "console/shell/mongoyia-env-redacted-report.ps1",
    "console/shell/mongoyia-env-redacted-report.sh",
    "console/shell/mongoyia-handoff-status.ps1",
    "console/shell/mongoyia-handoff-status.sh",
    "console/shell/mongoyia-p2-readiness.ps1",
    "console/shell/mongoyia-p2-readiness.sh",
    "console/shell/mongoyia-p2-evidence-pack.ps1",
    "console/shell/mongoyia-p2-evidence-pack.sh",
    "console/shell/mongoyia-payment-sandbox-evidence.ps1",
    "console/shell/mongoyia-payment-sandbox-evidence.sh",
    "console/shell/mongoyia-im-wss-evidence.ps1",
    "console/shell/mongoyia-im-wss-evidence.sh",
    "console/shell/mongoyia-mongolian-review-evidence.ps1",
    "console/shell/mongoyia-mongolian-review-evidence.sh",
    "console/shell/mongoyia-production-backup.ps1",
    "console/shell/mongoyia-production-backup.sh",
    "console/shell/mongoyia-production-backup-verify.ps1",
    "console/shell/mongoyia-production-backup-verify.sh",
    "console/shell/mongoyia-production-health.ps1",
    "console/shell/mongoyia-production-health.sh",
    "console/shell/mongoyia-production-monitor.ps1",
    "console/shell/mongoyia-production-monitor.sh",
    "console/shell/mongoyia-production-load-smoke.ps1",
    "console/shell/mongoyia-production-load-smoke.sh",
    "console/shell/mongoyia-production-load-test-evidence.ps1",
    "console/shell/mongoyia-production-load-test-evidence.sh",
    "console/shell/mongoyia-production-scheduled-check.ps1",
    "console/shell/mongoyia-production-scheduled-check.sh",
    "console/shell/mongoyia-production-evidence-summary.ps1",
    "console/shell/mongoyia-production-evidence-summary.sh",
    "console/shell/mongoyia-production-go-live-gate.ps1",
    "console/shell/mongoyia-production-go-live-gate.sh",
    "console/shell/mongoyia-test-server-delivery-archive.ps1",
    "console/shell/mongoyia-test-server-delivery-archive.sh",
    "console/shell/mongoyia-validate-test-server-delivery.ps1",
    "console/shell/mongoyia-validate-test-server-delivery.sh",
    "console/shell/mongoyia-final-handover.ps1",
    "console/shell/mongoyia-final-handover.sh",
    "console/shell/mongoyia-archive-handover.ps1",
    "console/shell/mongoyia-archive-handover.sh",
    "console/shell/mongoyia-validate-handover-archive.ps1",
    "console/shell/mongoyia-validate-handover-archive.sh",
    "console/shell/mongoyia-handover-verify.ps1",
    "console/shell/mongoyia-handover-verify.sh",
    "console/shell/mongoyia-worktree-inventory.ps1",
    "console/shell/mongoyia-worktree-inventory.sh",
    "console/shell/mongoyia-source-diff-export.ps1",
    "console/shell/mongoyia-source-diff-export.sh",
    "console/shell/mongoyia-untracked-source-export.ps1",
    "console/shell/mongoyia-untracked-source-export.sh",
    "console/shell/mongoyia-validate-untracked-source.ps1",
    "console/shell/mongoyia-validate-untracked-source.sh",
    "console/shell/mongoyia-source-handover-archive.ps1",
    "console/shell/mongoyia-source-handover-archive.sh",
    "console/shell/mongoyia-validate-source-handover.ps1",
    "console/shell/mongoyia-validate-source-handover.sh",
    "console/controllers/DeployCheckController.php",
    "console/controllers/MongoyiaPackageCheckController.php",
    "console/controllers/MongoyiaSecurityScanController.php",
    "console/controllers/MongoyiaDataReadinessController.php",
    "console/controllers/MongoyiaCatalogReadinessController.php",
    "console/controllers/MongoyiaTranslationReadinessController.php",
    "console/controllers/MongoyiaTranslationAuditController.php",
    "console/controllers/MongoyiaTranslationReviewController.php",
    "console/controllers/MongoyiaOrderIntegrityController.php",
    "console/controllers/MongoyiaPaymentAuditController.php",
    "console/controllers/MongoyiaAcceptanceController.php",
    "console/controllers/MongoyiaSignoffController.php",
    "console/controllers/MongoyiaDeliveryIndexController.php",
    "console/controllers/MongoyiaRiskRegisterController.php",
    "console/controllers/MongoyiaTestCleanupController.php",
    "console/controllers/MongoyiaHostCleanupController.php",
    "console/controllers/MongoyiaCatalogCleanupController.php",
    "console/controllers/ApiSmokeTestController.php",
    "console/controllers/MallSmokeTestController.php",
    "console/controllers/BackendSmokeTestController.php",
    "console/controllers/MallPaymentTestController.php",
    "console/controllers/MallTranslateController.php",
    "console/migrations/m260608_150000_mongoyia_order_parent_id.php",
    "console/migrations/m260608_160000_mongoyia_order_stock_deducted_at.php",
    "console/migrations/m260608_170000_mongoyia_order_stock_refunded_at.php",
    "console/migrations/m260608_180000_mongoyia_payment_attempt.php",
    "console/migrations/m260608_181000_mongoyia_payment_attempt_permission.php",
    "console/migrations/m260608_182000_mongoyia_payment_attempt_business_key.php",
    "console/migrations/m260608_183000_mongoyia_order_product_stats_permission.php",
    "console/migrations/m260608_184000_mongoyia_chat_context.php",
    "console/migrations/m260608_185000_mongoyia_chat_read_state.php"
)

foreach ($rel in $projectFiles) {
    Copy-ToStage (Join-Path $Root $rel) $rel
}

$imFiles = @(
    ".env.example",
    ".env.test.example",
    "README.md",
    "main.py",
    "requirements.txt",
    "scripts/start-im.ps1",
    "scripts/stop-im.ps1",
    "scripts/status-im.ps1",
    "scripts/im-healthcheck.py",
    "scripts/im-regression.py",
    "scripts/im-concurrency.py",
    "deploy/mongoyia-im.service.example",
    "deploy/supervisor-mongoyia-im.conf.example"
)
$imRootAbs = [string](Resolve-ImRoot)
foreach ($rel in $imFiles) {
    Copy-ToStage (Join-Path $imRootAbs $rel) ("im-backend/" + $rel)
}

if ($AcceptancePath -eq "") { $AcceptancePath = Latest-File "mongoyia-acceptance-*.md" }
if ($SignoffPath -eq "") { $SignoffPath = Latest-File "mongoyia-signoff-*.md" }
if ($RiskPath -eq "") { $RiskPath = Latest-File "mongoyia-risk-register-*.md" }
if ($DeliveryIndexPath -eq "") { $DeliveryIndexPath = Latest-File "mongoyia-delivery-index-*.md" }

Copy-ToStage (Source-Path $AcceptancePath) "runtime/acceptance/$(Split-Path -Leaf $AcceptancePath)"
Copy-ToStage (Source-Path $SignoffPath) "runtime/acceptance/$(Split-Path -Leaf $SignoffPath)"
Copy-ToStage (Source-Path $RiskPath) "runtime/acceptance/$(Split-Path -Leaf $RiskPath)"
Copy-ToStage (Source-Path $DeliveryIndexPath) "runtime/acceptance/$(Split-Path -Leaf $DeliveryIndexPath)"

$manifest = @(
    "# Mongoyia Handover Archive Manifest",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Acceptance report: runtime/acceptance/$(Split-Path -Leaf $AcceptancePath)",
    "- Signoff file: runtime/acceptance/$(Split-Path -Leaf $SignoffPath)",
    "- Risk register: runtime/acceptance/$(Split-Path -Leaf $RiskPath)",
    "- Delivery index: runtime/acceptance/$(Split-Path -Leaf $DeliveryIndexPath)",
    "- Python IM source: $imRootAbs",
    "",
    "This archive intentionally includes templates and handover scripts only. It does not include real .env files, vendor dependencies, uploaded files, or database dumps."
)
$manifest | Set-Content -LiteralPath (Join-Path $Stage "MANIFEST.md") -Encoding UTF8

$requiredFiles = @(
    "MANIFEST.md",
    "MONGOYIA_README.md",
    ".env.example",
    ".env.test.example",
    "docs/mongoyia-package-index.md",
    "docs/mongoyia-development-progress.md",
    "docs/mongoyia-local-baseline.md",
    "docs/mongoyia-test-server-receiver.md",
    "docs/mongoyia-external-integration-inputs.md",
    "docs/mongoyia-mongolian-review-workflow.md",
    "docs/mongoyia-mongolian-review-evidence.md",
    "docs/mongoyia-p2-evidence-pack.md",
    "docs/mongoyia-payment-sandbox-evidence.md",
    "docs/mongoyia-im-wss-evidence.md",
    "docs/mongoyia-production-scheduled-monitoring.md",
    "docs/mongoyia-production-load-test-evidence.md",
    "docs/mongoyia-production-evidence-summary.md",
    "docs/mongoyia-production-go-live-gate.md",
    "console/shell/mongoyia-final-handover.ps1",
    "console/shell/mongoyia-test-server-dry-run.ps1",
    "console/shell/mongoyia-test-server-preflight-report.ps1",
    "console/shell/mongoyia-test-server-go-no-go.ps1",
    "console/shell/mongoyia-test-server-go-no-go-smoke.ps1",
    "console/shell/mongoyia-test-server-receiver.ps1",
    "console/shell/mongoyia-test-server-receiver.sh",
    "console/shell/mongoyia-test-server-restore.ps1",
    "console/shell/mongoyia-test-server-restore.sh",
    "console/shell/mongoyia-test-server-restore-plan.ps1",
    "console/shell/mongoyia-test-server-restore-plan.sh",
    "console/shell/mongoyia-test-server-input-gate.ps1",
    "console/shell/mongoyia-test-server-input-gate.sh",
    "console/shell/mongoyia-test-server-input-gate-smoke.ps1",
    "console/shell/mongoyia-test-server-input-gate-smoke.sh",
    "console/shell/mongoyia-sql-dump-manifest.ps1",
    "console/shell/mongoyia-sql-dump-manifest.sh",
    "console/shell/mongoyia-env-redacted-report.ps1",
    "console/shell/mongoyia-env-redacted-report.sh",
    "console/shell/mongoyia-handoff-status.ps1",
    "console/shell/mongoyia-handoff-status.sh",
    "console/shell/mongoyia-p2-readiness.ps1",
    "console/shell/mongoyia-p2-readiness.sh",
    "console/shell/mongoyia-p2-evidence-pack.ps1",
    "console/shell/mongoyia-payment-sandbox-evidence.ps1",
    "console/shell/mongoyia-im-wss-evidence.ps1",
    "console/shell/mongoyia-mongolian-review-evidence.ps1",
    "console/shell/mongoyia-production-backup-verify.ps1",
    "console/shell/mongoyia-production-load-smoke.ps1",
    "console/shell/mongoyia-production-load-test-evidence.ps1",
    "console/shell/mongoyia-production-scheduled-check.ps1",
    "console/shell/mongoyia-production-evidence-summary.ps1",
    "console/shell/mongoyia-production-go-live-gate.ps1",
    "console/shell/mongoyia-test-server-delivery-archive.ps1",
    "console/shell/mongoyia-validate-test-server-delivery.ps1",
    "console/shell/mongoyia-archive-handover.ps1",
    "console/shell/mongoyia-validate-handover-archive.ps1",
    "console/shell/mongoyia-handover-verify.ps1",
    "console/shell/mongoyia-worktree-inventory.ps1",
    "console/shell/mongoyia-source-diff-export.ps1",
    "console/shell/mongoyia-untracked-source-export.ps1",
    "console/shell/mongoyia-validate-untracked-source.ps1",
    "console/shell/mongoyia-source-handover-archive.ps1",
    "console/shell/mongoyia-validate-source-handover.ps1",
    "console/controllers/MongoyiaAcceptanceController.php",
    "console/controllers/MongoyiaPackageCheckController.php",
    "console/controllers/MongoyiaTranslationReviewController.php",
    "im-backend/main.py",
    "im-backend/.env.example",
    "im-backend/scripts/im-healthcheck.py",
    "runtime/acceptance/$(Split-Path -Leaf $AcceptancePath)",
    "runtime/acceptance/$(Split-Path -Leaf $SignoffPath)",
    "runtime/acceptance/$(Split-Path -Leaf $RiskPath)",
    "runtime/acceptance/$(Split-Path -Leaf $DeliveryIndexPath)"
)
foreach ($rel in $requiredFiles) {
    Assert-StageFile $rel
}

if (Test-Path -LiteralPath $ZipPath) {
    Remove-Item -LiteralPath $ZipPath -Force
}
if (Test-Path -LiteralPath $HashPath) {
    Remove-Item -LiteralPath $HashPath -Force
}
Compress-Archive -Path (Join-Path $Stage "*") -DestinationPath $ZipPath -Force

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    foreach ($rel in $requiredFiles) {
        Assert-ZipEntry $zip $rel
    }
} finally {
    $zip.Dispose()
}

$archiveHash = (Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash.ToLowerInvariant()
"$archiveHash  $(Split-Path -Leaf $ZipPath)" | Set-Content -LiteralPath $HashPath -Encoding ASCII

Write-Output "Handover folder: $Stage"
Write-Output "Handover archive: $ZipPath"
Write-Output "Handover checksum: $HashPath"
Write-Output "Archive validation: PASS ($($requiredFiles.Count) required files)"
