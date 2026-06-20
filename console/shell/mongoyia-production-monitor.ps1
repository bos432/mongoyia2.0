param(
    [string]$OutputPath = "",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [int]$DiskWarnPercent = 85,
    [int]$DiskFailPercent = 95,
    [switch]$SkipImPort
)

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-production-monitor-$stamp.md"
}
$outputFull = if ([System.IO.Path]::IsPathRooted($OutputPath)) { $OutputPath } else { Join-Path $Root $OutputPath }
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) { New-Item -ItemType Directory -Path $outputDir -Force | Out-Null }

function Resolve-ProjectPath {
    param([string]$Path)
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Read-EnvValue {
    param([string]$Path, [string]$Key)
    $full = Resolve-ProjectPath $Path
    if (!(Test-Path -LiteralPath $full -PathType Leaf)) { return "" }
    $line = Get-Content -LiteralPath $full | Where-Object { $_ -match "^\s*$([regex]::Escape($Key))=" } | Select-Object -First 1
    if ($null -eq $line) { return "" }
    return ($line -replace "^\s*$([regex]::Escape($Key))=", "").Trim().Trim('"').Trim("'")
}

function Add-Row {
    param([string]$Area, [string]$Check, [string]$Status, [string]$Evidence, [string]$Action)
    $script:rows += "| $Area | $Check | $Status | $Evidence | $Action |"
    if ($Status -eq "FAIL") { $script:failures++ }
    if ($Status -eq "WARN") { $script:warnings++ }
}

$script:rows = @()
$script:failures = 0
$script:warnings = 0

$global:LASTEXITCODE = 0
$phpOutput = & php -v 2>&1
$phpCode = $LASTEXITCODE
$phpVersion = $phpOutput | Select-Object -First 1
if ($phpCode -eq 0) {
    Add-Row "Runtime" "PHP CLI" "PASS" $phpVersion "Keep PHP CLI available for console health and maintenance commands."
} else {
    Add-Row "Runtime" "PHP CLI" "FAIL" ($phpVersion -join " ") "Install/fix PHP CLI."
}

$dbDsn = Read-EnvValue $PhpEnv "DB_DSN"
$dbUser = Read-EnvValue $PhpEnv "DB_USERNAME"
if ($dbDsn -ne "" -and $dbUser -ne "") {
    Add-Row "Config" "PHP database env present" "PASS" "DB_DSN and DB_USERNAME exist" "Run deploy-check for credential validation."
} else {
    Add-Row "Config" "PHP database env present" "FAIL" "Missing DB_DSN or DB_USERNAME" "Provision real .env."
}

$redisHost = Read-EnvValue $PhpEnv "REDIS_HOST"
$redisPort = Read-EnvValue $PhpEnv "REDIS_PORT"
if ($redisHost -ne "" -and $redisPort -ne "") {
    $socket = Test-NetConnection -ComputerName $redisHost -Port ([int]$redisPort) -WarningAction SilentlyContinue
    if ($socket.TcpTestSucceeded) {
        Add-Row "Connectivity" "Redis port" "PASS" "$redisHost`:$redisPort reachable" "Monitor latency and memory in production."
    } else {
        Add-Row "Connectivity" "Redis port" "WARN" "$redisHost`:$redisPort not reachable from this host" "Start Redis or verify network/security group."
    }
} else {
    Add-Row "Connectivity" "Redis port" "WARN" "REDIS_HOST/REDIS_PORT missing" "Provision Redis env."
}

if (!$SkipImPort.IsPresent) {
    $imHost = Read-EnvValue $ImEnv "IM_HOST"
    $imPort = Read-EnvValue $ImEnv "IM_PORT"
    if ($imHost -eq "0.0.0.0") { $imHost = "127.0.0.1" }
    if ($imHost -ne "" -and $imPort -ne "") {
        $socket = Test-NetConnection -ComputerName $imHost -Port ([int]$imPort) -WarningAction SilentlyContinue
        if ($socket.TcpTestSucceeded) {
            Add-Row "Connectivity" "Python IM port" "PASS" "$imHost`:$imPort reachable" "Also run IM WSS healthcheck through the real domain."
        } else {
            Add-Row "Connectivity" "Python IM port" "WARN" "$imHost`:$imPort not reachable" "Start IM process or verify supervisor/systemd."
        }
    } else {
        Add-Row "Connectivity" "Python IM port" "WARN" "IM_HOST/IM_PORT missing" "Provision Python IM .env."
    }
}

$drive = Get-PSDrive -PSProvider FileSystem | Where-Object { $Root.StartsWith($_.Root, [System.StringComparison]::OrdinalIgnoreCase) } | Sort-Object { $_.Root.Length } -Descending | Select-Object -First 1
if ($null -ne $drive -and ($drive.Used + $drive.Free) -gt 0) {
    $usedPercent = [math]::Round(($drive.Used / ($drive.Used + $drive.Free)) * 100, 2)
    if ($usedPercent -ge $DiskFailPercent) {
        Add-Row "Capacity" "Project disk usage" "FAIL" "$usedPercent% used on $($drive.Root)" "Free disk space before uploads/logs/backups fill the volume."
    } elseif ($usedPercent -ge $DiskWarnPercent) {
        Add-Row "Capacity" "Project disk usage" "WARN" "$usedPercent% used on $($drive.Root)" "Plan cleanup or volume expansion."
    } else {
        Add-Row "Capacity" "Project disk usage" "PASS" "$usedPercent% used on $($drive.Root)" "Keep daily disk alerts enabled."
    }
}

foreach ($path in @("runtime", "frontend/runtime", "web/assets", "web/attachment")) {
    if (Test-Path -LiteralPath (Join-Path $Root $path)) {
        Add-Row "Filesystem" $path "PASS" "exists" "Keep writable by the PHP runtime user."
    } else {
        Add-Row "Filesystem" $path "WARN" "missing" "Create before production traffic."
    }
}

$logFiles = Get-ChildItem -Path (Join-Path $Root "frontend/runtime/logs"), (Join-Path $Root "console/runtime/logs") -File -Recurse -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 5
if ($logFiles) {
    Add-Row "Logs" "Recent runtime logs" "PASS" (($logFiles | ForEach-Object { $_.Name }) -join ", ") "Feed PHP and IM logs into alerting."
} else {
    Add-Row "Logs" "Recent runtime logs" "WARN" "No runtime log files found" "Verify log path and rotation."
}

$result = if ($failures -gt 0) { "FAIL" } elseif ($warnings -gt 0) { "WARN" } else { "PASS" }
@(
    "# Mongoyia Production Monitor",
    "",
    "- Result: $result",
    "- Failures: $failures",
    "- Warnings: $warnings",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "",
    "| Area | Check | Status | Evidence | Action |",
    "|---|---|---:|---|---|"
) + $rows | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Mongoyia production monitor report: $outputFull"
Write-Output "Result: $result"
if ($failures -gt 0) { exit 1 }
