param(
    [string]$OutputDir = "runtime/handover",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [string]$BackupArchive = "",
    [string]$UploadArchive = "",
    [string]$BaseUrl = "",
    [string]$ImUrl = "",
    [switch]$StrictHealth,
    [switch]$SkipConnectivity,
    [switch]$SkipMonitor,
    [switch]$SkipHealth,
    [switch]$SkipBackupVerify,
    [switch]$SkipLoadSmoke,
    [switch]$SkipImPort,
    [switch]$SkipLoadSmokeIm
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Resolve-ProjectPath {
    param([string]$Path)
    if ($Path -eq "") { return "" }
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Add-Step {
    param([string]$Name, [string]$Status, [int]$ExitCode, [string]$Report, [string]$Detail)
    $script:Rows += "| $Name | $Status | $ExitCode | $Report | $Detail |"
    if ($Status -eq "FAIL") { $script:Failures++ }
    if ($Status -eq "WARN") { $script:Warnings++ }
}

function Run-Tool {
    param([string]$Name, [string]$Report, [string]$ScriptPath, [hashtable]$Parameters)
    try {
        $global:LASTEXITCODE = 0
        & $ScriptPath @Parameters
        $code = $LASTEXITCODE
        if ($null -eq $code) { $code = 0 }
    } catch {
        $code = 1
        $script:LastErrorMessage = $_.Exception.Message
    }

    if ($code -eq 0) {
        Add-Step $Name "PASS" $code $Report "completed"
    } else {
        $detail = if ($script:LastErrorMessage) { $script:LastErrorMessage } else { "command failed" }
        Add-Step $Name "FAIL" $code $Report $detail
    }
    $script:LastErrorMessage = ""
}

$outDirFull = Resolve-ProjectPath $OutputDir
New-Item -ItemType Directory -Path $outDirFull -Force | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$summaryPath = Join-Path $outDirFull "mongoyia-production-scheduled-check-$stamp.md"

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:LastErrorMessage = ""

if ($SkipMonitor.IsPresent) {
    Add-Step "Monitor" "WARN" 0 "" "skipped by operator"
} else {
    $report = Join-Path $outDirFull "mongoyia-production-monitor-$stamp.md"
    $params = @{
        OutputPath = $report
        PhpEnv = $PhpEnv
        ImEnv = $ImEnv
    }
    if ($SkipImPort.IsPresent) { $params['SkipImPort'] = $true }
    Run-Tool -Name "Monitor" -Report $report -ScriptPath (Join-Path $PSScriptRoot "mongoyia-production-monitor.ps1") -Parameters $params
}

if ($SkipHealth.IsPresent) {
    Add-Step "Health" "WARN" 0 "" "skipped by operator"
} else {
    $report = Join-Path $outDirFull "mongoyia-production-health-$stamp.md"
    $params = @{
        OutputPath = $report
        PhpEnv = $PhpEnv
        ImEnv = $ImEnv
    }
    if ($StrictHealth.IsPresent) { $params['Strict'] = $true }
    if ($SkipConnectivity.IsPresent) { $params['SkipConnectivity'] = $true }
    Run-Tool -Name "Health" -Report $report -ScriptPath (Join-Path $PSScriptRoot "mongoyia-production-health.ps1") -Parameters $params
}

if ($SkipBackupVerify.IsPresent) {
    Add-Step "Backup Verify" "WARN" 0 "" "skipped by operator"
} else {
    $report = Join-Path $outDirFull "mongoyia-production-backup-verify-$stamp.md"
    $params = @{
        ReportPath = $report
    }
    if ($BackupArchive -ne "") { $params['BackupArchive'] = $BackupArchive }
    if ($UploadArchive -ne "") { $params['UploadArchive'] = $UploadArchive }
    Run-Tool -Name "Backup Verify" -Report $report -ScriptPath (Join-Path $PSScriptRoot "mongoyia-production-backup-verify.ps1") -Parameters $params
}

if ($SkipLoadSmoke.IsPresent) {
    Add-Step "Load Smoke" "WARN" 0 "" "skipped by operator"
} elseif ($BaseUrl -eq "") {
    Add-Step "Load Smoke" "WARN" 0 "" "skipped because BaseUrl was not provided"
} else {
    $report = Join-Path $outDirFull "mongoyia-production-load-smoke-$stamp.md"
    $params = @{
        OutputPath = $report
        BaseUrl = $BaseUrl
    }
    if ($ImUrl -ne "") { $params['ImUrl'] = $ImUrl }
    if ($SkipLoadSmokeIm.IsPresent) { $params['SkipIm'] = $true }
    Run-Tool -Name "Load Smoke" -Report $report -ScriptPath (Join-Path $PSScriptRoot "mongoyia-production-load-smoke.ps1") -Parameters $params
}

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Warnings -gt 0) { "WARN" } else { "PASS" }
$lines = @(
    "# Mongoyia Production Scheduled Check",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- PHP env: $PhpEnv",
    "- IM env: $ImEnv",
    "- Base URL: $BaseUrl",
    "- IM URL: $ImUrl",
    "",
    "A scheduler or alerting system should alert on non-zero exit code or Result=FAIL.",
    "",
    "| Step | Status | Exit Code | Report | Detail |",
    "|---|---:|---:|---|---|"
) + $Rows

$lines | Set-Content -LiteralPath $summaryPath -Encoding UTF8

Write-Output "Mongoyia production scheduled check: $result"
Write-Output "Report: $summaryPath"
if ($Failures -gt 0) { exit 1 }
