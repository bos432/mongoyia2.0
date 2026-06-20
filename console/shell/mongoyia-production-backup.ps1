param(
    [string]$EnvPath = ".env",
    [string]$OutputDir = "runtime/backups",
    [string]$Database = "",
    [string]$DumpBin = "",
    [int]$KeepDays = 14,
    [switch]$IncludeUploads,
    [string]$UploadDir = "web/attachment"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Resolve-ProjectPath {
    param([string]$Path)
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Read-EnvValue {
    param([string]$Path, [string]$Key)
    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { return "" }
    $line = Get-Content -LiteralPath $Path | Where-Object { $_ -match "^\s*$([regex]::Escape($Key))=" } | Select-Object -First 1
    if ($null -eq $line) { return "" }
    return ($line -replace "^\s*$([regex]::Escape($Key))=", "").Trim().Trim('"').Trim("'")
}

function Parse-DsnPart {
    param([string]$Dsn, [string]$Key)
    $match = [regex]::Match($Dsn, "(^|;)$([regex]::Escape($Key))=([^;]+)")
    if ($match.Success) { return $match.Groups[2].Value }
    return ""
}

function Find-DumpBin {
    if ($DumpBin -ne "") { return $DumpBin }
    foreach ($candidate in @("mariadb-dump.exe", "mysqldump.exe", "mariadb-dump", "mysqldump")) {
        $cmd = Get-Command $candidate -ErrorAction SilentlyContinue
        if ($null -ne $cmd) { return $cmd.Source }
    }
    foreach ($candidate in @(
        "C:\Program Files\MariaDB 12.3\bin\mariadb-dump.exe",
        "C:\Program Files\MariaDB 12.3\bin\mysqldump.exe"
    )) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }
    throw "mariadb-dump/mysqldump not found. Pass -DumpBin explicitly."
}

$envFull = Resolve-ProjectPath $EnvPath
$dsn = Read-EnvValue $envFull "DB_DSN"
$dbHost = Read-EnvValue $envFull "DB_HOST"
$dbPort = Read-EnvValue $envFull "DB_PORT"
$dbName = if ($Database -ne "") { $Database } else { Read-EnvValue $envFull "DB_DATABASE" }
if ($dbHost -eq "") { $dbHost = Parse-DsnPart $dsn "host" }
if ($dbPort -eq "") { $dbPort = Parse-DsnPart $dsn "port" }
if ($dbName -eq "") { $dbName = Parse-DsnPart $dsn "dbname" }
if ($dbHost -eq "") { $dbHost = "127.0.0.1" }
if ($dbPort -eq "") { $dbPort = "3306" }
if ($dbName -eq "") { throw "Database name is empty. Set DB_DSN/DB_DATABASE or pass -Database." }

$dbUser = Read-EnvValue $envFull "DB_USERNAME"
$dbPass = Read-EnvValue $envFull "DB_PASSWORD"
if ($dbUser -eq "") { throw "DB_USERNAME is empty in $EnvPath." }

$outFull = Resolve-ProjectPath $OutputDir
New-Item -ItemType Directory -Path $outFull -Force | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$base = "mongoyia-$dbName-$stamp"
$sqlPath = Join-Path $outFull "$base.sql"
$zipPath = Join-Path $outFull "$base.sql.zip"
$manifestPath = Join-Path $outFull "$base.md"

$dump = Find-DumpBin
$env:MYSQL_PWD = $dbPass
try {
    & $dump --host=$dbHost --port=$dbPort --user=$dbUser --single-transaction --routines --triggers --events $dbName | Set-Content -LiteralPath $sqlPath -Encoding UTF8
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}

if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
Compress-Archive -LiteralPath $sqlPath -DestinationPath $zipPath -Force
Remove-Item -LiteralPath $sqlPath -Force

$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
"$hash  $(Split-Path -Leaf $zipPath)" | Set-Content -LiteralPath "$zipPath.sha256" -Encoding ASCII

$uploadArchive = ""
if ($IncludeUploads.IsPresent) {
    $uploadFull = Resolve-ProjectPath $UploadDir
    if (Test-Path -LiteralPath $uploadFull) {
        $uploadArchive = Join-Path $outFull "$base-uploads.zip"
        Compress-Archive -LiteralPath $uploadFull -DestinationPath $uploadArchive -Force
        $uploadHash = (Get-FileHash -LiteralPath $uploadArchive -Algorithm SHA256).Hash.ToLowerInvariant()
        "$uploadHash  $(Split-Path -Leaf $uploadArchive)" | Set-Content -LiteralPath "$uploadArchive.sha256" -Encoding ASCII
    }
}

Get-ChildItem -LiteralPath $outFull -File -Filter "mongoyia-*.sql.zip" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-1 * $KeepDays) } |
    Remove-Item -Force

@(
    "# Mongoyia Production Backup",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Database: $dbName",
    "- Host: $dbHost",
    "- Port: $dbPort",
    "- Dump: $zipPath",
    "- Dump SHA256: $hash",
    "- Upload archive: $uploadArchive",
    "- Keep days: $KeepDays",
    "",
    "This file intentionally omits database passwords and API secrets."
) | Set-Content -LiteralPath $manifestPath -Encoding UTF8

Write-Output "Mongoyia production backup: $zipPath"
Write-Output "Checksum: $zipPath.sha256"
Write-Output "Manifest: $manifestPath"
