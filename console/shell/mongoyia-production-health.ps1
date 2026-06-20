param(
    [string]$OutputPath = "",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [switch]$Strict,
    [switch]$SkipConnectivity
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-production-health-$stamp.md"
}
$outputFull = if ([System.IO.Path]::IsPathRooted($OutputPath)) { $OutputPath } else { Join-Path $Root $OutputPath }
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) { New-Item -ItemType Directory -Path $outputDir -Force | Out-Null }

$strictFlag = if ($Strict.IsPresent) { "1" } else { "0" }
$skipFlag = if ($SkipConnectivity.IsPresent) { "1" } else { "0" }

$commands = @(
    @{ Name = "Deployment prod profile"; Command = "php yii deploy-check/run --profile=prod --strict=$strictFlag --skipConnectivity=$skipFlag --phpEnv=`"$PhpEnv`" --imEnv=`"$ImEnv`" --interactive=0" },
    @{ Name = "Security scan"; Command = "php yii mongoyia-security-scan/run --interactive=0" },
    @{ Name = "Payment audit"; Command = "php yii mongoyia-payment-audit/run --interactive=0" },
    @{ Name = "Order integrity"; Command = "php yii mongoyia-order-integrity/run --interactive=0" },
    @{ Name = "Translation audit"; Command = "php yii mongoyia-translation-audit/run --interactive=0" },
    @{ Name = "Generated test-data cleanup dry-run"; Command = "php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0" }
)

$failures = 0
$sections = @()
foreach ($item in $commands) {
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $output = & powershell -NoProfile -ExecutionPolicy Bypass -Command $item.Command 2>&1
        $code = $LASTEXITCODE
    } catch {
        $output = @($_.Exception.Message)
        $code = 1
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($code -ne 0) { $failures++ }
    $status = if ($code -eq 0) { "PASS" } else { "FAIL" }
    $section = @()
    $section += "## $($item.Name)"
    $section += ""
    $section += "- Status: $status"
    $section += "- Exit code: $code"
    $section += ""
    $section += '```text'
    $section += $item.Command
    $section += '```'
    $section += ""
    $section += "Output:"
    $section += ""
    $section += '```text'
    $section += ($output -join [Environment]::NewLine)
    $section += '```'
    $section += ""
    $sections += $section
}

$result = if ($failures -gt 0) { "FAIL" } else { "PASS" }
$lines = @()
$lines += "# Mongoyia Production Health Report"
$lines += ""
$lines += "- Result: $result"
$lines += "- Failures: $failures"
$lines += "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")"
$lines += "- PHP env: $PhpEnv"
$lines += "- IM env: $ImEnv"
$lines += "- Strict deploy-check: $strictFlag"
$lines += "- Skip connectivity: $skipFlag"
$lines += ""
$lines += $sections
$lines | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia production health report: $outputFull"
Write-Output "Result: $result"
if ($failures -gt 0) { exit 1 }
