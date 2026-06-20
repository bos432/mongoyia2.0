param(
    [string]$SqlDumpPath = "",
    [string]$Database = "outer",
    [string]$Mysql = "mysql",
    [string]$MysqlHost = "127.0.0.1",
    [int]$MysqlPort = 3306,
    [string]$MysqlUser = "root",
    [string]$MysqlPassword = "",
    [string]$MysqlDefaultsExtraFile = "",
    [string]$SqlChecksumPath = "",
    [string]$ExpectedSqlSha256 = "",
    [string]$DeliveryArchivePath = "",
    [string]$BaseUrl = "",
    [string]$ImUrl = "",
    [string]$Php = "php",
    [string]$Python = "python",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [string]$WorkDir = "",
    [switch]$Apply,
    [switch]$SkipInputGate,
    [switch]$AllowProductionDomainForTest,
    [switch]$BackupConfirmed,
    [string]$BackupArtifactPath = "",
    [string]$BackupChecksumPath = "",
    [string]$ExpectedBackupSha256 = "",
    [string]$BackupReference = "",
    [string]$ApplyConfirm = "",
    [switch]$SkipApplySafety,
    [string]$SkipApplySafetyConfirm = "",
    [switch]$ExternalInputsConfirmed,
    [string]$ExternalInputsConfirm = "",
    [switch]$RunReceiver,
    [switch]$RunMigrate,
    [switch]$RunPreflight,
    [switch]$RunAcceptance,
    [switch]$CleanupAfterRun,
    [switch]$SkipApi,
    [switch]$SkipConnectivity
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

function Quote-Cmd {
    param([string]$Value)
    return '"' + ($Value -replace '"', '\"') + '"'
}

function Mysql-Args {
    $args = @("--default-character-set=utf8mb4")
    if ($MysqlDefaultsExtraFile -ne "") {
        $args += "--defaults-extra-file=$MysqlDefaultsExtraFile"
    }
    $args += "-h"
    $args += $MysqlHost
    $args += "-P"
    $args += [string]$MysqlPort
    if ($MysqlUser -ne "") {
        $args += "-u"
        $args += $MysqlUser
    }
    if ($MysqlPassword -ne "") {
        $args += "--password=$MysqlPassword"
    }
    $args += $Database
    return $args
}

function Mask-Mysql-Args {
    param([string[]]$ArgsList)
    return @($ArgsList | ForEach-Object {
        if ($_ -like "--password=*") { "--password=***" } else { $_ }
    })
}

function Run-Step {
    param([string]$Name, [scriptblock]$Block)
    Write-Output ""
    Write-Output "== $Name =="
    & $Block
    if ($LASTEXITCODE -is [int] -and $LASTEXITCODE -ne 0) {
        throw "$Name failed with exit code $LASTEXITCODE"
    }
}

function Read-Sha256 {
    param([string]$Path)
    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Checksum file not found: $Path"
    }
    return ((Get-Content -LiteralPath $Path -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
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

$allowProductionDomain = $AllowProductionDomainForTest.IsPresent -or ($env:ALLOW_PRODUCTION_DOMAIN_FOR_TEST -in @("1", "true", "TRUE", "yes", "YES"))

if ($WorkDir -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $WorkDir = Join-Path $Root "runtime/handover/restore-$stamp"
} else {
    $WorkDir = Resolve-ProjectPath $WorkDir
}
if (!(Test-Path -LiteralPath $WorkDir)) {
    New-Item -ItemType Directory -Path $WorkDir -Force | Out-Null
}

$resolvedSqlDump = Resolve-ProjectPath $SqlDumpPath
$resolvedDelivery = Resolve-ProjectPath $DeliveryArchivePath
$resolvedSqlChecksum = Resolve-ProjectPath $SqlChecksumPath
$resolvedBackupArtifact = Resolve-ProjectPath $BackupArtifactPath
$resolvedBackupChecksum = Resolve-ProjectPath $BackupChecksumPath
if ($SqlDumpPath -ne "" -and !(Test-Path -LiteralPath $resolvedSqlDump -PathType Leaf)) {
    throw "SQL dump not found: $resolvedSqlDump"
}
if ($DeliveryArchivePath -ne "" -and !(Test-Path -LiteralPath $resolvedDelivery -PathType Leaf)) {
    throw "Delivery archive not found: $resolvedDelivery"
}
if ($SqlChecksumPath -ne "" -and !(Test-Path -LiteralPath $resolvedSqlChecksum -PathType Leaf)) {
    throw "SQL checksum file not found: $resolvedSqlChecksum"
}
if ($BackupArtifactPath -ne "" -and !(Test-Path -LiteralPath $resolvedBackupArtifact -PathType Leaf)) {
    throw "Backup artifact not found: $resolvedBackupArtifact"
}
if ($BackupChecksumPath -ne "" -and !(Test-Path -LiteralPath $resolvedBackupChecksum -PathType Leaf)) {
    throw "Backup checksum file not found: $resolvedBackupChecksum"
}

$actualSqlSha256 = ""
if ($SqlDumpPath -ne "") {
    $actualSqlSha256 = (Get-FileHash -LiteralPath $resolvedSqlDump -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($SqlChecksumPath -ne "") {
        $ExpectedSqlSha256 = Read-Sha256 $resolvedSqlChecksum
    }
    if ($ExpectedSqlSha256 -ne "" -and $actualSqlSha256 -ne $ExpectedSqlSha256.ToLowerInvariant()) {
        throw "SQL dump checksum mismatch. expected=$ExpectedSqlSha256 actual=$actualSqlSha256"
    }
}

$actualBackupSha256 = ""
if ($BackupArtifactPath -ne "") {
    $actualBackupSha256 = (Get-FileHash -LiteralPath $resolvedBackupArtifact -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($BackupChecksumPath -ne "") {
        $ExpectedBackupSha256 = Read-Sha256 $resolvedBackupChecksum
    }
    if ($ExpectedBackupSha256 -ne "" -and $actualBackupSha256 -ne $ExpectedBackupSha256.ToLowerInvariant()) {
        throw "Backup artifact checksum mismatch. expected=$ExpectedBackupSha256 actual=$actualBackupSha256"
    }
}

$mode = if ($Apply.IsPresent) { "APPLY" } else { "DRY-RUN" }
$generatedAt = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
$statusPath = Join-Path $WorkDir "RESTORE_STATUS.md"
$status = @(
    "# Mongoyia Test Server Restore Status",
    "",
    "- Generated at: $generatedAt",
    "- Project root: $Root",
    "- Mode: $mode",
    "- SQL dump: $resolvedSqlDump",
    "- SQL checksum: $resolvedSqlChecksum",
    "- SQL SHA256: $actualSqlSha256",
    "- Database: $Database",
    "- Delivery archive: $resolvedDelivery",
    "- Base URL: $BaseUrl",
    "- IM URL: $ImUrl",
    "- Allow production domain override: $allowProductionDomain",
    "- Backup artifact: $resolvedBackupArtifact",
    "- Backup reference: $BackupReference",
    "- Backup SHA256: $actualBackupSha256",
    ""
)

Write-Output "Mongoyia test-server restore orchestration"
Write-Output "Mode: $mode"
Write-Output "Status report: $statusPath"

if ($Apply.IsPresent -and !$SkipApplySafety.IsPresent) {
    $safetyFailures = @()
    if (!$BackupConfirmed.IsPresent) {
        $safetyFailures += "BackupConfirmed is required before apply mode."
    }
    if ($BackupArtifactPath -eq "" -and $BackupReference -eq "") {
        $safetyFailures += "BackupArtifactPath or BackupReference is required before apply mode."
    }
    if ($ApplyConfirm -ne "RESTORE_OUTER_TEST_SERVER") {
        $safetyFailures += "ApplyConfirm must equal RESTORE_OUTER_TEST_SERVER."
    }
    if ($SqlDumpPath -eq "") {
        $safetyFailures += "SqlDumpPath is required before apply mode."
    }
    if ($SqlChecksumPath -eq "") {
        $safetyFailures += "SqlChecksumPath is required before apply mode."
    }
    if ($DeliveryArchivePath -eq "") {
        $safetyFailures += "DeliveryArchivePath is required before apply mode."
    }
    if ($BaseUrl -eq "") {
        $safetyFailures += "BaseUrl is required before apply mode."
    } elseif (!$BaseUrl.StartsWith("https://")) {
        $safetyFailures += "BaseUrl must use https:// before apply mode."
    } elseif ($BaseUrl -match "localhost|127\.0\.0\.1|0\.0\.0\.0") {
        $safetyFailures += "BaseUrl must not point to a local-only host before apply mode."
    }
    if ($ImUrl -eq "") {
        $safetyFailures += "ImUrl is required before apply mode."
    } elseif (!$ImUrl.StartsWith("wss://")) {
        $safetyFailures += "ImUrl must use wss:// before apply mode."
    } elseif ($ImUrl -match "localhost|127\.0\.0\.1|0\.0\.0\.0") {
        $safetyFailures += "ImUrl must not point to a local-only host before apply mode."
    }
    if (!$RunMigrate.IsPresent) {
        $safetyFailures += "RunMigrate is required before apply mode."
    }
    if (!$RunPreflight.IsPresent) {
        $safetyFailures += "RunPreflight is required before apply mode."
    }
    if (!$RunReceiver.IsPresent) {
        $safetyFailures += "RunReceiver is required before apply mode."
    }
    if ($SkipInputGate.IsPresent) {
        $safetyFailures += "SkipInputGate is not allowed before apply mode unless SkipApplySafety is also used."
    }
    if ($Database -eq "" -or $Database.ToLowerInvariant() -in @("mysql", "information_schema", "performance_schema", "sys")) {
        $safetyFailures += "Refusing to restore into protected or empty database name: $Database"
    }
    foreach ($item in @(@("BaseUrl", $BaseUrl), @("ImUrl", $ImUrl))) {
        $domainHost = Production-Domain-Host $item[1]
        if ($domainHost -ne "" -and !$allowProductionDomain) {
            $safetyFailures += "$($item[0]) points to production domain $domainHost. Use a test domain, or pass -AllowProductionDomainForTest only for an intentional exception."
        }
    }
    $baseHost = Host-From-Url $BaseUrl
    $imHost = Host-From-Url $ImUrl
    if ($baseHost -ne "" -and $imHost -ne "" -and $baseHost -ne $imHost) {
        $safetyFailures += "BaseUrl and ImUrl hosts must match before apply mode."
    }

    $status += "## Apply safety gate"
    $status += ""
    if ($safetyFailures.Count -gt 0) {
        $status += "- Status: FAIL"
        foreach ($failure in $safetyFailures) {
            $status += "- FAIL: $failure"
        }
        $status | Set-Content -LiteralPath $statusPath -Encoding UTF8
        throw "Apply safety gate failed: $($safetyFailures -join '; ')"
    }
    $status += "- Status: PASS"
    $status += "- Backup confirmation: present"
    if ($BackupArtifactPath -ne "") {
        $status += "- Backup artifact: $resolvedBackupArtifact"
        $status += "- Backup SHA256: $actualBackupSha256"
    }
    if ($BackupReference -ne "") {
        $status += "- Backup reference: $BackupReference"
    }
    $status += "- Apply confirmation phrase: matched"
    $status += "- Target database: $Database"
    $status += "- Required follow-up steps: receiver validation, migrate, and strict preflight"
    $status += ""
} elseif ($Apply.IsPresent) {
    $status += "## Apply safety gate"
    $status += ""
    if ($SkipApplySafetyConfirm -ne "SKIP_RESTORE_APPLY_SAFETY") {
        $status += "- Status: FAIL"
        $status += "- FAIL: SkipApplySafetyConfirm must equal SKIP_RESTORE_APPLY_SAFETY when -SkipApplySafety is used."
        $status | Set-Content -LiteralPath $statusPath -Encoding UTF8
        throw "SkipApplySafetyConfirm must equal SKIP_RESTORE_APPLY_SAFETY when -SkipApplySafety is used."
    }
    $status += "- Status: SKIPPED by -SkipApplySafety"
    $status += "- SkipApplySafetyConfirm: matched"
    $status += ""
} else {
    $status += "## Apply safety gate"
    $status += ""
    $status += "- Status: DRY-RUN"
    $status += "- Apply mode requires -BackupConfirmed, -BackupArtifactPath or -BackupReference, -ApplyConfirm RESTORE_OUTER_TEST_SERVER, -DeliveryArchivePath, -SqlDumpPath, -SqlChecksumPath, -RunReceiver, -RunMigrate, -RunPreflight, BaseUrl, ImUrl, and input gate unless -SkipApplySafety is passed."
    $status += ""
}

if ($Apply.IsPresent -and !$SkipInputGate.IsPresent) {
    $status += "## Test-server input gate"
    $status += ""
    $status += "- Command: ``mongoyia-test-server-input-gate.ps1``"
    Run-Step "test-server input gate" {
        $gateArgs = @(
            "-PhpEnv", $PhpEnv,
            "-ImEnv", $ImEnv,
            "-BaseUrl", $BaseUrl,
            "-ImUrl", $ImUrl,
            "-DeliveryArchivePath", $resolvedDelivery,
            "-SqlDumpPath", $resolvedSqlDump,
            "-SqlChecksumPath", $resolvedSqlChecksum,
            "-ExpectedSqlSha256", $actualSqlSha256,
            "-Database", $Database,
            "-BackupReference", $BackupReference,
            "-BackupArtifactPath", $resolvedBackupArtifact,
            "-BackupChecksumPath", $resolvedBackupChecksum,
            "-ExpectedBackupSha256", $actualBackupSha256,
            "-Profile", "test",
            "-RequireRestoreInputs",
            "-OutputPath", (Join-Path $WorkDir "INPUT_GATE.md")
        )
        if ($AllowProductionDomainForTest.IsPresent) { $gateArgs += "-AllowProductionDomainForTest" }
        & "$PSScriptRoot\mongoyia-test-server-input-gate.ps1" @gateArgs
    }
    $status += "- Status: PASS"
    $status += ""
} elseif ($Apply.IsPresent) {
    $status += "## Test-server input gate"
    $status += ""
    $status += "- Status: SKIPPED by -SkipInputGate"
    $status += ""
} else {
    $status += "## Test-server input gate"
    $status += ""
    $status += "- Status: DRY-RUN"
    $status += "- Apply mode will run this gate before database restore unless -SkipInputGate is passed."
    $status += ""
}

if ($DeliveryArchivePath -ne "" -and ($RunReceiver.IsPresent -or (-not $Apply.IsPresent))) {
    $receiverCommand = ".\console\shell\mongoyia-test-server-receiver.ps1 -DeliveryArchivePath '$resolvedDelivery'"
    $status += "## Receiver validation"
    $status += ""
    $status += "- Command: ``$receiverCommand``"
    if ($Apply.IsPresent -and $RunReceiver.IsPresent) {
        Run-Step "receiver validation" {
            $argsList = @("-DeliveryArchivePath", $resolvedDelivery, "-BaseUrl", $BaseUrl)
            if ($SkipApi.IsPresent) { $argsList += "-SkipApi" }
            if ($SkipConnectivity.IsPresent) { $argsList += "-SkipConnectivity" }
            & "$PSScriptRoot\mongoyia-test-server-receiver.ps1" @argsList
        }
        $status += "- Status: PASS"
    } else {
        Write-Output "DRY-RUN receiver validation: $receiverCommand"
        $status += "- Status: DRY-RUN"
    }
    $status += ""
}

if ($Apply.IsPresent -and !$SkipApplySafety.IsPresent) {
    $status += "## Go/no-go checklist"
    $status += ""
    $status += "- Command: ``mongoyia-test-server-go-no-go.ps1``"
    Run-Step "go/no-go checklist" {
        $goNoGoArgs = @(
            "-OutputPath", (Join-Path $WorkDir "GO_NO_GO.md"),
            "-InputGatePath", (Join-Path $WorkDir "INPUT_GATE.md")
        )
        if ($ExternalInputsConfirmed.IsPresent) {
            $goNoGoArgs += "-ExternalInputsConfirmed"
            $goNoGoArgs += @("-ExternalInputsConfirm", $ExternalInputsConfirm)
        }
        & "$PSScriptRoot\mongoyia-test-server-go-no-go.ps1" @goNoGoArgs
    }
    $status += "- Status: PASS"
    $status += "- Report: $(Join-Path $WorkDir "GO_NO_GO.md")"
    $status += ""
} elseif ($Apply.IsPresent) {
    $status += "## Go/no-go checklist"
    $status += ""
    $status += "- Status: SKIPPED by -SkipApplySafety"
    $status += ""
} else {
    $status += "## Go/no-go checklist"
    $status += ""
    $status += "- Status: DRY-RUN"
    $status += "- Apply mode runs this checklist after input gate and receiver validation, before database restore."
    $status += ""
}

if ($SqlDumpPath -ne "") {
    $mysqlArgs = Mysql-Args
    $maskedArgs = Mask-Mysql-Args $mysqlArgs
    $restoreCommand = "$Mysql $($maskedArgs -join ' ') < $resolvedSqlDump"
    $status += "## Database restore"
    $status += ""
    $status += "- SHA256: $actualSqlSha256"
    $status += "- Command: ``$restoreCommand``"
    if ($Apply.IsPresent) {
        Run-Step "database restore" {
            $quotedArgs = ($mysqlArgs | ForEach-Object { Quote-Cmd $_ }) -join ' '
            $cmd = "$(Quote-Cmd $Mysql) $quotedArgs < $(Quote-Cmd $resolvedSqlDump)"
            Write-Output "$Mysql $($maskedArgs -join ' ') < $resolvedSqlDump"
            & cmd.exe /d /c $cmd
        }
        $status += "- Status: PASS"
    } else {
        Write-Output "DRY-RUN database restore: $restoreCommand"
        $status += "- Status: DRY-RUN"
    }
    $status += ""
}

if ($RunMigrate.IsPresent) {
    $status += "## Migrations"
    $status += ""
    $status += "- Command: ``$Php yii migrate/up --interactive=0``"
    if ($Apply.IsPresent) {
        Run-Step "migrations" {
            & $Php yii migrate/up --interactive=0
        }
        $status += "- Status: PASS"
    } else {
        Write-Output "DRY-RUN migrations: $Php yii migrate/up --interactive=0"
        $status += "- Status: DRY-RUN"
    }
    $status += ""
}

if ($RunPreflight.IsPresent) {
    $status += "## Strict preflight"
    $status += ""
    $status += "- Command: ``mongoyia-test-server-preflight-report.ps1``"
    if ($Apply.IsPresent) {
        Run-Step "strict preflight" {
            $argsList = @("-BaseUrl", $BaseUrl, "-Profile", "test", "-Strict", "1", "-Php", $Php)
            if ($SkipApi.IsPresent) { $argsList += "-SkipApi" }
            if ($SkipConnectivity.IsPresent) { $argsList += "-SkipConnectivity" }
            & "$PSScriptRoot\mongoyia-test-server-preflight-report.ps1" @argsList
        }
        $status += "- Status: PASS"
    } else {
        Write-Output "DRY-RUN strict preflight: .\console\shell\mongoyia-test-server-preflight-report.ps1 -BaseUrl '$BaseUrl' -Profile test -Strict 1"
        $status += "- Status: DRY-RUN"
    }
    $status += ""
}

if ($RunAcceptance.IsPresent) {
    if ($ImUrl -eq "") {
        throw "RunAcceptance requires -ImUrl."
    }
    $status += "## Full acceptance"
    $status += ""
    $status += "- Command: ``mongoyia-acceptance.ps1``"
    if ($Apply.IsPresent) {
        Run-Step "full acceptance" {
            $argsList = @(
                "-BaseUrl", $BaseUrl,
                "-Profile", "test",
                "-Strict",
                "-ImUrl", $ImUrl,
                "-Php", $Php,
                "-Python", $Python
            )
            if ($CleanupAfterRun.IsPresent) { $argsList += "-CleanupAfterRun" }
            & "$PSScriptRoot\mongoyia-acceptance.ps1" @argsList
        }
        $status += "- Status: PASS"
    } else {
        Write-Output "DRY-RUN full acceptance: .\console\shell\mongoyia-acceptance.ps1 -BaseUrl '$BaseUrl' -Profile test -Strict -ImUrl '$ImUrl'"
        $status += "- Status: DRY-RUN"
    }
    $status += ""
}

if (!$Apply.IsPresent) {
    $status += "## Apply note"
    $status += ""
    $status += "This was a dry-run. Add ``-Apply`` to execute the selected steps."
}

$status | Set-Content -LiteralPath $statusPath -Encoding UTF8
Write-Output ""
Write-Output "Restore orchestration status: $statusPath"
if (!$Apply.IsPresent) {
    Write-Output "Dry-run only. Add -Apply to execute selected steps."
}
