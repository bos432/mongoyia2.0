param(
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [string]$HandoverArchivePath = "",
    [string]$SourceHandoverArchivePath = "",
    [string]$PreflightReportPath = "",
    [string]$HandoverVerifyReportPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

$OutputRoot = Join-Path $Root $OutputDir
$Stage = Join-Path $OutputRoot "mongoyia-test-server-delivery-$Stamp"
$ArchivePath = Join-Path $OutputRoot "mongoyia-test-server-delivery-$Stamp.zip"
$HashPath = "$ArchivePath.sha256"
$TarGzPath = Join-Path $OutputRoot "mongoyia-test-server-delivery-$Stamp.tar.gz"
$TarGzHashPath = "$TarGzPath.sha256"

function Latest-File {
    param([string]$Pattern)
    $file = Get-ChildItem -Path $OutputRoot -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        return ""
    }
    return $file.FullName
}

function Resolve-ProjectPath {
    param([string]$Path)
    if ($Path -eq "") { return "" }
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Copy-ToStage {
    param([string]$Source, [string]$Folder)
    if ($Source -eq "" -or !(Test-Path -LiteralPath $Source -PathType Leaf)) {
        throw "Missing delivery artifact: $Source"
    }
    $destDir = Join-Path $Stage $Folder
    if (!(Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $Source -Destination (Join-Path $destDir (Split-Path -Leaf $Source)) -Force
}

New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
if ($HandoverArchivePath -eq "") { $HandoverArchivePath = Latest-File "mongoyia-handover-*.zip" } else { $HandoverArchivePath = Resolve-ProjectPath $HandoverArchivePath }
if ($SourceHandoverArchivePath -eq "") { $SourceHandoverArchivePath = Latest-File "mongoyia-source-handover-*.zip" } else { $SourceHandoverArchivePath = Resolve-ProjectPath $SourceHandoverArchivePath }
if ($PreflightReportPath -eq "") { $PreflightReportPath = Latest-File "mongoyia-test-server-preflight-*.md" } else { $PreflightReportPath = Resolve-ProjectPath $PreflightReportPath }
if ($HandoverVerifyReportPath -eq "") { $HandoverVerifyReportPath = Latest-File "mongoyia-handover-verify-*.md" } else { $HandoverVerifyReportPath = Resolve-ProjectPath $HandoverVerifyReportPath }

if (Test-Path -LiteralPath $Stage) {
    $resolvedStage = (Resolve-Path -LiteralPath $Stage).Path
    $resolvedOutput = (Resolve-Path -LiteralPath $OutputRoot).Path
    if (!$resolvedStage.StartsWith($resolvedOutput)) {
        throw "Refusing to remove unexpected stage path: $resolvedStage"
    }
    Remove-Item -LiteralPath $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

Copy-ToStage $HandoverArchivePath "archives"
Copy-ToStage "$HandoverArchivePath.sha256" "archives"
Copy-ToStage $SourceHandoverArchivePath "archives"
Copy-ToStage "$SourceHandoverArchivePath.sha256" "archives"
Copy-ToStage $PreflightReportPath "reports"
Copy-ToStage $HandoverVerifyReportPath "reports"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-receiver.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-receiver.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-restore.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-restore.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-restore-plan.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-restore-plan.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-go-no-go.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-go-no-go.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-go-no-go-smoke.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-go-no-go-smoke.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-input-gate.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-input-gate.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-input-gate-smoke.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-test-server-input-gate-smoke.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-sql-dump-manifest.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-sql-dump-manifest.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-env-redacted-report.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-env-redacted-report.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-handoff-status.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-handoff-status.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-p2-readiness.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-p2-readiness.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-p2-evidence-pack.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-p2-evidence-pack.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-payment-sandbox-evidence.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-payment-sandbox-evidence.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-im-wss-evidence.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-im-wss-evidence.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-mongolian-review-evidence.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-mongolian-review-evidence.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-backup.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-backup.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-backup-verify.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-backup-verify.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-health.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-health.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-monitor.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-monitor.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-load-smoke.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-load-smoke.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-load-test-evidence.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-load-test-evidence.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-scheduled-check.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-scheduled-check.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-evidence-summary.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-evidence-summary.sh") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-go-live-gate.ps1") "receiver"
Copy-ToStage (Join-Path $Root "console/shell/mongoyia-production-go-live-gate.sh") "receiver"
Copy-ToStage (Join-Path $Root "docs/mongoyia-test-server-receiver.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-test-server-inputs.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-external-integration-inputs.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-mongolian-review-workflow.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-mongolian-review-evidence.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-p2-evidence-pack.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-payment-sandbox-evidence.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-im-wss-evidence.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-production-readiness.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-production-scheduled-monitoring.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-production-load-test-evidence.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-production-evidence-summary.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-production-go-live-gate.md") "."
Copy-ToStage (Join-Path $Root "docs/mongoyia-production-rollout-rollback.md") "."

$manifest = @(
    "# Mongoyia Test Server Delivery Manifest",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Handover archive: archives/$(Split-Path -Leaf $HandoverArchivePath)",
    "- Source handover archive: archives/$(Split-Path -Leaf $SourceHandoverArchivePath)",
    "- Preflight report: reports/$(Split-Path -Leaf $PreflightReportPath)",
    "- Handover verification report: reports/$(Split-Path -Leaf $HandoverVerifyReportPath)",
    "",
    "## Receiver Use Order",
    "",
    "1. Read RECEIVER_README.md.",
    "2. Verify this delivery archive with mongoyia-validate-test-server-delivery or receiver/mongoyia-test-server-receiver.",
    "3. Use the source handover archive for code review/application.",
    "4. Use the handover archive for docs, templates, scripts, and acceptance evidence.",
    "5. Generate a restore command plan with receiver/mongoyia-test-server-restore-plan before switching to apply mode.",
    "6. Generate receiver/mongoyia-test-server-go-no-go before Apply; NO-GO means do not apply.",
    "7. Run receiver/mongoyia-test-server-go-no-go-smoke to verify local go/no-go rules with synthetic reports.",
    "8. Restore the database baseline separately; this delivery archive intentionally does not include SQL dumps.",
    "9. Create real PHP and Python IM .env files from templates, then run the test-server preflight report with profile=test and strict=1.",
    "10. Fill mongoyia-external-integration-inputs.md with non-sensitive server, payment sandbox, IM WSS, backup, monitoring, and load-test ownership before confirming EXTERNAL_TEST_INPUTS_CONFIRMED.",
    "11. Restore Apply runs input-gate and go/no-go again before database restore; external inputs must be confirmed with EXTERNAL_TEST_INPUTS_CONFIRMED after real values are supplied.",
    "12. After test-server restore, use mongoyia-mongolian-review-workflow.md for Mongolian human-review export/import rehearsal, then run receiver/mongoyia-mongolian-review-evidence to record non-sensitive reviewer signoff evidence.",
    "13. After test-server restore, strict preflight, full acceptance, payment sandbox checks, and IM WSS checks, run receiver/mongoyia-payment-sandbox-evidence, receiver/mongoyia-im-wss-evidence, then receiver/mongoyia-p2-evidence-pack to collect the latest non-sensitive P2 review archive.",
    "14. After P2 acceptance, use receiver/mongoyia-mongolian-review-evidence, receiver/mongoyia-production-backup, receiver/mongoyia-production-backup-verify, receiver/mongoyia-production-health, receiver/mongoyia-production-monitor, receiver/mongoyia-production-load-smoke, receiver/mongoyia-production-load-test-evidence, receiver/mongoyia-production-scheduled-check, receiver/mongoyia-production-evidence-summary, and receiver/mongoyia-production-go-live-gate as production-readiness rehearsal tools.",
    "",
    "## Boundary",
    "",
    "This delivery archive is for test-server handover. It excludes database dumps, real .env files, uploads, vendor dependencies, generated web assets, and production secrets."
)
$manifest | Set-Content -LiteralPath (Join-Path $Stage "MANIFEST.md") -Encoding UTF8
Copy-Item -LiteralPath (Join-Path $Stage "mongoyia-test-server-receiver.md") -Destination (Join-Path $Stage "RECEIVER_README.md") -Force
Remove-Item -LiteralPath (Join-Path $Stage "mongoyia-test-server-receiver.md") -Force

if (Test-Path -LiteralPath $ArchivePath) { Remove-Item -LiteralPath $ArchivePath -Force }
if (Test-Path -LiteralPath $HashPath) { Remove-Item -LiteralPath $HashPath -Force }
if (Test-Path -LiteralPath $TarGzPath) { Remove-Item -LiteralPath $TarGzPath -Force }
if (Test-Path -LiteralPath $TarGzHashPath) { Remove-Item -LiteralPath $TarGzHashPath -Force }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($ArchivePath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $Stage -Recurse -File -Force | ForEach-Object {
        $relative = $_.FullName.Substring($Stage.Length).TrimStart("\", "/").Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $relative) | Out-Null
    }
} finally {
    $zip.Dispose()
}

$archiveHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
"$archiveHash  $(Split-Path -Leaf $ArchivePath)" | Set-Content -LiteralPath $HashPath -Encoding ASCII

$tar = Get-Command tar -ErrorAction SilentlyContinue
if ($null -ne $tar) {
    & tar -czf $TarGzPath -C $Stage .
    if ($LASTEXITCODE -ne 0) {
        throw "tar delivery archive creation failed with exit code $LASTEXITCODE"
    }
    $tarHash = (Get-FileHash -LiteralPath $TarGzPath -Algorithm SHA256).Hash.ToLowerInvariant()
    "$tarHash  $(Split-Path -Leaf $TarGzPath)" | Set-Content -LiteralPath $TarGzHashPath -Encoding ASCII
} else {
    Write-Warning "tar command not found; skipped Linux .tar.gz delivery archive."
}

Write-Output "Test-server delivery folder: $Stage"
Write-Output "Test-server delivery archive: $ArchivePath"
Write-Output "Test-server delivery checksum: $HashPath"
if (Test-Path -LiteralPath $TarGzPath -PathType Leaf) {
    Write-Output "Test-server delivery tar.gz: $TarGzPath"
    Write-Output "Test-server delivery tar.gz checksum: $TarGzHashPath"
}
