param(
    [string]$ArchivePath = "",
    [ValidateSet("reverse", "apply", "skip")]
    [string]$PatchMode = "reverse"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Latest-Archive {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter "mongoyia-source-handover-*.zip" -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        throw "No source handover archive found under runtime/handover."
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
    throw "Missing source handover checksum: $ChecksumPath"
}

$expectedHash = ((Get-Content -LiteralPath $ChecksumPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
$actualHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($expectedHash -ne $actualHash) {
    throw "Source handover checksum mismatch. expected=$expectedHash actual=$actualHash"
}

$TempRoot = Join-Path $Root ("runtime/handover/source-handover-verify-{0}-{1}" -f $PID, ([System.Guid]::NewGuid().ToString("N").Substring(0, 8)))
New-Item -ItemType Directory -Path $TempRoot -Force | Out-Null

try {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::ExtractToDirectory($ArchivePath, $TempRoot)

    $entries = @(Get-ChildItem -LiteralPath $TempRoot -Recurse -File -Force | ForEach-Object {
        Normalize-Entry $_.FullName.Substring($TempRoot.Length).TrimStart("\", "/")
    })

$requiredPatterns = @(
    '^MANIFEST\.md$',
    '^tracked/mongoyia-source-tracked-diff-.+\.patch$',
    '^tracked/mongoyia-source-tracked-diff-.+\.patch\.sha256$',
    '^untracked/mongoyia-untracked-source-.+\.zip$',
    '^untracked/mongoyia-untracked-source-.+\.zip\.sha256$',
    '^reports/mongoyia-source-diff-export-.+\.md$',
    '^reports/mongoyia-untracked-source-export-.+\.md$',
    '^reports/mongoyia-worktree-inventory-.+\.md$'
)
foreach ($pattern in $requiredPatterns) {
    if (@($entries | Where-Object { $_ -match $pattern }).Count -lt 1) {
        throw "Missing required source handover entry matching: $pattern"
    }
}

foreach ($entry in $entries) {
    if ($entry -match '(^|/)\.env$') { throw "Forbidden real env file in source handover: $entry" }
    if ($entry -match '(^|/)vendor/') { throw "Forbidden vendor dependency in source handover: $entry" }
    if ($entry -match '(^|/)node_modules/') { throw "Forbidden node_modules dependency in source handover: $entry" }
    if ($entry -match '(^|/)web/attachment/') { throw "Forbidden uploaded attachment in source handover: $entry" }
    if ($entry -match '(^|/)web/assets/') { throw "Forbidden generated web asset in source handover: $entry" }
    if ($entry -match '\.(sql|dump|bak|7z|rar)$') { throw "Forbidden dump payload in source handover: $entry" }
}

$patch = Get-ChildItem -LiteralPath (Join-Path $TempRoot "tracked") -Filter "mongoyia-source-tracked-diff-*.patch" -File | Select-Object -First 1
$patchHash = "$($patch.FullName).sha256"
$patchExpected = ((Get-Content -LiteralPath $patchHash -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
$patchActual = (Get-FileHash -LiteralPath $patch.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
if ($patchExpected -ne $patchActual) {
    throw "Tracked patch checksum mismatch."
}

if ($PatchMode -ne "skip") {
    if ($PatchMode -eq "reverse") {
        & git apply --reverse --check $patch.FullName
    } else {
        & git apply --check $patch.FullName
    }
    if ($LASTEXITCODE -ne 0) {
        throw "git apply $PatchMode check failed for tracked patch."
    }
}

$untrackedBundle = Get-ChildItem -LiteralPath (Join-Path $TempRoot "untracked") -Filter "mongoyia-untracked-source-*.zip" -File | Select-Object -First 1
$untrackedHash = "$($untrackedBundle.FullName).sha256"
$untrackedExpected = ((Get-Content -LiteralPath $untrackedHash -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
$untrackedActual = (Get-FileHash -LiteralPath $untrackedBundle.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
if ($untrackedExpected -ne $untrackedActual) {
    throw "Untracked source bundle checksum mismatch."
}

& "$PSScriptRoot\mongoyia-validate-untracked-source.ps1" -BundlePath $untrackedBundle.FullName
if ($LASTEXITCODE -ne 0) {
    throw "Nested untracked source validation failed."
}

    Write-Output "Source handover validation: PASS"
    Write-Output "Archive: $ArchivePath"
    Write-Output "Checksum: PASS ($ChecksumPath)"
    Write-Output "Entries: $($entries.Count)"
    Write-Output "Patch mode: $PatchMode"
} finally {
    if (Test-Path -LiteralPath $TempRoot) {
        Remove-Item -LiteralPath $TempRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
