param(
    [string]$SqlDumpPath,
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [string]$ExpectedSha256 = "",
    [string]$Database = "outer"
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

if ($SqlDumpPath -eq "") {
    throw "SqlDumpPath is required."
}
if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

$resolvedSql = Resolve-ProjectPath $SqlDumpPath
if (!(Test-Path -LiteralPath $resolvedSql -PathType Leaf)) {
    throw "SQL dump not found: $resolvedSql"
}

$outputRoot = Resolve-ProjectPath $OutputDir
New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null

$file = Get-Item -LiteralPath $resolvedSql
$hash = (Get-FileHash -LiteralPath $resolvedSql -Algorithm SHA256).Hash.ToLowerInvariant()
if ($ExpectedSha256 -ne "" -and $hash -ne $ExpectedSha256.ToLowerInvariant()) {
    throw "SQL dump checksum mismatch. expected=$ExpectedSha256 actual=$hash"
}

$createTables = 0
$insertStatements = 0
$databaseMentions = 0
$lineCount = 0
Get-Content -LiteralPath $resolvedSql -ReadCount 1000 | ForEach-Object {
    foreach ($line in $_) {
        $lineCount++
        if ($line -match '^\s*CREATE\s+TABLE\b') { $createTables++ }
        if ($line -match '^\s*INSERT\s+INTO\b') { $insertStatements++ }
        if ($Database -ne "" -and $line -match [regex]::Escape($Database)) { $databaseMentions++ }
    }
}

$base = [System.IO.Path]::GetFileNameWithoutExtension($file.Name)
$manifestPath = Join-Path $outputRoot "mongoyia-sql-dump-manifest-$Stamp.md"
$hashPath = Join-Path $outputRoot "$($file.Name).sha256"
"$hash  $($file.Name)" | Set-Content -LiteralPath $hashPath -Encoding ASCII

$result = if ($createTables -gt 0 -and $insertStatements -gt 0) { "PASS" } else { "WARN" }
$manifest = @(
    "# Mongoyia SQL Dump Manifest",
    "",
    "- Result: $result",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- SQL dump: $resolvedSql",
    "- File name: $($file.Name)",
    "- Size bytes: $($file.Length)",
    "- Last write time: $($file.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss"))",
    "- SHA256: $hash",
    "- Sidecar checksum: $hashPath",
    "- Expected database: $Database",
    "- Line count: $lineCount",
    "- CREATE TABLE statements: $createTables",
    "- INSERT statements: $insertStatements",
    "- Database name mentions: $databaseMentions",
    "",
    "## Receiver Notes",
    "",
    "Copy the SQL dump and this `.sha256` sidecar separately from the code delivery archive.",
    "The code delivery archive intentionally excludes SQL dumps and production data.",
    "Before restore, verify this SHA256 matches the receiver-side SQL file."
)
$manifest | Set-Content -LiteralPath $manifestPath -Encoding UTF8

Write-Output "SQL dump manifest: $manifestPath"
Write-Output "SQL dump checksum: $hashPath"
Write-Output "SHA256: $hash"
Write-Output "Result: $result"
