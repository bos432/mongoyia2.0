param(
    [string]$OutputDir = "runtime/handover/go-no-go-smoke"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

$SmokeRoot = Join-Path $Root $OutputDir
if (Test-Path -LiteralPath $SmokeRoot) {
    $resolvedSmoke = (Resolve-Path -LiteralPath $SmokeRoot).Path
    $resolvedRuntime = (Resolve-Path -LiteralPath (Join-Path $Root "runtime")).Path
    if (!$resolvedSmoke.StartsWith($resolvedRuntime)) {
        throw "Refusing to remove unexpected smoke path: $resolvedSmoke"
    }
    Remove-Item -LiteralPath $SmokeRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $SmokeRoot -Force | Out-Null

$handoverDir = Join-Path $SmokeRoot "handover"
$acceptanceDir = Join-Path $SmokeRoot "acceptance"
$receiverDir = Join-Path $handoverDir "receiver-smoke"
$restoreDir = Join-Path $handoverDir "restore-smoke"
New-Item -ItemType Directory -Path $handoverDir, $acceptanceDir, $receiverDir, $restoreDir -Force | Out-Null

$delivery = Join-Path $handoverDir "mongoyia-test-server-delivery-smoke.zip"
"smoke delivery" | Set-Content -LiteralPath $delivery -Encoding ASCII
$deliveryHash = (Get-FileHash -LiteralPath $delivery -Algorithm SHA256).Hash.ToLowerInvariant()
"$deliveryHash  mongoyia-test-server-delivery-smoke.zip" | Set-Content -LiteralPath ($delivery + ".sha256") -Encoding ASCII

$sqlDump = Join-Path $SmokeRoot "outer-smoke.sql"
"-- smoke sql" | Set-Content -LiteralPath $sqlDump -Encoding ASCII
$sqlHash = (Get-FileHash -LiteralPath $sqlDump -Algorithm SHA256).Hash.ToLowerInvariant()
$sqlChecksum = Join-Path $SmokeRoot "outer-smoke.sql.sha256"
"$sqlHash  outer-smoke.sql" | Set-Content -LiteralPath $sqlChecksum -Encoding ASCII

$handoffStatus = Join-Path $handoverDir "mongoyia-handoff-status-smoke-validated.md"
@"
# Smoke Handoff Status

- Result: WARN

| Check | Status |
|---|---|
| external test-server inputs | PENDING |
"@ | Set-Content -LiteralPath $handoffStatus -Encoding ASCII

$restorePlan = Join-Path $handoverDir "mongoyia-test-server-restore-plan-ready.md"
"- Result: READY" | Set-Content -LiteralPath $restorePlan -Encoding ASCII
$inputGate = Join-Path $handoverDir "mongoyia-test-server-input-gate-smoke-real.md"
"- Result: PASS" | Set-Content -LiteralPath $inputGate -Encoding ASCII
$receiverStatus = Join-Path $receiverDir "RECEIVER_STATUS.md"
"Mongoyia test-server receiver validation: PASS" | Set-Content -LiteralPath $receiverStatus -Encoding ASCII
$restoreStatus = Join-Path $restoreDir "RESTORE_STATUS.md"
"- Result: DRY_RUN" | Set-Content -LiteralPath $restoreStatus -Encoding ASCII
"- Result: PASS" | Set-Content -LiteralPath (Join-Path $acceptanceDir "mongoyia-acceptance-smoke.md") -Encoding ASCII
"- Result: PASS" | Set-Content -LiteralPath (Join-Path $acceptanceDir "mongoyia-signoff-smoke.md") -Encoding ASCII

$powerShell = (Get-Command pwsh -ErrorAction SilentlyContinue | Select-Object -First 1).Source
if ($null -eq $powerShell -or $powerShell -eq "") {
    $powerShell = (Get-Command powershell -ErrorAction Stop | Select-Object -First 1).Source
}
$goNoGoScript = Join-Path $PSScriptRoot "mongoyia-test-server-go-no-go.ps1"

function Invoke-GoNoGoSmoke {
    param([string]$Name, [string[]]$ExtraArgs)
    $report = Join-Path $SmokeRoot "$Name.md"
    $argsList = @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", $goNoGoScript,
        "-OutputPath", $report,
        "-HandoverDir", $handoverDir,
        "-AcceptanceDir", $acceptanceDir,
        "-SqlDumpPath", $sqlDump,
        "-SqlChecksumPath", $sqlChecksum,
        "-HandoffStatusPath", $handoffStatus,
        "-InputGatePath", $inputGate,
        "-ReceiverStatusPath", $receiverStatus,
        "-RestoreStatusPath", $restoreStatus
    ) + $ExtraArgs
    & $powerShell @argsList | Out-Null
    return @{ ExitCode = $LASTEXITCODE; Report = $report; Text = (Get-Content -LiteralPath $report -Raw) }
}

$missing = Invoke-GoNoGoSmoke "missing-confirm" @()
if ($missing.ExitCode -eq 0 -or $missing.Text -notmatch '(?m)^- Result: NO-GO\r?$' -or $missing.Text -notmatch 'External test-server inputs supplied \| BLOCK') {
    throw "Expected missing external confirmation go/no-go smoke to block."
}

$wrong = Invoke-GoNoGoSmoke "wrong-confirm" @("-ExternalInputsConfirmed", "-ExternalInputsConfirm", "WRONG")
if ($wrong.ExitCode -eq 0 -or $wrong.Text -notmatch '(?m)^- Result: NO-GO\r?$' -or $wrong.Text -notmatch 'ExternalInputsConfirm must equal EXTERNAL_TEST_INPUTS_CONFIRMED') {
    throw "Expected wrong external confirmation go/no-go smoke to block."
}

$confirmed = Invoke-GoNoGoSmoke "confirmed" @("-ExternalInputsConfirmed", "-ExternalInputsConfirm", "EXTERNAL_TEST_INPUTS_CONFIRMED")
if ($confirmed.ExitCode -ne 0 -or $confirmed.Text -notmatch '(?m)^- Result: GO-WITH-WARNINGS\r?$' -or $confirmed.Text -notmatch 'External test-server inputs supplied \| PASS') {
    throw "Expected confirmed external inputs go/no-go smoke to pass external gate."
}

Remove-Item -LiteralPath $SmokeRoot -Recurse -Force
Write-Output "Mongoyia test-server go/no-go smoke: PASS"
