param(
    [string]$DeliveryArchivePath = "",
    [string]$WorkDir = "",
    [string]$BaseUrl = "",
    [string]$Php = "php",
    [switch]$RunPreflight,
    [switch]$SkipApi,
    [switch]$SkipConnectivity
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Latest-DeliveryArchive {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^mongoyia-test-server-delivery-.+\.(zip|tar\.gz)$' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        throw "No test-server delivery archive found. Pass -DeliveryArchivePath."
    }
    return $file.FullName
}

function Resolve-ProjectPath {
    param([string]$Path)
    if ($Path -eq "") { return "" }
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Assert-Sha256 {
    param([string]$Path)
    $checksumPath = "$Path.sha256"
    if (!(Test-Path -LiteralPath $checksumPath -PathType Leaf)) {
        throw "Missing checksum: $checksumPath"
    }
    $expected = ((Get-Content -LiteralPath $checksumPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
    $actual = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($expected -ne $actual) {
        throw "Checksum mismatch for $Path. expected=$expected actual=$actual"
    }
}

function Normalize-Entry {
    param([string]$Path)
    $path = $Path.Replace("\", "/")
    while ($path.StartsWith("./")) {
        $path = $path.Substring(2)
    }
    return $path
}

if ($DeliveryArchivePath -eq "") {
    $DeliveryArchivePath = Latest-DeliveryArchive
} else {
    $DeliveryArchivePath = Resolve-ProjectPath $DeliveryArchivePath
}
$DeliveryArchivePath = (Resolve-Path -LiteralPath $DeliveryArchivePath).Path
Assert-Sha256 $DeliveryArchivePath

if ($WorkDir -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $suffix = [System.Guid]::NewGuid().ToString("N").Substring(0, 8)
    $WorkDir = Join-Path $Root "runtime/handover/receiver-$stamp-$suffix"
} else {
    $WorkDir = Resolve-ProjectPath $WorkDir
}
if (Test-Path -LiteralPath $WorkDir) {
    throw "Receiver work directory already exists: $WorkDir"
}
New-Item -ItemType Directory -Path $WorkDir -Force | Out-Null

$extractDir = Join-Path $WorkDir "delivery"
New-Item -ItemType Directory -Path $extractDir -Force | Out-Null

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
if ($DeliveryArchivePath -like "*.zip") {
    [System.IO.Compression.ZipFile]::ExtractToDirectory($DeliveryArchivePath, $extractDir)
} elseif ($DeliveryArchivePath -like "*.tar.gz") {
    $tar = Get-Command tar -ErrorAction SilentlyContinue
    if ($null -eq $tar) {
        throw "tar command is required to extract tar.gz delivery archive: $DeliveryArchivePath"
    }
    & tar -xzf $DeliveryArchivePath -C $extractDir
    if ($LASTEXITCODE -ne 0) {
        throw "tar extraction failed with exit code $LASTEXITCODE"
    }
} else {
    throw "Unsupported delivery archive type: $DeliveryArchivePath"
}

$entries = @(Get-ChildItem -LiteralPath $extractDir -Recurse -File -Force | ForEach-Object {
    Normalize-Entry $_.FullName.Substring($extractDir.Length).TrimStart("\", "/")
})

$requiredPatterns = @(
    '^MANIFEST\.md$',
    '^RECEIVER_README\.md$',
    '^receiver/mongoyia-test-server-receiver\.ps1$',
    '^receiver/mongoyia-test-server-receiver\.sh$',
    '^receiver/mongoyia-test-server-restore-plan\.ps1$',
    '^receiver/mongoyia-test-server-restore-plan\.sh$',
    '^receiver/mongoyia-test-server-input-gate\.ps1$',
    '^receiver/mongoyia-test-server-input-gate\.sh$',
    '^receiver/mongoyia-test-server-input-gate-smoke\.ps1$',
    '^receiver/mongoyia-test-server-input-gate-smoke\.sh$',
    '^receiver/mongoyia-test-server-go-no-go\.ps1$',
    '^receiver/mongoyia-test-server-go-no-go\.sh$',
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

$archivesDir = Join-Path $extractDir "archives"
$handover = Get-ChildItem -LiteralPath $archivesDir -Filter "mongoyia-handover-*.zip" -File | Select-Object -First 1
$sourceHandover = Get-ChildItem -LiteralPath $archivesDir -Filter "mongoyia-source-handover-*.zip" -File | Select-Object -First 1
$preflight = Get-ChildItem -LiteralPath (Join-Path $extractDir "reports") -Filter "mongoyia-test-server-preflight-*.md" -File | Select-Object -First 1

Assert-Sha256 $handover.FullName
Assert-Sha256 $sourceHandover.FullName

$preflightText = Get-Content -LiteralPath $preflight.FullName -Raw
if ($preflightText -notmatch '(?m)^- Result: PASS\r?$') {
    throw "Preflight report inside delivery archive is not marked PASS."
}

$statusPath = Join-Path $WorkDir "RECEIVER_STATUS.md"
$status = @(
    "# Mongoyia Test Server Receiver Status",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Project root: $Root",
    "- Delivery archive: $DeliveryArchivePath",
    "- Extracted to: $extractDir",
    "- Handover archive: $($handover.FullName)",
    "- Source handover archive: $($sourceHandover.FullName)",
    "- Included preflight report: $($preflight.FullName)",
    "- Checksum validation: PASS",
    "- Included preflight marker: PASS",
    "",
    "## Next Required Receiver Steps",
    "",
    "1. Copy the SQL dump and `.sha256` sidecar to this test server.",
    "2. Create real PHP and Python IM `.env` files from `.env.test.example` and replace placeholders.",
    "3. Generate a restore command plan with `receiver/mongoyia-test-server-restore-plan`.",
    "4. Run the restore dry-run command from the generated plan.",
    "5. Generate `receiver/mongoyia-test-server-go-no-go`; do not run Apply while it reports `NO-GO`.",
    "6. Run apply mode only after backup/snapshot, input gate, dry-run, go/no-go, and external inputs are approved.",
    "7. Run strict preflight and then full acceptance.",
    "",
    "## Restore Plan Command Template",
    "",
    '```powershell',
    ".\console\shell\mongoyia-test-server-restore-plan.ps1 -DeliveryArchivePath `"$DeliveryArchivePath`" -SqlDumpPath `"<dump.sql>`" -SqlChecksumPath `"runtime\handover\<dump.sql>.sha256`" -BaseUrl `"https://<test-domain>`" -ImUrl `"wss://<test-domain>/<im-path>`" -BackupReference `"snapshot-or-ticket-id`"",
    '```',
    "",
    '```bash',
    "DELIVERY_ARCHIVE_PATH='$DeliveryArchivePath' SQL_DUMP_PATH='<dump.sql>' SQL_CHECKSUM_PATH='runtime/handover/<dump.sql>.sha256' BASE_URL='https://<test-domain>' IM_URL='wss://<test-domain>/<im-path>' BACKUP_REFERENCE='snapshot-or-ticket-id' sh console/shell/mongoyia-test-server-restore-plan.sh",
    '```',
    "",
    "## Apply Safety Reminder",
    "",
    "- Restore apply automatically runs `mongoyia-test-server-input-gate` and `mongoyia-test-server-go-no-go` before database restore.",
    "- If handoff status still reports external inputs as pending, include `-ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED` or `EXTERNAL_INPUTS_CONFIRMED=1 EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED` only after the real test-server values are supplied and approved.",
    "- Emergency bypass requires the full apply-safety bypass confirmation `SKIP_RESTORE_APPLY_SAFETY`."
)
$status | Set-Content -LiteralPath $statusPath -Encoding UTF8

Write-Output "Mongoyia test-server receiver validation: PASS"
Write-Output "Delivery archive: $DeliveryArchivePath"
Write-Output "Extracted to: $extractDir"
Write-Output "Status report: $statusPath"
Write-Output "Handover archive: $($handover.FullName)"
Write-Output "Source handover archive: $($sourceHandover.FullName)"
Write-Output ""
Write-Output "Recommended next step: generate a restore command plan before dry-run/apply, then run go/no-go before Apply. Apply will run input-gate and go/no-go again before database restore:"
Write-Output ".\console\shell\mongoyia-test-server-restore-plan.ps1 -DeliveryArchivePath `"$DeliveryArchivePath`" -SqlDumpPath `"<dump.sql>`" -SqlChecksumPath `"runtime\handover\<dump.sql>.sha256`" -BaseUrl `"https://<test-domain>`" -ImUrl `"wss://<test-domain>/<im-path>`" -BackupReference `"snapshot-or-ticket-id`""
Write-Output ".\console\shell\mongoyia-test-server-go-no-go.ps1"
Write-Output "When real external inputs are approved, Apply commands must include: -ExternalInputsConfirmed -ExternalInputsConfirm EXTERNAL_TEST_INPUTS_CONFIRMED"

if ($RunPreflight.IsPresent) {
    Write-Output ""
    Write-Output "Running strict test-server preflight..."
    $argsList = @(
        "-BaseUrl", $BaseUrl,
        "-Profile", "test",
        "-Strict", "1",
        "-Php", $Php
    )
    if ($SkipApi.IsPresent) { $argsList += "-SkipApi" }
    if ($SkipConnectivity.IsPresent) { $argsList += "-SkipConnectivity" }
    & "$PSScriptRoot\mongoyia-test-server-preflight-report.ps1" @argsList
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
