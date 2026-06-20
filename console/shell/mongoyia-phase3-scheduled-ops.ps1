param(
    [string]$OutputDir = "runtime/handover",
    [int]$AutoReceiveDays = 7,
    [int]$FeeLimit = 100,
    [int]$StoreId = 0,
    [switch]$ApplyAutoReceive,
    [switch]$SkipFixture,
    [switch]$Strict
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Resolve-ProjectPath {
    param([string]$Path)
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Add-Step {
    param([string]$Name, [string]$Status, [int]$ExitCode, [string]$Detail)
    $script:Rows += "| $Name | $Status | $ExitCode | $Detail |"
    if ($Status -eq "FAIL") { $script:Failures++ }
    if ($Status -eq "WARN") { $script:Warnings++ }
}

function Run-Yii {
    param([string]$Name, [string[]]$CommandArgs)
    try {
        & php yii @CommandArgs
        $code = $LASTEXITCODE
        if ($null -eq $code) { $code = 0 }
    } catch {
        $code = 1
        $script:LastErrorMessage = $_.Exception.Message
    }

    if ($code -eq 0) {
        Add-Step $Name "PASS" $code "completed"
    } else {
        $detail = if ($script:LastErrorMessage) { $script:LastErrorMessage } else { "command failed" }
        Add-Step $Name "FAIL" $code $detail
    }
    $script:LastErrorMessage = ""
}

$outDirFull = Resolve-ProjectPath $OutputDir
New-Item -ItemType Directory -Path $outDirFull -Force | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$summaryPath = Join-Path $outDirFull "mongoyia-phase3-scheduled-ops-$stamp.md"

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:LastErrorMessage = ""

if (-not $SkipFixture.IsPresent) {
    Run-Yii "Fee Deduction Fixture" @("mongoyia-logistics-fee-deduction-test/run", "--interactive=0")
    Run-Yii "Fee Reconciliation Fixture" @("mongoyia-logistics-fee-reconciliation/run", "--fixture=1", "--interactive=0")
    Run-Yii "Status Batch Fixture" @("mongoyia-logistics-status-batch/run", "--fixture=1", "--interactive=0")
    Run-Yii "Port Review Fixture" @("mongoyia-logistics-port-review/run", "--fixture=1", "--interactive=0")
} else {
    Add-Step "Fixture Checks" "WARN" 0 "skipped by operator"
}

$feeArgs = @("mongoyia-logistics-fee-reconciliation/run", "--limit=$FeeLimit", "--interactive=0")
if ($StoreId -gt 0) { $feeArgs += "--storeId=$StoreId" }
if ($Strict.IsPresent) { $feeArgs += "--strict=1" }
Run-Yii "Current Fee Reconciliation" $feeArgs

$autoArgs = @("mongoyia-auto-receive/run", "--days=$AutoReceiveDays", "--interactive=0")
if ($ApplyAutoReceive.IsPresent) { $autoArgs += "--apply=1" }
Run-Yii "Auto Receive" $autoArgs

Run-Yii "Generated Data Cleanup Verification" @("mongoyia-test-cleanup/run", "--failOnPending=1", "--interactive=0")

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Warnings -gt 0) { "WARN" } else { "PASS" }
$lines = @(
    "# Mongoyia Phase 3 Scheduled Ops",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Auto receive days: $AutoReceiveDays",
    "- Auto receive apply: $($ApplyAutoReceive.IsPresent)",
    "- Fee reconciliation limit: $FeeLimit",
    "- Store id: $StoreId",
    "",
    "Default mode is safe/read-only for business data except rollback-clean fixtures. Pass `-ApplyAutoReceive` only after reviewing the dry-run output.",
    "",
    "| Step | Status | Exit Code | Detail |",
    "|---|---:|---:|---|"
) + $Rows

$lines | Set-Content -LiteralPath $summaryPath -Encoding UTF8

Write-Output "Mongoyia Phase 3 scheduled ops: $result"
Write-Output "Report: $summaryPath"
if ($Failures -gt 0) { exit 1 }
