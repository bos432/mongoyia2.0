param(
    [string]$OutputPath = "",
    [string]$HandoverDir = "runtime/handover",
    [string]$AcceptanceDir = "runtime/acceptance",
    [string]$SqlDumpPath = "../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql",
    [string]$SqlChecksumPath = "runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256",
    [string]$HandoffStatusPath = "",
    [string]$InputGatePath = "",
    [string]$ReceiverStatusPath = "",
    [string]$RestoreStatusPath = "",
    [switch]$ExternalInputsConfirmed,
    [string]$ExternalInputsConfirm = ""
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
    $fullDir = Resolve-ProjectPath $Dir
    $file = Get-ChildItem -Path $fullDir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return $null }
    return $file
}

function Latest-NonSmokeFile {
    param([string]$Dir, [string]$Pattern)
    $fullDir = Resolve-ProjectPath $Dir
    $file = Get-ChildItem -Path $fullDir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -notmatch '(smoke|expected)' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return $null }
    return $file
}

function Latest-StatusFile {
    param([string]$FolderPattern, [string]$FileName)
    $dir = Resolve-ProjectPath $HandoverDir
    $folder = Get-ChildItem -Path $dir -Directory -Filter $FolderPattern -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $folder) { return $null }
    $path = Join-Path $folder.FullName $FileName
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { return $null }
    return Get-Item -LiteralPath $path
}

function Read-Result {
    param([string]$Path)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) { return "MISSING" }
    $text = Get-Content -LiteralPath $Path -Raw
    $match = [regex]::Match($text, '(?m)^-\s*Result:\s*(.+?)\r?$')
    if ($match.Success) { return $match.Groups[1].Value.Trim() }
    if ($text -match 'Mongoyia test-server receiver validation:\s*PASS') { return "PASS" }
    if ($text -match '(?m)^-\s*Checksum validation:\s*PASS\r?$') { return "PASS" }
    if ($text -match 'PASS') { return "PASS" }
    return "UNKNOWN"
}

function Read-Contains {
    param([string]$Path, [string]$Pattern)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
    $text = Get-Content -LiteralPath $Path -Raw
    return ($text -match $Pattern)
}

