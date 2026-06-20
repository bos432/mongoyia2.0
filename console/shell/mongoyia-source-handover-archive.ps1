param(
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [string]$PatchPath = "",
    [string]$UntrackedBundlePath = "",
    [string]$InventoryPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

$OutputRoot = Join-Path $Root $OutputDir
$Stage = Join-Path $OutputRoot "mongoyia-source-handover-$Stamp"
$ArchivePath = Join-Path $OutputRoot "mongoyia-source-handover-$Stamp.zip"
$HashPath = "$ArchivePath.sha256"

function Latest-File {
    param([string]$Pattern)
    $file = Get-ChildItem -Path $OutputRoot -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        throw "No file found for pattern $Pattern under $OutputRoot"
    }
    return $file.FullName
}

function Source-Path {
    param([string]$Path)
    if ([System.IO.Path]::IsPathRooted($Path)) {
        return $Path
    }
    return (Join-Path $Root $Path)
}

function Copy-ToStage {
    param([string]$Source, [string]$Folder)
    if (!(Test-Path -LiteralPath $Source -PathType Leaf)) {
        throw "Missing source handover artifact: $Source"
    }
    $destDir = Join-Path $Stage $Folder
    if (!(Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $Source -Destination (Join-Path $destDir (Split-Path -Leaf $Source)) -Force
}

New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
if ($PatchPath -eq "") { $PatchPath = Latest-File "mongoyia-source-tracked-diff-*.patch" } else { $PatchPath = Source-Path $PatchPath }
if ($UntrackedBundlePath -eq "") { $UntrackedBundlePath = Latest-File "mongoyia-untracked-source-*.zip" } else { $UntrackedBundlePath = Source-Path $UntrackedBundlePath }
if ($InventoryPath -eq "") { $InventoryPath = Latest-File "mongoyia-worktree-inventory-*.md" } else { $InventoryPath = Source-Path $InventoryPath }

$PatchHashPath = "$PatchPath.sha256"
$UntrackedHashPath = "$UntrackedBundlePath.sha256"
$PatchReportPath = Join-Path (Split-Path -Parent $PatchPath) ((Split-Path -Leaf $PatchPath) -replace '^mongoyia-source-tracked-diff-', 'mongoyia-source-diff-export-' -replace '\.patch$', '.md')
$UntrackedReportPath = Join-Path (Split-Path -Parent $UntrackedBundlePath) ((Split-Path -Leaf $UntrackedBundlePath) -replace '^mongoyia-untracked-source-', 'mongoyia-untracked-source-export-' -replace '\.zip$', '.md')

if (Test-Path -LiteralPath $Stage) {
    $resolvedStage = (Resolve-Path -LiteralPath $Stage).Path
    $resolvedOutput = (Resolve-Path -LiteralPath $OutputRoot).Path
    if (!$resolvedStage.StartsWith($resolvedOutput)) {
        throw "Refusing to remove unexpected stage path: $resolvedStage"
    }
    Remove-Item -LiteralPath $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

Copy-ToStage $PatchPath "tracked"
Copy-ToStage $PatchHashPath "tracked"
Copy-ToStage $UntrackedBundlePath "untracked"
Copy-ToStage $UntrackedHashPath "untracked"
Copy-ToStage $PatchReportPath "reports"
Copy-ToStage $UntrackedReportPath "reports"
Copy-ToStage $InventoryPath "reports"

$manifest = @(
    "# Mongoyia Source Handover Manifest",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Tracked patch: tracked/$(Split-Path -Leaf $PatchPath)",
    "- Untracked source bundle: untracked/$(Split-Path -Leaf $UntrackedBundlePath)",
    "- Worktree inventory: reports/$(Split-Path -Leaf $InventoryPath)",
    "",
    "## Use Order",
    "",
    "1. Start from the same source baseline used for this handover.",
    "2. Apply the tracked patch from 'tracked/'.",
    "3. Copy the reviewed untracked files from the archive in 'untracked/'.",
    "4. Run package, security, cleanup, and acceptance checks before test-server signoff.",
    "",
    "## Boundary",
    "",
    "This source handover archive intentionally contains patch files, reviewed untracked source bundle, checksums, and reports only. It does not contain database dumps, real .env, uploads, vendor dependencies, generated runtime output, or generated web assets."
)
$manifest | Set-Content -LiteralPath (Join-Path $Stage "MANIFEST.md") -Encoding UTF8

if (Test-Path -LiteralPath $ArchivePath) { Remove-Item -LiteralPath $ArchivePath -Force }
if (Test-Path -LiteralPath $HashPath) { Remove-Item -LiteralPath $HashPath -Force }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($ArchivePath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $Stage -Recurse -File -Force | ForEach-Object {
        $relative = $_.FullName.Substring($Stage.Length).TrimStart("\", "/").Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $relative) | Out-Null
    }
} finally {
    $zip.Dispose()
}

$archiveHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
"$archiveHash  $(Split-Path -Leaf $ArchivePath)" | Set-Content -LiteralPath $HashPath -Encoding ASCII

Write-Output "Source handover folder: $Stage"
Write-Output "Source handover archive: $ArchivePath"
Write-Output "Source handover checksum: $HashPath"
