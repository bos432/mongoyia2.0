param(
    [string]$ArchivePath = "",
    [ValidateSet("reverse", "apply", "skip")]
    [string]$PatchMode = "reverse"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Latest-Archive {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^mongoyia-test-server-delivery-.+\.(zip|tar\.gz)$' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        throw "No test-server delivery archive found under runtime/handover."
    }
    return $file.FullName
}

function Normalize-Entry {
    param([string]$Path)
    $path = $Path.Replace("\", "/")
    while ($path.StartsWith("./")) {
        $path = $path.Substring(2)
    }
    return $path
}

if ($ArchivePath -eq "") {
    $ArchivePath = Latest-Archive
}
$ArchivePath = (Resolve-Path $ArchivePath).Path
$ChecksumPath = "$ArchivePath.sha256"
if (!(Test-Path -LiteralPath $ChecksumPath -PathType Leaf)) {
    throw "Missing delivery checksum: $ChecksumPath"
}

$expectedHash = ((Get-Content -LiteralPath $ChecksumPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
$actualHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($expectedHash -ne $actualHash) {
    throw "Delivery checksum mismatch. expected=$expectedHash actual=$actualHash"
}

$TempRoot = Join-Path $Root ("runtime/handover/test-server-delivery-verify-{0}-{1}" -f $PID, ([System.Guid]::NewGuid().ToString("N").Substring(0, 8)))
if (Test-Path -LiteralPath $TempRoot) {
    Remove-Item -LiteralPath $TempRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $TempRoot -Force | Out-Null

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
if ($ArchivePath -like "*.zip") {
    [System.IO.Compression.ZipFile]::ExtractToDirectory($ArchivePath, $TempRoot)
} elseif ($ArchivePath -like "*.tar.gz") {
    $tar = Get-Command tar -ErrorAction SilentlyContinue
    if ($null -eq $tar) {
        throw "tar command is required to validate tar.gz delivery archive: $ArchivePath"
    }
    & tar -xzf $ArchivePath -C $TempRoot
    if ($LASTEXITCODE -ne 0) {
        throw "tar extraction failed with exit code $LASTEXITCODE"
    }
} else {
    throw "Unsupported delivery archive type: $ArchivePath"
}

$entries = @(Get-ChildItem -LiteralPath $TempRoot -Recurse -File -Force | ForEach-Object {
    Normalize-Entry $_.FullName.Substring($TempRoot.Length).TrimStart("\", "/")
})

$requiredPatterns = @(
    '^MANIFEST\.md$',
    '^RECEIVER_README\.md$',
    '^mongoyia-test-server-inputs\.md$',
    '^mongoyia-external-integration-inputs\.md$',
    '^mongoyia-mongolian-review-workflow\.md$',
    '^mongoyia-mongolian-review-evidence\.md$',
    '^mongoyia-p2-evidence-pack\.md$',
    '^mongoyia-payment-sandbox-evidence\.md$',
    '^mongoyia-im-wss-evidence\.md$',
    '^mongoyia-production-readiness\.md$',
    '^mongoyia-production-scheduled-monitoring\.md$',
    '^mongoyia-production-load-test-evidence\.md$',
    '^mongoyia-production-evidence-summary\.md$',
    '^mongoyia-production-go-live-gate\.md$',
    '^mongoyia-production-rollout-rollback\.md$',
    '^receiver/mongoyia-test-server-receiver\.ps1$',
    '^receiver/mongoyia-test-server-receiver\.sh$',
    '^receiver/mongoyia-test-server-restore\.ps1$',
    '^receiver/mongoyia-test-server-restore\.sh$',
    '^receiver/mongoyia-test-server-restore-plan\.ps1$',
    '^receiver/mongoyia-test-server-restore-plan\.sh$',
    '^receiver/mongoyia-test-server-input-gate\.ps1$',
    '^receiver/mongoyia-test-server-input-gate\.sh$',
    '^receiver/mongoyia-test-server-input-gate-smoke\.ps1$',
    '^receiver/mongoyia-test-server-input-gate-smoke\.sh$',
    '^receiver/mongoyia-test-server-go-no-go\.ps1$',
    '^receiver/mongoyia-test-server-go-no-go\.sh$',
    '^receiver/mongoyia-test-server-go-no-go-smoke\.ps1$',
    '^receiver/mongoyia-test-server-go-no-go-smoke\.sh$',
    '^receiver/mongoyia-sql-dump-manifest\.ps1$',
    '^receiver/mongoyia-sql-dump-manifest\.sh$',
    '^receiver/mongoyia-env-redacted-report\.ps1$',
    '^receiver/mongoyia-env-redacted-report\.sh$',
    '^receiver/mongoyia-handoff-status\.ps1$',
    '^receiver/mongoyia-handoff-status\.sh$',
    '^receiver/mongoyia-p2-readiness\.ps1$',
    '^receiver/mongoyia-p2-readiness\.sh$',
    '^receiver/mongoyia-p2-evidence-pack\.ps1$',
    '^receiver/mongoyia-p2-evidence-pack\.sh$',
    '^receiver/mongoyia-payment-sandbox-evidence\.ps1$',
    '^receiver/mongoyia-payment-sandbox-evidence\.sh$',
    '^receiver/mongoyia-im-wss-evidence\.ps1$',
    '^receiver/mongoyia-im-wss-evidence\.sh$',
    '^receiver/mongoyia-mongolian-review-evidence\.ps1$',
    '^receiver/mongoyia-mongolian-review-evidence\.sh$',
    '^receiver/mongoyia-production-backup-verify\.ps1$',
    '^receiver/mongoyia-production-backup-verify\.sh$',
    '^receiver/mongoyia-production-load-smoke\.ps1$',
    '^receiver/mongoyia-production-load-smoke\.sh$',
    '^receiver/mongoyia-production-load-test-evidence\.ps1$',
    '^receiver/mongoyia-production-load-test-evidence\.sh$',
    '^receiver/mongoyia-production-scheduled-check\.ps1$',
    '^receiver/mongoyia-production-scheduled-check\.sh$',
    '^receiver/mongoyia-production-evidence-summary\.ps1$',
    '^receiver/mongoyia-production-evidence-summary\.sh$',
    '^receiver/mongoyia-production-go-live-gate\.ps1$',
    '^receiver/mongoyia-production-go-live-gate\.sh$',
    '^archives/mongoyia-handover-.+\.zip$',
    '^archives/mongoyia-handover-.+\.zip\.sha256$',
    '^archives/mongoyia-source-handover-.+\.zip$',
    '^archives/mongoyia-source-handover-.+\.zip\.sha256$',
    '^reports/mongoyia-test-server-preflight-.+\.md$',
    '^reports/mongoyia-handover-verify-.+\.md$'
)
foreach ($pattern in $requiredPatterns) {
    if (@($entries | Where-Object { $_ -match $pattern }).Count -lt 1) {
        throw "Missing required delivery entry matching: $pattern"
    }
}

foreach ($entry in $entries) {
    if ($entry -match '(^|/)\.env$') { throw "Forbidden real env file in delivery archive: $entry" }
    if ($entry -match '(^|/)vendor/') { throw "Forbidden vendor dependency in delivery archive: $entry" }
    if ($entry -match '(^|/)node_modules/') { throw "Forbidden node_modules dependency in delivery archive: $entry" }
    if ($entry -match '(^|/)web/attachment/') { throw "Forbidden uploaded attachment in delivery archive: $entry" }
    if ($entry -match '(^|/)web/assets/') { throw "Forbidden generated web asset in delivery archive: $entry" }
    if ($entry -match '\.(sql|dump|bak|7z|rar)$') { throw "Forbidden dump payload in delivery archive: $entry" }
}

$restorePs1 = Join-Path $TempRoot "receiver/mongoyia-test-server-restore.ps1"
$restoreSh = Join-Path $TempRoot "receiver/mongoyia-test-server-restore.sh"
$restorePlanPs1 = Join-Path $TempRoot "receiver/mongoyia-test-server-restore-plan.ps1"
$restorePlanSh = Join-Path $TempRoot "receiver/mongoyia-test-server-restore-plan.sh"
$inputGatePs1 = Join-Path $TempRoot "receiver/mongoyia-test-server-input-gate.ps1"
$inputGateSh = Join-Path $TempRoot "receiver/mongoyia-test-server-input-gate.sh"
$inputGateSmokePs1 = Join-Path $TempRoot "receiver/mongoyia-test-server-input-gate-smoke.ps1"
$inputGateSmokeSh = Join-Path $TempRoot "receiver/mongoyia-test-server-input-gate-smoke.sh"
$goNoGoPs1 = Join-Path $TempRoot "receiver/mongoyia-test-server-go-no-go.ps1"
$goNoGoSh = Join-Path $TempRoot "receiver/mongoyia-test-server-go-no-go.sh"
$goNoGoSmokePs1 = Join-Path $TempRoot "receiver/mongoyia-test-server-go-no-go-smoke.ps1"
$goNoGoSmokeSh = Join-Path $TempRoot "receiver/mongoyia-test-server-go-no-go-smoke.sh"
$p2ReadinessPs1 = Join-Path $TempRoot "receiver/mongoyia-p2-readiness.ps1"
$p2ReadinessSh = Join-Path $TempRoot "receiver/mongoyia-p2-readiness.sh"
$receiverReadme = Join-Path $TempRoot "RECEIVER_README.md"
$manifest = Join-Path $TempRoot "MANIFEST.md"
$restorePs1Text = Get-Content -LiteralPath $restorePs1 -Raw
$restoreShText = Get-Content -LiteralPath $restoreSh -Raw
$restorePlanPs1Text = Get-Content -LiteralPath $restorePlanPs1 -Raw
$restorePlanShText = Get-Content -LiteralPath $restorePlanSh -Raw
$inputGatePs1Text = Get-Content -LiteralPath $inputGatePs1 -Raw
$inputGateShText = Get-Content -LiteralPath $inputGateSh -Raw
$inputGateSmokePs1Text = Get-Content -LiteralPath $inputGateSmokePs1 -Raw
$inputGateSmokeShText = Get-Content -LiteralPath $inputGateSmokeSh -Raw
$goNoGoPs1Text = Get-Content -LiteralPath $goNoGoPs1 -Raw
$goNoGoShText = Get-Content -LiteralPath $goNoGoSh -Raw
$goNoGoSmokePs1Text = Get-Content -LiteralPath $goNoGoSmokePs1 -Raw
$goNoGoSmokeShText = Get-Content -LiteralPath $goNoGoSmokeSh -Raw
$p2ReadinessPs1Text = Get-Content -LiteralPath $p2ReadinessPs1 -Raw
$p2ReadinessShText = Get-Content -LiteralPath $p2ReadinessSh -Raw
$receiverReadmeText = Get-Content -LiteralPath $receiverReadme -Raw
$manifestText = Get-Content -LiteralPath $manifest -Raw
if ($restorePs1Text -notmatch 'SkipApplySafetyConfirm' -or $restorePs1Text -notmatch 'SKIP_RESTORE_APPLY_SAFETY') {
    throw "Receiver restore PowerShell script is missing SkipApplySafety confirmation guard."
}
if ($restoreShText -notmatch 'SKIP_APPLY_SAFETY_CONFIRM' -or $restoreShText -notmatch 'SKIP_RESTORE_APPLY_SAFETY') {
    throw "Receiver restore shell script is missing SkipApplySafety confirmation guard."
}
if ($restorePs1Text -notmatch 'mongoyia-test-server-go-no-go' -or $restorePs1Text -notmatch 'ExternalInputsConfirm') {
    throw "Receiver restore PowerShell script is missing go/no-go apply guard."
}
if ($restoreShText -notmatch 'mongoyia-test-server-go-no-go' -or $restoreShText -notmatch 'EXTERNAL_INPUTS_CONFIRM') {
    throw "Receiver restore shell script is missing go/no-go apply guard."
}
if ($restorePlanPs1Text -notmatch 'SKIP_RESTORE_APPLY_SAFETY' -or $restorePlanPs1Text -notmatch 'SKIP_APPLY_SAFETY_CONFIRM') {
    throw "Receiver restore-plan PowerShell script is missing emergency apply-safety bypass notes."
}
if ($restorePlanShText -notmatch 'SKIP_RESTORE_APPLY_SAFETY' -or $restorePlanShText -notmatch 'SKIP_APPLY_SAFETY_CONFIRM') {
    throw "Receiver restore-plan shell script is missing emergency apply-safety bypass notes."
}
if ($restorePlanPs1Text -notmatch 'ExternalInputsConfirm' -or $restorePlanPs1Text -notmatch 'EXTERNAL_TEST_INPUTS_CONFIRMED') {
    throw "Receiver restore-plan PowerShell script is missing external-input go/no-go confirmation in apply commands."
}
if ($restorePlanShText -notmatch 'EXTERNAL_INPUTS_CONFIRM' -or $restorePlanShText -notmatch 'EXTERNAL_TEST_INPUTS_CONFIRMED') {
    throw "Receiver restore-plan shell script is missing external-input go/no-go confirmation in apply commands."
}
if ($receiverReadmeText -notmatch 'SKIP_RESTORE_APPLY_SAFETY' -or $receiverReadmeText -notmatch 'Do not skip the input gate' -or $receiverReadmeText -notmatch 'mongoyia-test-server-go-no-go' -or $receiverReadmeText -notmatch 'NO-GO') {
    throw "Receiver README is missing input-gate/apply-safety bypass guidance."
}
if ($receiverReadmeText -notmatch 'EXTERNAL_TEST_INPUTS_CONFIRMED' -or $receiverReadmeText -notmatch 'Restore apply runs it again automatically before database restore') {
    throw "Receiver README is missing external-input or automatic go/no-go apply guidance."
}
if ($manifestText -notmatch 'EXTERNAL_TEST_INPUTS_CONFIRMED' -or $manifestText -notmatch 'Restore Apply runs input-gate and go/no-go again before database restore') {
    throw "Delivery manifest is missing automatic go/no-go apply guidance."
}
if ($manifestText -notmatch 'mongoyia-test-server-go-no-go-smoke') {
    throw "Delivery manifest is missing go/no-go smoke guidance."
}
$inputGateRequiredTokens = @(
    'DEFAULT_ROUTE',
    'STORE_PLATFORM_DOMAIN',
    'IM_WEBSOCKET_URL',
    'QPAY_AUTH_URL',
    'QPAY_INVOICE_URL',
    'QPAY_CALLBACK_BASE',
    'LIANLIAN_SANDBOX',
    'IM_PORT',
    'IM_MAX_TEXT_MESSAGE_LENGTH',
    'IM_MAX_IMAGE_MESSAGE_LENGTH',
    'IM_CHAT_TABLE',
    'CHAT_UPLOAD_URL',
    'UPLOAD_HTTP_PREFIX'
)
foreach ($token in $inputGateRequiredTokens) {
    if ($inputGatePs1Text -notmatch [regex]::Escape($token)) {
        throw "Receiver input-gate PowerShell script is missing required check token: $token"
    }
    if ($inputGateShText -notmatch [regex]::Escape($token)) {
        throw "Receiver input-gate shell script is missing required check token: $token"
    }
}
foreach ($token in @('Expected good input-gate smoke to pass', 'Expected bad input-gate smoke to fail', 'php-good.env', 'php-bad.env')) {
    if ($inputGateSmokePs1Text -notmatch [regex]::Escape($token)) {
        throw "Receiver input-gate smoke PowerShell script is missing required check token: $token"
    }
}
foreach ($token in @('expected bad input-gate smoke to fail', 'php-good.env', 'php-bad.env')) {
    if ($inputGateSmokeShText -notmatch [regex]::Escape($token)) {
        throw "Receiver input-gate smoke shell script is missing required check token: $token"
    }
}
foreach ($token in @('NO-GO', 'GO-WITH-WARNINGS', 'Real input gate passed', 'External test-server inputs supplied')) {
    if ($goNoGoPs1Text -notmatch [regex]::Escape($token)) {
        throw "Receiver go/no-go PowerShell script is missing required check token: $token"
    }
    if ($goNoGoShText -notmatch [regex]::Escape($token)) {
        throw "Receiver go/no-go shell script is missing required check token: $token"
    }
}
foreach ($token in @('QPAY_AUTH_URL', 'QPAY_INVOICE_URL', 'RequireExternalInputs', 'P2 Readiness Report')) {
    if ($p2ReadinessPs1Text -notmatch [regex]::Escape($token)) {
        throw "Receiver P2 readiness PowerShell script is missing required check token: $token"
    }
}
foreach ($token in @('QPAY_AUTH_URL', 'QPAY_INVOICE_URL', 'REQUIRE_EXTERNAL_INPUTS', 'P2 Readiness Report')) {
    if ($p2ReadinessShText -notmatch [regex]::Escape($token)) {
        throw "Receiver P2 readiness shell script is missing required check token: $token"
    }
}
foreach ($token in @('missing external confirmation go/no-go smoke to block', 'wrong external confirmation go/no-go smoke to block', 'confirmed external inputs go/no-go smoke to pass external gate')) {
    if ($goNoGoSmokePs1Text -notmatch [regex]::Escape($token)) {
        throw "Receiver go/no-go smoke PowerShell script is missing required check token: $token"
    }
    if ($goNoGoSmokeShText -notmatch [regex]::Escape($token)) {
        throw "Receiver go/no-go smoke shell script is missing required check token: $token"
    }
}

$handover = Get-ChildItem -LiteralPath (Join-Path $TempRoot "archives") -Filter "mongoyia-handover-*.zip" -File | Select-Object -First 1
$sourceHandover = Get-ChildItem -LiteralPath (Join-Path $TempRoot "archives") -Filter "mongoyia-source-handover-*.zip" -File | Select-Object -First 1
$preflight = Get-ChildItem -LiteralPath (Join-Path $TempRoot "reports") -Filter "mongoyia-test-server-preflight-*.md" -File | Select-Object -First 1
$handoverVerify = Get-ChildItem -LiteralPath (Join-Path $TempRoot "reports") -Filter "mongoyia-handover-verify-*.md" -File | Select-Object -First 1

& "$PSScriptRoot\mongoyia-validate-handover-archive.ps1" -ArchivePath $handover.FullName

& "$PSScriptRoot\mongoyia-validate-source-handover.ps1" -ArchivePath $sourceHandover.FullName -PatchMode $PatchMode

$preflightText = Get-Content -LiteralPath $preflight.FullName -Raw
if ($preflightText -notmatch '(?m)^- Result: PASS\r?$') {
    throw "Preflight report inside delivery archive is not marked PASS."
}
$handoverVerifyText = Get-Content -LiteralPath $handoverVerify.FullName -Raw
if ($handoverVerifyText -notmatch '(?m)^- Result: PASS\r?$') {
    throw "Handover verify report inside delivery archive is not marked PASS."
}
foreach ($token in @('- PASS: input-gate smoke', '- PASS: go/no-go smoke')) {
    if ($handoverVerifyText -notmatch [regex]::Escape($token)) {
        throw "Handover verify report inside delivery archive is missing smoke evidence: $token"
    }
}

Write-Output "Test-server delivery validation: PASS"
Write-Output "Archive: $ArchivePath"
Write-Output "Checksum: PASS ($ChecksumPath)"
Write-Output "Entries: $($entries.Count)"
Write-Output "Patch mode: $PatchMode"
