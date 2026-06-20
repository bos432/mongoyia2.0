param(
    [string]$AcceptanceDir = "runtime/acceptance",
    [string]$OutputPath = "",
    [string]$ImUrl = "",
    [string]$WssSignoff = "PENDING",
    [string]$ReverseProxyReference = "",
    [string]$TlsReference = "",
    [string]$ServiceManagerReference = "",
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

function Acceptance-Step-Status {
    param([string]$Text, [string]$Step)
    if ($Text -eq "") { return "PENDING" }
    $escaped = [regex]::Escape($Step)
    if ($Text -match "(?s)### $escaped\s+.*?-\s+Exit code:\s+0") { return "PASS" }
    if ($Text -match "(?s)### $escaped\s+.*?-\s+Exit code:\s+[1-9]") { return "FAIL" }
    return "PENDING"
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

function Reference-Status {
    param([string]$Value)
    if ($Value -eq "") { return "PENDING" }
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
    $OutputPath = "runtime/handover/mongoyia-im-wss-evidence-$(Get-Date -Format "yyyyMMdd-HHmmss").md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir -PathType Container)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$acceptance = Latest-File $AcceptanceDir "mongoyia-acceptance-*.md"
$acceptanceResult = Read-Result $acceptance
$acceptanceText = if ($acceptance -ne "") { Get-Content -LiteralPath $acceptance -Raw } else { "" }

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:Pending = 0

Add-Row "Acceptance report" $acceptanceResult "Latest acceptance report" $acceptance "Must be test profile strict acceptance for final P2 signoff."
Add-Row "IM healthcheck" (Acceptance-Step-Status $acceptanceText "IM healthcheck") "Acceptance step: IM healthcheck" $acceptance "Opens WSS, registers a temporary user, and verifies DB-backed chat_list."
Add-Row "IM chat regression" (Acceptance-Step-Status $acceptanceText "IM chat regression") "Acceptance step: IM chat regression" $acceptance "Verifies user/merchant send, history, auth rejection, scope rejection, and payload rejection."
Add-Row "IM concurrency regression" (Acceptance-Step-Status $acceptanceText "IM concurrency regression") "Acceptance step: IM concurrency regression" $acceptance "Lightweight concurrent WSS users."
Add-Row "Public WSS signoff" (Normalize-Status $WssSignoff) "Public WSS URL reviewed" $ImUrl "Must use wss:// and the real test domain."
Add-Row "Reverse proxy" (Reference-Status $ReverseProxyReference) "Proxy route forwards upgrade traffic to Python IM" $ReverseProxyReference "Store ticket/config reference only."
Add-Row "TLS certificate" (Reference-Status $TlsReference) "Valid certificate and renewal owner reviewed" $TlsReference "Store certificate/ticket reference only."
Add-Row "Service manager" (Reference-Status $ServiceManagerReference) "systemd/Supervisor/Windows service guard reviewed" $ServiceManagerReference "Store unit/process-manager reference only."

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Pending -gt 0 -and $FailOnPending.IsPresent) { "FAIL" } elseif ($Warnings -gt 0 -or $Pending -gt 0) { "WARN" } else { "PASS" }

$lines = @(
    "# Mongoyia IM WSS Evidence",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Pending: $Pending",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Tester: $Tester",
    "- IM URL: $ImUrl",
    "- Acceptance report: $acceptance",
    "",
    "This report is non-sensitive. It must not contain real `.env` files, IM auth secrets, database passwords, SSH keys, or private network credentials.",
    "",
    "| Area | Status | Evidence | Reference | Notes |",
    "|---|---:|---|---|---|"
) + $Rows + @(
    "",
    "## Required WSS Cases",
    "",
    "| Case | Expected Result | Actual/Reference |",
    "|---|---|---|",
    "| Public WSS URL | Browser-facing URL uses `wss://<test-domain>/<im-path>` |  |",
    "| Reverse proxy upgrade | Proxy preserves `Upgrade` and `Connection` headers |  |",
    "| Python IM bind | `IM_HOST` is a bind host and `IM_PORT` is reachable from proxy |  |",
    "| Shared auth secret | PHP and Python IM use the same secret on the target server |  |",
    "| Healthcheck | `im-healthcheck.py` passes against public WSS URL |  |",
    "| Chat regression | user/merchant send, history, auth, scope, and payload checks pass |  |",
    "| Concurrency | lightweight concurrent WSS users pass |  |",
    "| Persistence | refresh/reconnect can load prior chat history |  |",
    "| Service guard | systemd/Supervisor/Windows service is enabled and restart policy is set |  |",
    "",
    "## Suggested Commands",
    "",
    "```bash",
    "python ../../im*/im*/scripts/im-healthcheck.py --url wss://<test-domain>/<im-path>",
    "python ../../im*/im*/scripts/im-regression.py --url wss://<test-domain>/<im-path> --merchant-uid 37 --product-id 102 --store-id 9",
    "python ../../im*/im*/scripts/im-concurrency.py --url wss://<test-domain>/<im-path> --merchant-uid 37 --product-id 102 --store-id 9 --users 5",
    "php yii mongoyia-acceptance/run --baseUrl=https://<test-domain> --profile=test --strict=1 --cleanupAfterRun=1 --imUrl=wss://<test-domain>/<im-path> --interactive=0",
    "```",
    "",
    "For final P2 signoff, rerun this script with `-FailOnPending` and set `-WssSignoff PASS` only after public-domain WSS, reverse proxy, TLS, and service-manager evidence is reviewed."
)

$lines | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia IM WSS evidence: $result"
Write-Output "Report: $outputFull"
if ($Failures -gt 0 -or ($FailOnPending.IsPresent -and $Pending -gt 0)) { exit 1 }
