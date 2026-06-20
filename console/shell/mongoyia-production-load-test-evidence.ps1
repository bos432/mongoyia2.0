param(
    [string]$EvidenceDir = "runtime/handover",
    [string]$OutputPath = "",
    [string]$LoadSmokePath = "",
    [string]$LoadTestReference = "",
    [string]$BrowsingSignoff = "PENDING",
    [string]$CheckoutSignoff = "PENDING",
    [string]$PaymentCallbackSignoff = "PENDING",
    [string]$ImConcurrencySignoff = "PENDING",
    [string]$PeakUsers = "",
    [string]$DurationMinutes = "",
    [string]$P95Ms = "",
    [string]$ErrorRate = "",
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
    if (!(Test-Path -LiteralPath $full -PathType Container)) { return "" }
    $file = Get-ChildItem -LiteralPath $full -File -Filter $Pattern -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Read-Result {
    param([string]$Path)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) { return "PENDING" }
    $text = Get-Content -LiteralPath $Path -Raw
    foreach ($pattern in @('(?m)^-\s+Result:\s*(PASS|WARN|FAIL)\s*$', '(?m)^-\s+Status:\s*(PASS|WARN|FAIL)\s*$')) {
        $match = [regex]::Match($text, $pattern)
        if ($match.Success) { return $match.Groups[1].Value }
    }
    return "UNKNOWN"
}

function Normalize-Status {
    param([string]$Value)
    $upper = $Value.Trim().ToUpperInvariant()
    if ($upper -in @("PASS", "WARN", "FAIL", "PENDING", "BLOCKED")) { return $upper }
    if ($upper -eq "") { return "PENDING" }
    return "WARN"
}

function Reference-Status {
    param([string]$Value)
    if ($Value.Trim() -eq "") { return "PENDING" }
    return "PASS"
}

function Add-Row {
    param([string]$Area, [string]$Status, [string]$Evidence, [string]$Reference, [string]$Notes)
    $script:Rows += "| $Area | $Status | $Evidence | $Reference | $Notes |"
    if ($Status -eq "FAIL" -or $Status -eq "UNKNOWN") { $script:Failures++ }
    elseif ($Status -eq "WARN" -or $Status -eq "BLOCKED") { $script:Warnings++ }
    elseif ($Status -eq "PENDING") { $script:Pending++ }
}

if ($OutputPath -eq "") {
    $OutputPath = "runtime/handover/mongoyia-production-load-test-evidence-$(Get-Date -Format "yyyyMMdd-HHmmss").md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir -PathType Container)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

if ($LoadSmokePath -eq "") {
    $LoadSmokePath = Latest-File $EvidenceDir "mongoyia-production-load-smoke-*.md"
} else {
    $LoadSmokePath = Resolve-ProjectPath $LoadSmokePath
}

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:Pending = 0

Add-Row "Load smoke baseline" (Read-Result $LoadSmokePath) "Latest non-destructive load-smoke report" $LoadSmokePath "Local baseline before formal load testing."
Add-Row "Formal load-test report" (Reference-Status $LoadTestReference) "External load-test report reviewed" $LoadTestReference "Store report/ticket/sheet reference only."
Add-Row "Browsing scenario" (Normalize-Status $BrowsingSignoff) "Homepage/category/product browsing met agreed thresholds" $LoadTestReference "Include HTTP status, latency, and error-rate evidence."
Add-Row "Checkout scenario" (Normalize-Status $CheckoutSignoff) "Cart/checkout/order creation path met agreed thresholds" $LoadTestReference "Use sandbox or controlled non-production data."
Add-Row "Payment callback scenario" (Normalize-Status $PaymentCallbackSignoff) "Payment callback throughput and idempotency under load reviewed" $LoadTestReference "Do not store provider secrets or raw callbacks."
Add-Row "IM concurrency scenario" (Normalize-Status $ImConcurrencySignoff) "IM WSS concurrency and reconnect behavior reviewed" $LoadTestReference "Use public WSS test domain evidence."

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Pending -gt 0 -and $FailOnPending.IsPresent) { "FAIL" } elseif ($Warnings -gt 0 -or $Pending -gt 0) { "WARN" } else { "PASS" }

$lines = @(
    "# Mongoyia Production Load-Test Evidence",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Pending: $Pending",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Tester: $Tester",
    "- Evidence dir: $(Resolve-ProjectPath $EvidenceDir)",
    "- Load-test reference: $LoadTestReference",
    "- Peak users: $PeakUsers",
    "- Duration minutes: $DurationMinutes",
    "- P95 ms: $P95Ms",
    "- Error rate: $ErrorRate",
    "",
    "This report is read-only. It records external load-test evidence and does not generate traffic, create orders, trigger callbacks, or connect to IM.",
    "",
    "| Area | Status | Evidence | Reference | Notes |",
    "|---|---:|---|---|---|"
) + $Rows + @(
    "",
    "## Required Formal Load Scope",
    "",
    "- Storefront browsing: homepage, category, product detail, cart page.",
    "- Checkout flow: cart, address, order creation, order state verification.",
    "- Payment callback flow: success, duplicate success, amount mismatch, invalid signature, expired timestamp.",
    "- IM WSS flow: connect, send, receive, reconnect, history load, concurrent users.",
    "",
    "For final production signoff, rerun with `-FailOnPending` and set every scenario signoff to `PASS` only after the formal load-test owner approves the evidence."
)

$lines | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia production load-test evidence: $result"
Write-Output "Report: $outputFull"
if ($Failures -gt 0 -or ($FailOnPending.IsPresent -and $Pending -gt 0)) { exit 1 }
