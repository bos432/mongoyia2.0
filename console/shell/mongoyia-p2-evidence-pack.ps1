param(
    [string]$EvidenceDir = "runtime/handover",
    [string]$AcceptanceDir = "runtime/acceptance",
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [switch]$FailOnPending
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

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

function Copy-IfPresent {
    param([string]$Source, [string]$Relative)
    if ($Source -eq "" -or !(Test-Path -LiteralPath $Source -PathType Leaf)) {
        return ""
    }

    $dest = Join-Path $Stage $Relative
    $destDir = Split-Path -Parent $dest
    if (!(Test-Path -LiteralPath $destDir -PathType Container)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $Source -Destination $dest -Force
    return $Relative.Replace("\", "/")
}

function Add-Report {
    param([string]$Gate, [string]$Dir, [string]$Pattern, [string]$Owner, [bool]$Required, [string]$Notes)
    $path = Latest-File $Dir $Pattern
    $status = Read-Result $path
    $dest = ""
    if ($path -ne "") {
        $dest = Copy-IfPresent $path ("reports/" + (Split-Path -Leaf $path))
    }

    if ($status -eq "FAIL") { $script:Failures++ }
    elseif ($status -eq "WARN" -or $status -eq "UNKNOWN") { $script:Warnings++ }
    elseif ($status -eq "PENDING" -and $Required) { $script:Pending++ }

    $requiredText = if ($Required) { "yes" } else { "no" }
    $reportText = if ($dest -ne "") { $dest } else { "" }
    $script:Rows += "| $Gate | $status | $requiredText | $reportText | $Owner | $Notes |"
}

$OutputRoot = Resolve-ProjectPath $OutputDir
$Stage = Join-Path $OutputRoot "mongoyia-p2-evidence-pack-$Stamp"
$ArchivePath = Join-Path $OutputRoot "mongoyia-p2-evidence-pack-$Stamp.zip"
$HashPath = "$ArchivePath.sha256"

New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
if (Test-Path -LiteralPath $Stage) {
    $resolvedStage = (Resolve-Path -LiteralPath $Stage).Path
    $resolvedOutput = (Resolve-Path -LiteralPath $OutputRoot).Path
    if (!$resolvedStage.StartsWith($resolvedOutput)) {
        throw "Refusing to remove unexpected stage path: $resolvedStage"
    }
    Remove-Item -LiteralPath $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

$script:Rows = @()
$script:Failures = 0
$script:Warnings = 0
$script:Pending = 0

Add-Report "External input gate" $EvidenceDir "mongoyia-test-server-input-gate-*.md" "Ops/engineering" $true "Must pass before restore apply."
Add-Report "P2 readiness" $EvidenceDir "mongoyia-p2-readiness-*.md" "Ops/engineering" $true "Confirms HTTPS/WSS/payment/test inputs are not placeholders."
Add-Report "Restore plan" $EvidenceDir "mongoyia-test-server-restore-plan-*.md" "Ops" $true "Command plan reviewed before apply."
Add-Report "Restore execution" $EvidenceDir "mongoyia-test-server-restore-*.md" "Ops" $true "Dry-run or apply report from restore orchestrator."
Add-Report "Go/no-go" $EvidenceDir "mongoyia-test-server-go-no-go-*.md" "Ops/QA" $true "NO-GO blocks restore apply."
Add-Report "Preflight" $EvidenceDir "mongoyia-test-server-preflight-*.md" "Engineering" $true "Strict test profile preflight."
Add-Report "Payment sandbox evidence" $EvidenceDir "mongoyia-payment-sandbox-evidence-*.md" "Payment/Ops" $true "QPay/LianLian sandbox signoff without secrets."
Add-Report "IM WSS evidence" $EvidenceDir "mongoyia-im-wss-evidence-*.md" "IM/Ops" $true "Public-domain WSS, reverse-proxy, TLS, and IM regression signoff."
Add-Report "Acceptance" $AcceptanceDir "mongoyia-acceptance-*.md" "QA/business" $true "Full storefront/payment/IM/backend acceptance."
Add-Report "Signoff" $AcceptanceDir "mongoyia-signoff-*.md" "QA/business" $true "Human-readable signoff summary."
Add-Report "Risk register" $AcceptanceDir "mongoyia-risk-register-*.md" "Engineering/business" $true "Known risks and owner decisions."
Add-Report "Delivery index" $AcceptanceDir "mongoyia-delivery-index-*.md" "Engineering" $false "Final generated handover index."
Add-Report "Handoff status" $EvidenceDir "mongoyia-handoff-status-*.md" "Engineering" $false "Latest artifact and missing-input summary."
Add-Report "Production evidence summary" $EvidenceDir "mongoyia-production-evidence-summary-*.md" "Ops/engineering" $false "Production-readiness rehearsal index."

Copy-IfPresent (Join-Path $Root "docs/mongoyia-external-integration-inputs.md") "docs/mongoyia-external-integration-inputs.md" | Out-Null
Copy-IfPresent (Join-Path $Root "docs/mongoyia-acceptance-signoff-template.md") "docs/mongoyia-acceptance-signoff-template.md" | Out-Null
Copy-IfPresent (Join-Path $Root "docs/mongoyia-p2-evidence-pack.md") "docs/mongoyia-p2-evidence-pack.md" | Out-Null

$result = if ($Failures -gt 0) { "FAIL" } elseif ($Pending -gt 0 -and $FailOnPending.IsPresent) { "FAIL" } elseif ($Warnings -gt 0 -or $Pending -gt 0) { "WARN" } else { "PASS" }

$manifest = @(
    "# Mongoyia P2 Evidence Pack",
    "",
    "- Result: $result",
    "- Failures: $Failures",
    "- Warnings: $Warnings",
    "- Pending required evidence: $Pending",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Evidence dir: $(Resolve-ProjectPath $EvidenceDir)",
    "- Acceptance dir: $(Resolve-ProjectPath $AcceptanceDir)",
    "",
    "This pack is read-only. It only copies existing reports and docs; it does not restore databases, create orders, call payment gateways, or connect to IM.",
    "",
    "| Gate | Status | Required | Included report | Owner | Notes |",
    "|---|---:|---:|---|---|---|"
) + $Rows + @(
    "",
    "## Manual Attachments",
    "",
    "- Test-server URL and account handoff record.",
    "- Payment provider sandbox portal screenshots or ticket references.",
    "- IM WSS reverse-proxy/TLS ticket references.",
    "- Backup snapshot/archive reference and restore-drill note.",
    "- Business acceptance owner and date.",
    "",
    "Do not add secrets, private keys, raw payment credentials, SSH keys, or real `.env` files to this evidence pack."
)
$manifest | Set-Content -LiteralPath (Join-Path $Stage "MANIFEST.md") -Encoding UTF8

if (Test-Path -LiteralPath $ArchivePath) { Remove-Item -LiteralPath $ArchivePath -Force }
if (Test-Path -LiteralPath $HashPath) { Remove-Item -LiteralPath $HashPath -Force }

Compress-Archive -Path (Join-Path $Stage "*") -DestinationPath $ArchivePath -Force
$archiveHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
"$archiveHash  $(Split-Path -Leaf $ArchivePath)" | Set-Content -LiteralPath $HashPath -Encoding ASCII

Write-Output "Mongoyia P2 evidence pack: $result"
Write-Output "Folder: $Stage"
Write-Output "Archive: $ArchivePath"
Write-Output "Checksum: $HashPath"
if ($Failures -gt 0 -or ($FailOnPending.IsPresent -and $Pending -gt 0)) { exit 1 }
