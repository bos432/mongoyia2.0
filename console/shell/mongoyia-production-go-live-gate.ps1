param(
    [string]$EvidenceDir = "runtime/handover",
    [string]$OutputPath = "",
    [string]$EvidenceSummaryPath = "",
    [string]$BusinessSignoff = "PENDING",
    [string]$PaymentProductionSignoff = "PENDING",
    [string]$SettlementSignoff = "PENDING",
    [string]$MonitoringAlertSignoff = "PENDING",
    [string]$BackupRestoreDrillSignoff = "PENDING",
    [string]$RollbackOwnerSignoff = "PENDING",
    [string]$SecuritySignoff = "PENDING",
    [string]$LaunchWindowSignoff = "PENDING",
    [string]$ApproverReference = "",
    [string]$ChangeTicket = "",
    [string]$PaymentProductionReference = "",
    [string]$SettlementReference = "",
    [string]$MonitoringAlertReference = "",
    [string]$BackupRestoreDrillReference = "",
    [string]$RollbackOwnerReference = "",
    [string]$SecurityReference = "",
    [string]$LaunchWindowReference = "",
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

function Reference-OrFallback {
    param([string]$Reference)
    if ($Reference.Trim() -ne "") { return $Reference }
    return $ChangeTicket
}

function Manual-Status {
    param([string]$Status, [string]$Reference)
    $normalized = Normalize-Status $Status
    if ($normalized -eq "PASS" -and $Reference.Trim() -eq "") {
        return "FAIL"
    }
    return $normalized
}

function Add-Row {
    param([string]$Gate, [string]$Status, [string]$Evidence, [string]$Reference, [string]$Notes)
    $script:Rows += "| $Gate | $Status | $Evidence | $Reference | $Notes |"
    if ($Status -eq "FAIL" -or $Status -eq "UNKNOWN") { $script:Failures++ }
    elseif ($Status -eq "WARN" -or $Status -eq "BLOCKED") { $script:Warnings++ }
    elseif ($Status -eq "PENDING") { $script:Pending++ }
}

if ($OutputPath -eq "") {
    $OutputPath = "runtime/handover/mongoyia-production-go-live-gate-$(Get-Date -Format "yyyyMMdd-HHmmss").md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir -PathType Container)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

if ($EvidenceSummaryPath -eq "") {
    $EvidenceSummaryPath = Latest-File $EvidenceDir "mongoyia-production-evidence-summary-*.md"
} else {
    $EvidenceSummaryPath = Resolve-ProjectPath $EvidenceSummaryPath
}
$loadTest = Latest-File $EvidenceDir "mongoyia-production-load-test-evidence-*.md"

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:Pending = 0

Add-Row "Production evidence summary" (Read-Result $EvidenceSummaryPath) "Latest generated production evidence summary" $EvidenceSummaryPath "Must be PASS or explicitly accepted before launch."
Add-Row "Formal load test" (Read-Result $loadTest) "Latest formal load-test evidence report" $loadTest "Required before production traffic."
Add-Row "Business launch approval" (Normalize-Status $BusinessSignoff) "Business owner approved launch window" $ApproverReference "Record owner/ticket reference only."
$paymentReference = Reference-OrFallback $PaymentProductionReference
$settlementRef = Reference-OrFallback $SettlementReference
$monitoringRef = Reference-OrFallback $MonitoringAlertReference
$backupRef = Reference-OrFallback $BackupRestoreDrillReference
$rollbackRef = Reference-OrFallback $RollbackOwnerReference
$securityRef = Reference-OrFallback $SecurityReference
$launchRef = Reference-OrFallback $LaunchWindowReference
Add-Row "Payment production readiness" (Manual-Status $PaymentProductionSignoff $paymentReference) "QPay/LianLian production credentials, callbacks, and provider portal reviewed" $paymentReference "PASS requires a non-sensitive provider/ticket reference."
Add-Row "Settlement and reconciliation" (Manual-Status $SettlementSignoff $settlementRef) "Platform/seller settlement, refund reconciliation, and accounting owner confirmed" $settlementRef "PASS requires an accounting/settlement owner reference."
Add-Row "Monitoring and alerting" (Manual-Status $MonitoringAlertSignoff $monitoringRef) "Scheduler/monitoring alerts wired for production checks" $monitoringRef "PASS requires an alerting runbook or ticket reference."
Add-Row "Backup restore drill" (Manual-Status $BackupRestoreDrillSignoff $backupRef) "Backup restored to disposable database and verified" $backupRef "PASS requires a restore-drill report reference."
Add-Row "Rollback ownership" (Manual-Status $RollbackOwnerSignoff $rollbackRef) "Rollback owner and database rollback rule confirmed" $rollbackRef "PASS requires a rollback owner/rule reference."
Add-Row "Security signoff" (Manual-Status $SecuritySignoff $securityRef) "Secrets, TLS/WSS, callback signatures, upload limits, and access controls reviewed" $securityRef "PASS requires a hardening review reference."
Add-Row "Launch-window approval" (Manual-Status $LaunchWindowSignoff $launchRef) "Operator coverage and launch window approved" $launchRef "PASS requires a launch/change-window reference."

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Pending -gt 0 -and $FailOnPending.IsPresent) { "FAIL" } elseif ($Warnings -gt 0 -or $Pending -gt 0) { "WARN" } else { "PASS" }

$lines = @(
    "# Mongoyia Production Go-Live Gate",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Pending: $Pending",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Evidence dir: $(Resolve-ProjectPath $EvidenceDir)",
    "- Evidence summary: $EvidenceSummaryPath",
    "- Approver reference: $ApproverReference",
    "- Change ticket: $ChangeTicket",
    "",
    "This gate is read-only. It does not run checks, switch traffic, restore databases, create orders, or trigger payment callbacks.",
    "",
    "| Gate | Status | Evidence | Reference | Notes |",
    "|---|---:|---|---|---|"
) + $Rows + @(
    "",
    "## Production Boundary",
    "",
    "A PASS report means the recorded evidence is complete enough for a production launch review. It is not a substitute for provider contracts, legal/compliance review, or business owner approval outside this repository.",
    "",
    "For final launch review, rerun with `-FailOnPending` and set every signoff parameter to `PASS` only after the responsible owner has approved it."
)

$lines | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia production go-live gate: $result"
Write-Output "Report: $outputFull"
if ($Failures -gt 0 -or ($FailOnPending.IsPresent -and $Pending -gt 0)) { exit 1 }
