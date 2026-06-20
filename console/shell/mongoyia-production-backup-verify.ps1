param(
    [string]$BackupArchive = "",
    [string]$BackupDir = "runtime/backups",
    [string]$UploadArchive = "",
    [string]$ReportPath = "",
    [switch]$RequireUploads
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

function Add-Check {
    param([string]$Name, [bool]$Pass, [string]$Detail)
    $script:Checks += [pscustomobject]@{
        Name = $Name
        Pass = $Pass
        Detail = $Detail
    }
}

function Latest-BackupArchive {
    $dir = Resolve-ProjectPath $BackupDir
    if (!(Test-Path -LiteralPath $dir -PathType Container)) {
        return ""
    }

    $zip = Get-ChildItem -LiteralPath $dir -File -Filter "mongoyia-*.sql.zip" -ErrorAction SilentlyContinue
    $gz = Get-ChildItem -LiteralPath $dir -File -Filter "mongoyia-*.sql.gz" -ErrorAction SilentlyContinue
    $file = @($zip + $gz) | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Verify-Checksum {
    param([string]$Archive)
    $sidecar = "$Archive.sha256"
    if (!(Test-Path -LiteralPath $sidecar -PathType Leaf)) {
        Add-Check "checksum sidecar" $false "Missing $sidecar"
        return
    }

    $line = (Get-Content -LiteralPath $sidecar | Select-Object -First 1)
    $expected = (($line -split "\s+")[0]).ToLowerInvariant()
    if ($expected -notmatch "^[0-9a-f]{64}$") {
        Add-Check "checksum sidecar" $false "Invalid checksum line in $sidecar"
        return
    }

    $actual = (Get-FileHash -LiteralPath $Archive -Algorithm SHA256).Hash.ToLowerInvariant()
    Add-Check "checksum match" ($actual -eq $expected) "expected=$expected actual=$actual"
}

function Verify-ZipArchive {
    param([string]$Archive, [bool]$RequireSql)
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $zip = [System.IO.Compression.ZipFile]::OpenRead($Archive)
    try {
        $entries = @($zip.Entries | Where-Object { $_.Length -gt 0 })
        Add-Check "zip readable" ($entries.Count -gt 0) "entries=$($entries.Count)"
        if ($RequireSql) {
            $sql = @($entries | Where-Object { $_.FullName -like "*.sql" })
            Add-Check "sql entry exists" ($sql.Count -gt 0) "sql_entries=$($sql.Count)"
        }
    } finally {
        $zip.Dispose()
    }
}

function Verify-GzipReadable {
    param([string]$Archive)
    $file = [System.IO.File]::OpenRead($Archive)
    try {
        $gzip = New-Object System.IO.Compression.GZipStream($file, [System.IO.Compression.CompressionMode]::Decompress)
        try {
            $buffer = New-Object byte[] 1024
            $read = $gzip.Read($buffer, 0, $buffer.Length)
            Add-Check "gzip readable" ($read -gt 0) "first_read_bytes=$read"
        } finally {
            $gzip.Dispose()
        }
    } finally {
        $file.Dispose()
    }
}

function Verify-ArchiveReadable {
    param([string]$Archive, [bool]$RequireSql)
    if ($Archive -like "*.zip") {
        Verify-ZipArchive $Archive $RequireSql
        return
    }

    if ($Archive -like "*.gz") {
        Verify-GzipReadable $Archive
        return
    }

    Add-Check "archive type" $false "Unsupported archive type: $Archive"
}

$Checks = @()
$BackupArchive = Resolve-ProjectPath $BackupArchive
if ($BackupArchive -eq "") {
    $BackupArchive = Latest-BackupArchive
}

if ($ReportPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $ReportPath = "runtime/handover/mongoyia-production-backup-verify-$stamp.md"
}
$ReportPath = Resolve-ProjectPath $ReportPath
$reportDir = Split-Path -Parent $ReportPath
if (!(Test-Path -LiteralPath $reportDir -PathType Container)) {
    New-Item -ItemType Directory -Path $reportDir -Force | Out-Null
}

if ($BackupArchive -eq "" -or !(Test-Path -LiteralPath $BackupArchive -PathType Leaf)) {
    Add-Check "database backup archive" $false "Backup archive not found. Pass -BackupArchive or create one first."
} else {
    Add-Check "database backup archive" $true $BackupArchive
    Verify-Checksum $BackupArchive
    Verify-ArchiveReadable $BackupArchive $true
}

$UploadArchive = Resolve-ProjectPath $UploadArchive
if ($UploadArchive -eq "") {
    Add-Check "upload archive" (!$RequireUploads.IsPresent) "Upload archive not provided."
} elseif (!(Test-Path -LiteralPath $UploadArchive -PathType Leaf)) {
    Add-Check "upload archive" $false "Missing $UploadArchive"
} else {
    Add-Check "upload archive" $true $UploadArchive
    Verify-Checksum $UploadArchive
    Verify-ArchiveReadable $UploadArchive $false
}

$failures = @($Checks | Where-Object { -not $_.Pass }).Count
$status = if ($failures -eq 0) { "PASS" } else { "FAIL" }

$lines = @(
    "# Mongoyia Production Backup Verify",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Status: $status",
    "- Database backup archive: $BackupArchive",
    "- Upload archive: $UploadArchive",
    "",
    "## Checks",
    ""
)
foreach ($check in $Checks) {
    $mark = if ($check.Pass) { "PASS" } else { "FAIL" }
    $lines += "- $mark $($check.Name): $($check.Detail)"
}

$lines | Set-Content -LiteralPath $ReportPath -Encoding UTF8

Write-Output "Mongoyia production backup verify: $status"
Write-Output "Report: $ReportPath"
exit $(if ($failures -eq 0) { 0 } else { 1 })
