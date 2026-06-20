param(
    [string]$ArchivePath = "",
    [string]$Php = "php",
    [string]$ReportPath = "",
    [switch]$SkipArchive
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root
$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
if ($ReportPath -eq "") {
    $ReportPath = "runtime/handover/mongoyia-handover-verify-$Stamp.md"
}
$CompletedSteps = @()
$ArchiveValidationOutput = @()

function Latest-Archive {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter "mongoyia-handover-*.zip" -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        throw "No handover archive found under runtime/handover."
    }
    return $file.FullName
}

function Run-Step {
    param([string]$Name, [string[]]$ArgsList)
    Write-Output ""
    Write-Output "== $Name =="
    Write-Output "$Php $($ArgsList -join ' ')"
    & $Php @ArgsList
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
    $script:CompletedSteps += $Name
}

function Run-ScriptStep {
    param([string]$Name, [string]$ScriptPath)
    Write-Output ""
    Write-Output "== $Name =="
    Write-Output $ScriptPath
    & $ScriptPath
    if (!$?) {
        exit $LASTEXITCODE
    }
    $global:LASTEXITCODE = 0
    $script:CompletedSteps += $Name
}

function Write-Report {
    $reportFullPath = Join-Path $Root $ReportPath
    $reportDir = Split-Path -Parent $reportFullPath
    if (!(Test-Path -LiteralPath $reportDir)) {
        New-Item -ItemType Directory -Path $reportDir -Force | Out-Null
    }

    $archiveLine = "Skipped"
    $checksumLine = "Skipped"
    $archiveSizeLine = "Skipped"
    if (!$SkipArchive.IsPresent) {
        $resolvedArchive = if ($ArchivePath -ne "") { (Resolve-Path $ArchivePath).Path } else { Latest-Archive }
        $archiveLine = $resolvedArchive
        $archiveSizeLine = "$((Get-Item -LiteralPath $resolvedArchive).Length) bytes"
        $checksumPath = "$resolvedArchive.sha256"
        if (Test-Path -LiteralPath $checksumPath -PathType Leaf) {
            $hash = ((Get-Content -LiteralPath $checksumPath -TotalCount 1) -split '\s+')[0]
            $checksumLine = "$checksumPath (hash=$hash)"
        } else {
            $checksumLine = "Not found"
        }
    }

    $lines = @(
        "# Mongoyia Handover Verification Report",
        "",
        "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
        "- Source root: $Root",
        "- Result: PASS",
        "- Archive: $archiveLine",
        "- Archive size: $archiveSizeLine",
        "- Checksum: $checksumLine",
        "",
        "## Completed Steps",
        ""
    )
    foreach ($step in $CompletedSteps) {
        $lines += "- PASS: $step"
    }
    if (!$SkipArchive.IsPresent) {
        $lines += "- PASS: handover archive validation"
    }
    $lines += ""
    if (!$SkipArchive.IsPresent) {
        $lines += "## Archive Validation Output"
        $lines += ""
        $lines += '```text'
        $lines += $ArchiveValidationOutput
        $lines += '```'
        $lines += ""
    }
    $lines += "## Receiver Next Commands"
    $lines += ""
    $lines += '```powershell'
    $lines += '.\console\shell\mongoyia-validate-handover-archive.ps1 -ArchivePath "' + $archiveLine + '"'
    $lines += ".\console\shell\mongoyia-test-profile-preflight.ps1"
    $lines += '.\console\shell\mongoyia-test-server-dry-run.ps1 -BaseUrl "https://<test-domain>"'
    $lines += '```'
    $lines += ""
    $lines += "Generated test-data cleanup was run with `--failOnPending=1`."
    $lines | Set-Content -LiteralPath $reportFullPath -Encoding UTF8
    Write-Output "Verification report: $reportFullPath"
}

Write-Output "Running Mongoyia handover verification from $Root"

Run-Step "handover package check" @("yii", "mongoyia-package-check/run", "--interactive=0")
Run-Step "security hardcode scan" @("yii", "mongoyia-security-scan/run", "--interactive=0")
Run-ScriptStep "input-gate smoke" ".\console\shell\mongoyia-test-server-input-gate-smoke.ps1"
Run-ScriptStep "go/no-go smoke" ".\console\shell\mongoyia-test-server-go-no-go-smoke.ps1"
Run-Step "generated test-data cleanup verification" @("yii", "mongoyia-test-cleanup/run", "--failOnPending=1", "--interactive=0")

if (!$SkipArchive.IsPresent) {
    $archiveScript = ".\console\shell\mongoyia-validate-handover-archive.ps1"
    if ($ArchivePath -eq "") {
        $ArchivePath = Latest-Archive
    }
    Write-Output ""
    Write-Output "== handover archive validation =="
    Write-Output "$archiveScript -ArchivePath $ArchivePath"
    $ArchiveValidationOutput = @(& $archiveScript -ArchivePath $ArchivePath)
    $ArchiveValidationOutput | Write-Output
}

Write-Report

Write-Output ""
Write-Output "Mongoyia handover verification: PASS"