function Test-Sha256 {
    param([string]$FilePath, [string]$ChecksumPath)
    if ($FilePath -eq "" -or !(Test-Path -LiteralPath $FilePath -PathType Leaf)) { return "MISSING" }
    if ($ChecksumPath -eq "" -or !(Test-Path -LiteralPath $ChecksumPath -PathType Leaf)) { return "NO_CHECKSUM" }
    $expected = ((Get-Content -LiteralPath $ChecksumPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
    $actual = (Get-FileHash -LiteralPath $FilePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($expected -eq $actual) { return "PASS" }
    return "MISMATCH"
}

function Add-Check {
    param([string]$Area, [string]$Check, [string]$Status, [string]$Evidence, [string]$Action)
    $script:rows += "| $Area | $Check | $Status | $Evidence | $Action |"
    if ($Status -eq "BLOCK") { $script:blockers++ }
    elseif ($Status -eq "WARN") { $script:warnings++ }
}

function Add-Critical-StateCheck {
    param([string]$Area, [string]$Check, [string]$State, [string]$Evidence, [string]$Action)
    if ($State -eq "PASS") {
        Add-Check $Area $Check "PASS" $Evidence $Action
    } else {
        Add-Check $Area $Check "BLOCK" $Evidence "$Action Current state: $State."
    }
}

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-test-server-go-no-go-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$script:rows = @()
$script:blockers = 0
$script:warnings = 0

$delivery = Latest-File $HandoverDir "mongoyia-test-server-delivery-*.zip"
$deliveryTar = Latest-File $HandoverDir "mongoyia-test-server-delivery-*.tar.gz"
$handoffStatus = if ($HandoffStatusPath -ne "") { Get-Item -LiteralPath (Resolve-ProjectPath $HandoffStatusPath) } else { Latest-NonSmokeFile $HandoverDir "mongoyia-handoff-status-*-validated.md" }
if ($null -eq $handoffStatus) { $handoffStatus = Latest-NonSmokeFile $HandoverDir "mongoyia-handoff-status-*.md" }
$restorePlan = Latest-NonSmokeFile $HandoverDir "mongoyia-test-server-restore-plan-*.md"
$inputGate = if ($InputGatePath -ne "") { Get-Item -LiteralPath (Resolve-ProjectPath $InputGatePath) } else { Latest-NonSmokeFile $HandoverDir "mongoyia-test-server-input-gate-*.md" }
$receiverStatus = if ($ReceiverStatusPath -ne "") { Get-Item -LiteralPath (Resolve-ProjectPath $ReceiverStatusPath) } else { Latest-StatusFile "receiver-*" "RECEIVER_STATUS.md" }
$restoreStatus = if ($RestoreStatusPath -ne "") { Get-Item -LiteralPath (Resolve-ProjectPath $RestoreStatusPath) } else { Latest-StatusFile "restore-*" "RESTORE_STATUS.md" }
$acceptance = Latest-File $AcceptanceDir "mongoyia-acceptance-*.md"
$signoff = Latest-File $AcceptanceDir "mongoyia-signoff-*.md"

if ($null -ne $delivery) {
    Add-Critical-StateCheck "Package" "Windows delivery archive checksum" (Test-Sha256 $delivery.FullName ($delivery.FullName + ".sha256")) $delivery.Name "Use the adjacent .sha256 sidecar."
} else {
    Add-Check "Package" "Windows delivery archive checksum" "BLOCK" "" "Build the delivery archive."
}
if ($null -ne $deliveryTar) {
    Add-Critical-StateCheck "Package" "Linux delivery archive checksum" (Test-Sha256 $deliveryTar.FullName ($deliveryTar.FullName + ".sha256")) $deliveryTar.Name "Use this on Linux receivers."
}

$handoffResult = if ($null -ne $handoffStatus) { Read-Result $handoffStatus.FullName } else { "MISSING" }
if ($handoffResult -eq "PASS" -or $handoffResult -eq "WARN") {
    Add-Check "Package" "Handoff status generated" "PASS" $handoffStatus.Name "Review remaining warnings before apply."
} else {
    Add-Check "Package" "Handoff status generated" "BLOCK" "" "Generate handoff status with -ValidateDelivery."
}

$restorePlanResult = if ($null -ne $restorePlan) { Read-Result $restorePlan.FullName } else { "MISSING" }
if ($restorePlanResult -eq "READY") {
    Add-Check "Restore" "Restore command plan is READY" "PASS" $restorePlan.Name "Use the generated dry-run/apply commands."
} else {
    Add-Check "Restore" "Restore command plan is READY" "BLOCK" $restorePlanResult "Generate a restore plan with real HTTPS/WSS test inputs and backup reference."
}

$sqlPath = Resolve-ProjectPath $SqlDumpPath
$sqlChecksum = Resolve-ProjectPath $SqlChecksumPath
Add-Critical-StateCheck "Restore" "SQL dump checksum" (Test-Sha256 $sqlPath $sqlChecksum) (Split-Path -Leaf $sqlPath) "Do not restore if this is not PASS."

$receiverResult = if ($null -ne $receiverStatus) { Read-Result $receiverStatus.FullName } else { "MISSING" }
if ($receiverResult -eq "PASS") {
    Add-Check "Receiver" "Receiver package validation" "PASS" $receiverStatus.FullName "Run again on the real receiver host."
} else {
    Add-Check "Receiver" "Receiver package validation" "BLOCK" $receiverResult "Run receiver validation on the test server."
}

$inputGateResult = if ($null -ne $inputGate) { Read-Result $inputGate.FullName } else { "MISSING" }
if ($inputGateResult -eq "PASS") {
    Add-Check "Safety" "Real input gate passed" "PASS" $inputGate.Name "Must be run with real .env, BaseUrl, ImUrl, SQL checksum, and backup reference."
} else {
    Add-Check "Safety" "Real input gate passed" "BLOCK" $inputGateResult "Run mongoyia-test-server-input-gate with RequireRestoreInputs on the real test server."
}

$restoreResult = if ($null -ne $restoreStatus) { Read-Result $restoreStatus.FullName } else { "MISSING" }
if ($restoreResult -eq "DRY_RUN" -or $restoreResult -eq "PASS") {
    Add-Check "Restore" "Restore dry-run reviewed" "WARN" $restoreStatus.FullName "Local dry-run evidence exists; repeat after real receiver .env is provisioned."
} else {
    Add-Check "Restore" "Restore dry-run reviewed" "BLOCK" $restoreResult "Run restore dry-run before apply."
}

if ($null -ne $acceptance -and (Read-Result $acceptance.FullName) -eq "PASS") {
    Add-Check "Acceptance" "Acceptance evidence exists" "WARN" $acceptance.Name "Current acceptance is local-only until test-server run is complete."
} else {
    Add-Check "Acceptance" "Acceptance evidence exists" "BLOCK" "" "Run full acceptance after restore/preflight."
}
if ($null -ne $signoff -and (Read-Result $signoff.FullName) -eq "PASS") {
    Add-Check "Acceptance" "Signoff evidence exists" "WARN" $signoff.Name "Needs real test-server owner signoff."
} else {
    Add-Check "Acceptance" "Signoff evidence exists" "BLOCK" "" "Collect real test-server signoff."
}

if ($null -ne $handoffStatus -and (Read-Contains $handoffStatus.FullName 'external test-server inputs \| PENDING')) {
    if ($ExternalInputsConfirmed.IsPresent -and $ExternalInputsConfirm -eq "EXTERNAL_TEST_INPUTS_CONFIRMED") {
        Add-Check "External" "External test-server inputs supplied" "PASS" $handoffStatus.Name "Operator confirmed real host, .env values, HTTPS/WSS, payment sandbox secrets, and QA owners."
    } elseif ($ExternalInputsConfirmed.IsPresent) {
        Add-Check "External" "External test-server inputs supplied" "BLOCK" $handoffStatus.Name "ExternalInputsConfirm must equal EXTERNAL_TEST_INPUTS_CONFIRMED."
    } else {
        Add-Check "External" "External test-server inputs supplied" "BLOCK" $handoffStatus.Name "Provide real host, .env values, HTTPS/WSS, payment sandbox secrets, and QA owners, then pass -ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED."
    }
} else {
    Add-Check "External" "External test-server inputs supplied" "PASS" "" "No pending external-input marker found in handoff status."
}

$result = if ($blockers -gt 0) { "NO-GO" } elseif ($warnings -gt 0) { "GO-WITH-WARNINGS" } else { "GO" }
$report = @(
    "# Mongoyia Test Server Go/No-Go",
    "",
    "- Result: $result",
    "- Blockers: $blockers",
    "- Warnings: $warnings",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "",
    "## Checks",
    "",
    "| Area | Check | Status | Evidence | Required action |",
    "|---|---|---:|---|---|"
) + $rows + @(
    "",
    "## Decision",
    "",
    "Apply restore is allowed only when Result is GO and every Safety/Restore/Receiver check is PASS. GO-WITH-WARNINGS still requires owner approval. NO-GO means do not run Apply.",
    "",
    "This report is a checklist. It does not contain real passwords, API keys, callback secrets, or private keys."
)
$report | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia test-server go/no-go report: $outputFull"
Write-Output "Result: $result"
if ($blockers -gt 0) {
    exit 1
}
