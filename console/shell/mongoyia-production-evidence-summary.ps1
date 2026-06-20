param(
    [string]$EvidenceDir = "runtime/handover",
    [string]$AcceptanceDir = "runtime/acceptance",
    [string]$OutputPath = "",
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
    foreach ($pattern in @('(?m)^-\s+Result:\s*(PASS|WARN|FAIL)\s*$', '(?m)^-\s+Status:\s*(PASS|WARN|FAIL)\s*$')) {
        $match = [regex]::Match($text, $pattern)
        if ($match.Success) {
            return $match.Groups[1].Value
        }
    }
    return "UNKNOWN"
}

function Add-Gate {
    param([string]$Gate, [string]$Evidence, [string]$Path, [string]$Owner, [string]$Notes)
    $status = Read-Result $Path
    $script:Rows += "| $Gate | $status | $Evidence | $Path | $Owner | $Notes |"
    if ($status -eq "FAIL" -or $status -eq "UNKNOWN") { $script:Failures++ }
    if ($status -eq "WARN") { $script:Warnings++ }
    if ($status -eq "PENDING") { $script:Pending++ }
}

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-production-evidence-summary-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir -PathType Container)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:Pending = 0

$acceptance = Latest-File $AcceptanceDir "mongoyia-acceptance-*.md"
$p2Evidence = Latest-File $EvidenceDir "mongoyia-p2-evidence-pack-*.md"
$paymentSandbox = Latest-File $EvidenceDir "mongoyia-payment-sandbox-evidence-*.md"
$imWss = Latest-File $EvidenceDir "mongoyia-im-wss-evidence-*.md"
$scheduled = Latest-File $EvidenceDir "mongoyia-production-scheduled-check-*.md"
$health = Latest-File $EvidenceDir "mongoyia-production-health-*.md"
$monitor = Latest-File $EvidenceDir "mongoyia-production-monitor-*.md"
$backupVerify = Latest-File $EvidenceDir "mongoyia-production-backup-verify-*.md"
$loadSmoke = Latest-File $EvidenceDir "mongoyia-production-load-smoke-*.md"
$loadTest = Latest-File $EvidenceDir "mongoyia-production-load-test-evidence-*.md"
$mongolianReview = Latest-File $EvidenceDir "mongoyia-mongolian-review-evidence-*.md"
$handoverVerify = Latest-File $EvidenceDir "mongoyia-handover-verify-*.md"
$preflight = Latest-File $EvidenceDir "mongoyia-test-server-preflight-*.md"

Add-Gate "Test-server acceptance" "Latest acceptance report" $acceptance "QA/business" "Required before production launch."
Add-Gate "P2 evidence pack" "Latest P2 evidence pack report" $p2Evidence "QA/Ops" "Restore, preflight, acceptance, payment sandbox, and IM WSS review bundle."
Add-Gate "Payment sandbox evidence" "Latest payment sandbox evidence report" $paymentSandbox "Payment/Ops" "QPay/LianLian sandbox callback signoff without secrets."
Add-Gate "IM WSS evidence" "Latest IM WSS evidence report" $imWss "IM/Ops" "Public WSS healthcheck, regression, TLS, reverse-proxy, and service-manager evidence."
Add-Gate "Handover integrity" "Latest handover verification report" $handoverVerify "Engineering" "Confirms package and local checks."
Add-Gate "Test-server preflight" "Latest test-server preflight report" $preflight "Ops" "Required before restore/apply."
Add-Gate "Scheduled monitoring" "Latest scheduled-check summary" $scheduled "Ops" "Cron/Task Scheduler should alert on failure."
Add-Gate "Production health" "Latest production health report" $health "Engineering/Ops" "Includes deploy-check, security, payment audit, order integrity, translation audit, cleanup dry-run."
Add-Gate "Production monitor" "Latest monitor report" $monitor "Ops" "Runtime/env/Redis/IM/disk/log report."
Add-Gate "Backup verification" "Latest backup-verify report" $backupVerify "Ops" "Checksum and archive readability evidence."
Add-Gate "Load smoke" "Latest load-smoke report" $loadSmoke "Engineering/Ops" "Non-destructive storefront and optional IM concurrency smoke."
Add-Gate "Formal load test" "Latest formal load-test evidence report" $loadTest "Engineering/Ops/business" "Browsing, checkout, payment callback, and IM concurrency load evidence."
Add-Gate "Mongolian review" "Latest Mongolian review evidence report" $mongolianReview "Native/business reviewer" "Human review and image-text signoff evidence."

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Pending -gt 0 -and $FailOnPending.IsPresent) { "FAIL" } elseif ($Warnings -gt 0 -or $Pending -gt 0) { "WARN" } else { "PASS" }
$lines = @(
    "# Mongoyia Production Evidence Summary",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Pending: $Pending",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Evidence dir: $(Resolve-ProjectPath $EvidenceDir)",
    "- Acceptance dir: $(Resolve-ProjectPath $AcceptanceDir)",
    "",
    "This summary is read-only. It does not run checks, restore databases, create orders, or trigger payment callbacks.",
    "",
    "| Gate | Status | Evidence | Report | Owner | Notes |",
    "|---|---:|---|---|---|---|"
) + $Rows + @(
    "",
    "## Required Manual Evidence",
    "",
    "- Payment provider sandbox and production credential signoff.",
    "- IM WSS public-domain regression and reverse-proxy/TLS signoff.",
    "- Mongolian native/business content signoff, recorded by `mongoyia-mongolian-review-evidence`.",
    "- Formal load-test signoff, recorded by `mongoyia-production-load-test-evidence`.",
    "- Backup restore drill to a disposable database.",
    "- Rollout owner, rollback owner, and launch-window approval.",
    "",
    "Use `docs/mongoyia-external-integration-inputs.md` and `docs/mongoyia-production-rollout-rollback.md` to record external/manual evidence that cannot be generated locally."
)

$lines | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia production evidence summary: $result"
Write-Output "Report: $outputFull"
if ($Failures -gt 0 -or ($FailOnPending.IsPresent -and $Pending -gt 0)) { exit 1 }
