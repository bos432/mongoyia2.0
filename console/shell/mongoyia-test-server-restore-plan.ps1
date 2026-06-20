param(
    [string]$DeliveryArchivePath = "",
    [string]$SqlDumpPath = "../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql",
    [string]$SqlChecksumPath = "runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256",
    [string]$Database = "outer",
    [string]$BaseUrl = "",
    [string]$ImUrl = "",
    [string]$BackupReference = "",
    [string]$BackupArtifactPath = "",
    [string]$BackupChecksumPath = "",
    [string]$LinuxDeliveryArchivePath = "",
    [string]$LinuxSqlDumpPath = "",
    [string]$LinuxSqlChecksumPath = "",
    [string]$LinuxBackupArtifactPath = "",
    [string]$LinuxBackupChecksumPath = "",
    [string]$OutputPath = ""
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

function Latest-Delivery {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter "mongoyia-test-server-delivery-*.tar.gz" -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter "mongoyia-test-server-delivery-*.zip" -File -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
    }
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Quote-Ps {
    param([string]$Value)
    return '"' + ($Value -replace '"', '\"') + '"'
}

function Escape-Sh {
    param([string]$Value)
    return "'" + ($Value -replace "'", "'\''") + "'"
}

function Portable-LinuxPath {
    param([string]$Value, [string]$Prefix)
    if ($Value -eq "") { return "" }
    $leaf = Split-Path -Leaf $Value
    if ($Prefix -eq "") { return $leaf }
    return ($Prefix.TrimEnd("/") + "/" + $leaf)
}

function Production-Domain-Host {
    param([string]$Value)
    if ($Value -eq "") { return "" }
    $candidate = $Value.Trim()
    $domainHost = ""
    try {
        if ($candidate -match "^[a-z][a-z0-9+.-]*://") {
            $uri = [System.Uri]$candidate
            $domainHost = $uri.Host
        } else {
            $domainHost = ($candidate -split "/", 2)[0]
            $domainHost = ($domainHost -split ":", 2)[0]
        }
    } catch {
        $domainHost = $candidate
    }
    $domainHost = $domainHost.Trim().TrimEnd(".").ToLowerInvariant()
    if ($domainHost -in @("mongoyia.com", "www.mongoyia.com")) { return $domainHost }
    return ""
}

function Host-From-Url {
    param([string]$Value)
    if ($Value -eq "") { return "" }
    try {
        if ($Value -match "^[a-z][a-z0-9+.-]*://") {
            $uri = [System.Uri]$Value
            return $uri.Host.Trim().TrimEnd(".").ToLowerInvariant()
        }
    } catch {
        return ""
    }
    return ""
}

function Is-Placeholder {
    param([string]$Value)
    $lower = $Value.ToLowerInvariant()
    return $lower -eq "" -or
        $lower -like "replace-with-*" -or
        $lower -like "*example.com*" -or
        $lower -like "*placeholder*" -or
        $lower -like "*changeme*" -or
        $lower -like "*change-me*" -or
        $lower -like "*your-*"
}

function Add-Missing {
    param([string]$Message)
    $script:missing += "- $Message"
}

if ($DeliveryArchivePath -eq "") {
    $DeliveryArchivePath = Latest-Delivery
} else {
    $DeliveryArchivePath = Resolve-ProjectPath $DeliveryArchivePath
}
$SqlDumpPath = Resolve-ProjectPath $SqlDumpPath
$SqlChecksumPath = Resolve-ProjectPath $SqlChecksumPath
$BackupArtifactPath = Resolve-ProjectPath $BackupArtifactPath
$BackupChecksumPath = Resolve-ProjectPath $BackupChecksumPath

if ($LinuxDeliveryArchivePath -eq "") { $LinuxDeliveryArchivePath = Portable-LinuxPath $DeliveryArchivePath "runtime/handover" }
if ($LinuxSqlDumpPath -eq "") { $LinuxSqlDumpPath = Portable-LinuxPath $SqlDumpPath "" }
if ($LinuxSqlChecksumPath -eq "") { $LinuxSqlChecksumPath = Portable-LinuxPath $SqlChecksumPath "runtime/handover" }
if ($LinuxBackupArtifactPath -eq "") { $LinuxBackupArtifactPath = Portable-LinuxPath $BackupArtifactPath "backups" }
if ($LinuxBackupChecksumPath -eq "") { $LinuxBackupChecksumPath = Portable-LinuxPath $BackupChecksumPath "backups" }

$script:missing = @()
if ($DeliveryArchivePath -eq "" -or !(Test-Path -LiteralPath $DeliveryArchivePath -PathType Leaf)) { Add-Missing "Delivery archive is missing." }
if ($SqlDumpPath -eq "" -or !(Test-Path -LiteralPath $SqlDumpPath -PathType Leaf)) { Add-Missing "SQL dump file is missing." }
if ($SqlChecksumPath -eq "" -or !(Test-Path -LiteralPath $SqlChecksumPath -PathType Leaf)) { Add-Missing "SQL checksum sidecar is missing." }
if ($Database -eq "" -or $Database.ToLowerInvariant() -in @("mysql", "information_schema", "performance_schema", "sys")) { Add-Missing "Target database must be a non-system database name, usually outer." }
if ($BaseUrl -eq "" -or !$BaseUrl.StartsWith("https://")) { Add-Missing "BaseUrl must be a real HTTPS test URL." }
if ($ImUrl -eq "" -or !$ImUrl.StartsWith("wss://")) { Add-Missing "ImUrl must be a real WSS test URL." }
if (Is-Placeholder $BaseUrl) { Add-Missing "BaseUrl still looks like a placeholder." }
if (Is-Placeholder $ImUrl) { Add-Missing "ImUrl still looks like a placeholder." }
if ($BaseUrl -match "localhost|127\.0\.0\.1|0\.0\.0\.0") { Add-Missing "BaseUrl must not point to a local-only host for test apply mode." }
if ($ImUrl -match "localhost|127\.0\.0\.1|0\.0\.0\.0") { Add-Missing "ImUrl must not point to a local-only host for test apply mode." }
$baseHost = Host-From-Url $BaseUrl
$imHost = Host-From-Url $ImUrl
if ($baseHost -ne "" -and $imHost -ne "" -and $baseHost -ne $imHost) { Add-Missing "BaseUrl and ImUrl hosts must match the same test domain." }
foreach ($pair in @(@("BaseUrl", $BaseUrl), @("ImUrl", $ImUrl))) {
    $prodHost = Production-Domain-Host $pair[1]
    if ($prodHost -ne "") { Add-Missing "$($pair[0]) points to production domain $prodHost; use a test domain." }
}
if ($BackupReference -eq "" -and $BackupArtifactPath -eq "") { Add-Missing "BackupReference or BackupArtifactPath is required before apply mode." }
if ($BackupArtifactPath -ne "" -and !(Test-Path -LiteralPath $BackupArtifactPath -PathType Leaf)) { Add-Missing "BackupArtifactPath does not exist." }
if ($BackupChecksumPath -ne "" -and !(Test-Path -LiteralPath $BackupChecksumPath -PathType Leaf)) { Add-Missing "BackupChecksumPath does not exist." }

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-test-server-restore-plan-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$psReceiver = @(
    ".\console\shell\mongoyia-test-server-receiver.ps1",
    "-DeliveryArchivePath", (Quote-Ps $DeliveryArchivePath),
    "-BaseUrl", (Quote-Ps $BaseUrl)
) -join " "
$psInputGate = @(
    ".\console\shell\mongoyia-test-server-input-gate.ps1",
    "-BaseUrl", (Quote-Ps $BaseUrl),
    "-ImUrl", (Quote-Ps $ImUrl),
    "-DeliveryArchivePath", (Quote-Ps $DeliveryArchivePath),
    "-SqlDumpPath", (Quote-Ps $SqlDumpPath),
    "-SqlChecksumPath", (Quote-Ps $SqlChecksumPath),
    "-Database", (Quote-Ps $Database),
    "-BackupReference", (Quote-Ps $BackupReference),
    "-BackupArtifactPath", (Quote-Ps $BackupArtifactPath),
    "-BackupChecksumPath", (Quote-Ps $BackupChecksumPath),
    "-RequireRestoreInputs"
) -join " "
$psDryRun = @(
    ".\console\shell\mongoyia-test-server-restore.ps1",
    "-DeliveryArchivePath", (Quote-Ps $DeliveryArchivePath),
    "-SqlDumpPath", (Quote-Ps $SqlDumpPath),
    "-SqlChecksumPath", (Quote-Ps $SqlChecksumPath),
    "-Database", (Quote-Ps $Database),
    "-BaseUrl", (Quote-Ps $BaseUrl),
    "-RunReceiver",
    "-RunMigrate",
    "-RunPreflight"
) -join " "
$psDryRunAcceptance = @(
    ".\console\shell\mongoyia-test-server-restore.ps1",
    "-DeliveryArchivePath", (Quote-Ps $DeliveryArchivePath),
    "-SqlDumpPath", (Quote-Ps $SqlDumpPath),
    "-SqlChecksumPath", (Quote-Ps $SqlChecksumPath),
    "-Database", (Quote-Ps $Database),
    "-BaseUrl", (Quote-Ps $BaseUrl),
    "-ImUrl", (Quote-Ps $ImUrl),
    "-RunReceiver",
    "-RunMigrate",
    "-RunPreflight",
    "-RunAcceptance",
    "-CleanupAfterRun"
) -join " "
$applyParts = @(
    ".\console\shell\mongoyia-test-server-restore.ps1",
    "-Apply",
    "-BackupConfirmed",
    "-ApplyConfirm", "RESTORE_OUTER_TEST_SERVER",
    "-ExternalInputsConfirmed",
    "-ExternalInputsConfirm", "EXTERNAL_TEST_INPUTS_CONFIRMED",
    "-DeliveryArchivePath", (Quote-Ps $DeliveryArchivePath),
    "-SqlDumpPath", (Quote-Ps $SqlDumpPath),
    "-SqlChecksumPath", (Quote-Ps $SqlChecksumPath),
    "-Database", (Quote-Ps $Database),
    "-BaseUrl", (Quote-Ps $BaseUrl),
    "-ImUrl", (Quote-Ps $ImUrl),
    "-RunReceiver",
    "-RunMigrate",
    "-RunPreflight"
)
if ($BackupReference -ne "") { $applyParts += @("-BackupReference", (Quote-Ps $BackupReference)) }
if ($BackupArtifactPath -ne "") { $applyParts += @("-BackupArtifactPath", (Quote-Ps $BackupArtifactPath)) }
if ($BackupChecksumPath -ne "") { $applyParts += @("-BackupChecksumPath", (Quote-Ps $BackupChecksumPath)) }
$psApply = $applyParts -join " "
$psApplyAcceptance = ($applyParts + @("-RunAcceptance", "-CleanupAfterRun")) -join " "
$psAcceptance = @(
    ".\console\shell\mongoyia-acceptance.ps1",
    "-BaseUrl", (Quote-Ps $BaseUrl),
    "-Profile", "test",
    "-Strict",
    "-CleanupAfterRun",
    "-ImUrl", (Quote-Ps $ImUrl)
) -join " "

$shDryRunParts = @(
    "DELIVERY_ARCHIVE_PATH=$(Escape-Sh $LinuxDeliveryArchivePath)",
    "SQL_DUMP_PATH=$(Escape-Sh $LinuxSqlDumpPath)",
    "SQL_CHECKSUM_PATH=$(Escape-Sh $LinuxSqlChecksumPath)",
    "DATABASE=$(Escape-Sh $Database)",
    "BASE_URL=$(Escape-Sh $BaseUrl)",
    "RUN_RECEIVER=1",
    "RUN_MIGRATE=1",
    "RUN_PREFLIGHT=1"
)
$shInputGateParts = @(
    "DELIVERY_ARCHIVE_PATH=$(Escape-Sh $LinuxDeliveryArchivePath)",
    "SQL_DUMP_PATH=$(Escape-Sh $LinuxSqlDumpPath)",
    "SQL_CHECKSUM_PATH=$(Escape-Sh $LinuxSqlChecksumPath)",
    "DATABASE=$(Escape-Sh $Database)",
    "BASE_URL=$(Escape-Sh $BaseUrl)",
    "IM_URL=$(Escape-Sh $ImUrl)",
    "BACKUP_REFERENCE=$(Escape-Sh $BackupReference)",
    "BACKUP_ARTIFACT_PATH=$(Escape-Sh $LinuxBackupArtifactPath)",
    "BACKUP_CHECKSUM_PATH=$(Escape-Sh $LinuxBackupChecksumPath)",
    "REQUIRE_RESTORE_INPUTS=1"
)
$shInputGate = (($shInputGateParts | ForEach-Object { $_ + ' \' }) + "sh console/shell/mongoyia-test-server-input-gate.sh") -join "`n"
$shDryRun = (($shDryRunParts | ForEach-Object { $_ + ' \' }) + "sh console/shell/mongoyia-test-server-restore.sh") -join "`n"
$shDryRunAcceptanceParts = $shDryRunParts + @(
    "IM_URL=$(Escape-Sh $ImUrl)",
    "RUN_ACCEPTANCE=1",
    "CLEANUP_AFTER_RUN=1"
)
$shDryRunAcceptance = (($shDryRunAcceptanceParts | ForEach-Object { $_ + ' \' }) + "sh console/shell/mongoyia-test-server-restore.sh") -join "`n"
$shApplyParts = @(
    "APPLY=1",
    "BACKUP_CONFIRMED=1",
    "APPLY_CONFIRM=RESTORE_OUTER_TEST_SERVER",
    "EXTERNAL_INPUTS_CONFIRMED=1",
    "EXTERNAL_INPUTS_CONFIRM=EXTERNAL_TEST_INPUTS_CONFIRMED",
    "DELIVERY_ARCHIVE_PATH=$(Escape-Sh $LinuxDeliveryArchivePath)",
    "SQL_DUMP_PATH=$(Escape-Sh $LinuxSqlDumpPath)",
    "SQL_CHECKSUM_PATH=$(Escape-Sh $LinuxSqlChecksumPath)",
    "DATABASE=$(Escape-Sh $Database)",
    "BASE_URL=$(Escape-Sh $BaseUrl)",
    "IM_URL=$(Escape-Sh $ImUrl)",
    "RUN_RECEIVER=1",
    "RUN_MIGRATE=1",
    "RUN_PREFLIGHT=1"
)
if ($BackupReference -ne "") { $shApplyParts += "BACKUP_REFERENCE=$(Escape-Sh $BackupReference)" }
if ($LinuxBackupArtifactPath -ne "") { $shApplyParts += "BACKUP_ARTIFACT_PATH=$(Escape-Sh $LinuxBackupArtifactPath)" }
if ($LinuxBackupChecksumPath -ne "") { $shApplyParts += "BACKUP_CHECKSUM_PATH=$(Escape-Sh $LinuxBackupChecksumPath)" }
$shApply = (($shApplyParts | ForEach-Object { $_ + ' \' }) + "sh console/shell/mongoyia-test-server-restore.sh") -join "`n"
$shApplyAcceptance = (($shApplyParts + @("RUN_ACCEPTANCE=1", "CLEANUP_AFTER_RUN=1") | ForEach-Object { $_ + ' \' }) + "sh console/shell/mongoyia-test-server-restore.sh") -join "`n"
$shAcceptanceParts = @(
    "PROFILE=test",
    "STRICT=1",
    "CLEANUP_AFTER_RUN=1",
    "BASE_URL=$(Escape-Sh $BaseUrl)",
    "IM_URL=$(Escape-Sh $ImUrl)"
)
$shAcceptance = (($shAcceptanceParts | ForEach-Object { $_ + ' \' }) + "sh console/shell/mongoyia-acceptance.sh") -join "`n"

$result = if ($missing.Count -eq 0) { "READY" } else { "PENDING" }
$missingBlock = if ($missing.Count -eq 0) { @("- No missing inputs detected by this planner.") } else { $missing }
$report = @(
    "# Mongoyia Test Server Restore Plan",
    "",
    "- Result: $result",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Delivery archive: $DeliveryArchivePath",
    "- SQL dump: $SqlDumpPath",
    "- SQL checksum: $SqlChecksumPath",
    "- Database: $Database",
    "- BaseUrl: $BaseUrl",
    "- BaseUrl host: $baseHost",
    "- ImUrl: $ImUrl",
    "- ImUrl host: $imHost",
    "- Backup reference: $BackupReference",
    "- Backup artifact: $BackupArtifactPath",
    "",
    "## Linux Path Mapping",
    "",
    "| Item | Linux/Test-Server Path Used In Bash Commands |",
    "|---|---|",
    ("| Delivery archive | ``{0}`` |" -f $LinuxDeliveryArchivePath),
    ("| SQL dump | ``{0}`` |" -f $LinuxSqlDumpPath),
    ("| SQL checksum | ``{0}`` |" -f $LinuxSqlChecksumPath),
    ("| Backup artifact | ``{0}`` |" -f $LinuxBackupArtifactPath),
    ("| Backup checksum | ``{0}`` |" -f $LinuxBackupChecksumPath),
    "",
    "## Missing Or Unsafe Inputs",
    ""
) + $missingBlock + @(
    "",
    "## Windows Commands",
    "",
    "Receiver validation:",
    "",
    '```powershell',
    $psReceiver,
    '```',
    "",
    "Input gate:",
    "",
    '```powershell',
    $psInputGate,
    '```',
    "",
    "Restore dry-run:",
    "",
    '```powershell',
    $psDryRun,
    '```',
    "",
    "Restore dry-run with full acceptance:",
    "",
    '```powershell',
    $psDryRunAcceptance,
    '```',
    "",
    "Apply restore after dry-run, backup, and input gate are approved:",
    "",
    '```powershell',
    $psApply,
    '```',
    "",
    "Apply restore and then run full acceptance after dry-run, backup, and input gate are approved:",
    "",
    '```powershell',
    $psApplyAcceptance,
    '```',
    "",
    "Full acceptance only, after restore and strict preflight are PASS:",
    "",
    '```powershell',
    $psAcceptance,
    '```',
    "",
    "## Linux Commands",
    "",
    "Input gate:",
    "",
    '```bash',
    $shInputGate,
    '```',
    "",
    "Restore dry-run:",
    "",
    '```bash',
    $shDryRun,
    '```',
    "",
    "Restore dry-run with full acceptance:",
    "",
    '```bash',
    $shDryRunAcceptance,
    '```',
    "",
    "Apply restore after dry-run, backup, and input gate are approved:",
    "",
    '```bash',
    $shApply,
    '```',
    "",
    "Apply restore and then run full acceptance after dry-run, backup, and input gate are approved:",
    "",
    '```bash',
    $shApplyAcceptance,
    '```',
    "",
    "Full acceptance only, after restore and strict preflight are PASS:",
    "",
    '```bash',
    $shAcceptance,
    '```',
    "",
    "## Notes",
    "",
    "- This planner does not run the restore and does not print secrets.",
    "- Windows commands use the local paths shown at the top; Linux commands use the Linux/Test-Server paths above.",
    "- Do not use `mongoyia.com` or `www.mongoyia.com` for test-server restore.",
    "- Apply mode must run only after a backup/snapshot is confirmed.",
    "- Emergency apply-safety bypass is not part of the normal plan; if a documented emergency requires it, PowerShell must include `-SkipApplySafety -SkipApplySafetyConfirm SKIP_RESTORE_APPLY_SAFETY`, and Bash must include `SKIP_APPLY_SAFETY=1 SKIP_APPLY_SAFETY_CONFIRM=SKIP_RESTORE_APPLY_SAFETY`."
)
$report | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Test-server restore plan: $outputFull"
Write-Output "Result: $result"
