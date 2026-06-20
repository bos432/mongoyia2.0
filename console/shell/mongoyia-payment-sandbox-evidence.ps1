param(
    [string]$AcceptanceDir = "runtime/acceptance",
    [string]$OutputPath = "",
    [string]$BaseUrl = "",
    [string]$QpaySignoff = "PENDING",
    [string]$LianlianSignoff = "PENDING",
    [string]$QpayReference = "",
    [string]$LianlianReference = "",
    [string]$Tester = "",
    [switch]$FailOnPending
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

function Latest-File {
    param([string]$Dir, [string]$Pattern)
    $full = Resolve-ProjectPath $Dir
    if (!(Test-Path -LiteralPath $full -PathType Container)) {
        return ""
    }
    $file = Get-ChildItem -LiteralPath $full -File -Filter $Pattern -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Read-Result {
    param([string]$Path)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) {
        return "PENDING"
    }
    $text = Get-Content -LiteralPath $Path -Raw
    $match = [regex]::Match($text, '(?m)^-\s+Result:\s*(PASS|WARN|FAIL)\s*$')
    if ($match.Success) { return $match.Groups[1].Value }
    return "UNKNOWN"
}

function Normalize-Status {
    param([string]$Value)
    $upper = $Value.Trim().ToUpperInvariant()
    if ($upper -in @("PASS", "WARN", "FAIL", "PENDING", "BLOCKED")) {
        return $upper
    }
    if ($upper -eq "") { return "PENDING" }
    return "WARN"
}

function Add-Row {
    param([string]$Area, [string]$Status, [string]$Evidence, [string]$Reference, [string]$Notes)
    $script:Rows += "| $Area | $Status | $Evidence | $Reference | $Notes |"
    if ($Status -eq "FAIL" -or $Status -eq "UNKNOWN") { $script:Failures++ }
    elseif ($Status -eq "WARN" -or $Status -eq "BLOCKED") { $script:Warnings++ }
    elseif ($Status -eq "PENDING") { $script:Pending++ }
}

if ($OutputPath -eq "") {
    $OutputPath = "runtime/handover/mongoyia-payment-sandbox-evidence-$(Get-Date -Format "yyyyMMdd-HHmmss").md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir -PathType Container)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$acceptance = Latest-File $AcceptanceDir "mongoyia-acceptance-*.md"
$acceptanceResult = Read-Result $acceptance
$acceptanceText = if ($acceptance -ne "") { Get-Content -LiteralPath $acceptance -Raw } else { "" }
$paymentRegressionStatus = "PENDING"
if ($acceptanceText -match '(?s)### payment regression\s+.*?-\s+Exit code:\s+0') {
    $paymentRegressionStatus = "PASS"
} elseif ($acceptanceText -match '(?s)### payment regression\s+.*?-\s+Exit code:\s+[1-9]') {
    $paymentRegressionStatus = "FAIL"
} elseif ($acceptanceResult -eq "PASS") {
    $paymentRegressionStatus = "WARN"
}

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:Pending = 0

Add-Row "Acceptance report" $acceptanceResult "Latest acceptance report" $acceptance "Must be test profile strict acceptance for final P2 signoff."
Add-Row "Automated payment regression" $paymentRegressionStatus "Acceptance step: payment regression" $acceptance "Covers local callback success, duplicate, amount mismatch, HMAC, timestamp, refund, and shipment paths."
Add-Row "QPay sandbox portal" (Normalize-Status $QpaySignoff) "Provider sandbox callback/invoice flow reviewed" $QpayReference "Use ticket ID or screenshot reference only; do not store credentials."
Add-Row "LianLian sandbox portal" (Normalize-Status $LianlianSignoff) "Provider sandbox callback flow reviewed" $LianlianReference "Use ticket ID or screenshot reference only; do not store keys."

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Pending -gt 0 -and $FailOnPending.IsPresent) { "FAIL" } elseif ($Warnings -gt 0 -or $Pending -gt 0) { "WARN" } else { "PASS" }

$lines = @(
    "# Mongoyia Payment Sandbox Evidence",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Pending: $Pending",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Tester: $Tester",
    "- Base URL: $BaseUrl",
    "- Acceptance report: $acceptance",
    "",
    "This report is non-sensitive. It must not contain real payment credentials, private keys, callback HMAC secrets, auth headers, or raw provider secrets.",
    "",
    "| Area | Status | Evidence | Reference | Notes |",
    "|---|---:|---|---|---|"
) + $Rows + @(
    "",
    "## Required Sandbox Cases",
    "",
    "| Provider | Case | Expected Result | Actual/Reference |",
    "|---|---|---|---|",
    "| QPay | Create sandbox invoice/payment | Provider accepts sandbox merchant config |  |",
    "| QPay | Success callback | Parent and seller child orders become paid once |  |",
    "| QPay | Duplicate success callback | No duplicate stock deduction or duplicate success side effects |  |",
    "| QPay | Amount mismatch | Callback rejected and audited |  |",
    "| QPay | Bad/missing signature | Callback rejected and audited when HMAC is enabled |  |",
    "| QPay | Expired timestamp | Callback rejected and audited when max-age is enabled |  |",
    "| LianLian | Create sandbox payment | Provider accepts sandbox merchant config |  |",
    "| LianLian | Success callback | Parent and seller child orders become paid once |  |",
    "| LianLian | Duplicate success callback | No duplicate stock deduction or duplicate success side effects |  |",
    "| LianLian | Amount mismatch | Callback rejected and audited |  |",
    "| LianLian | Bad/missing signature | Callback rejected and audited when HMAC is enabled |  |",
    "| LianLian | Expired timestamp | Callback rejected and audited when max-age is enabled |  |",
    "",
    "## Suggested Commands",
    "",
    "```bash",
    "php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --interactive=0",
    "php yii mongoyia-payment-audit/run --strict=1 --interactive=0",
    "php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0",
    "```",
    "",
    "For final P2 signoff, rerun this script with `-FailOnPending` and set `-QpaySignoff PASS` / `-LianlianSignoff PASS` only after provider sandbox evidence is reviewed."
)

$lines | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia payment sandbox evidence: $result"
Write-Output "Report: $outputFull"
if ($Failures -gt 0 -or ($FailOnPending.IsPresent -and $Pending -gt 0)) { exit 1 }
