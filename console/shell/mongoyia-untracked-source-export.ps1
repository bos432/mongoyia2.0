param(
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [string]$BundlePath = "",
    [string]$ReportPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

$OutputRoot = Join-Path $Root $OutputDir
$Stage = Join-Path $OutputRoot "mongoyia-untracked-source-$Stamp"
if ($BundlePath -eq "") {
    $BundlePath = Join-Path $OutputRoot "mongoyia-untracked-source-$Stamp.zip"
}
if ($ReportPath -eq "") {
    $ReportPath = Join-Path $OutputRoot "mongoyia-untracked-source-export-$Stamp.md"
}

$bundleFullPath = if ([System.IO.Path]::IsPathRooted($BundlePath)) { $BundlePath } else { Join-Path $Root $BundlePath }
$reportFullPath = if ([System.IO.Path]::IsPathRooted($ReportPath)) { $ReportPath } else { Join-Path $Root $ReportPath }

function Normalize-PathText {
    param([string]$Path)
    $path = $Path.Replace("\", "/")
    while ($path.StartsWith("./")) {
        $path = $path.Substring(2)
    }
    return $path
}

function Is-ForbiddenSource {
    param([string]$Path)
    $path = Normalize-PathText $Path

    $forbiddenPrefixes = @(
        ".well-known/",
        "demo/",
        "runtime/",
        "web/.well-known/"
    )
    foreach ($prefix in $forbiddenPrefixes) {
        if ($path.StartsWith($prefix)) {
            return $true
        }
    }

    $forbiddenExact = @(
        "194.sql",
        "petever1.jpg",
        "web/log.txt",
        "web/success.php",
        "frontend/modules/mall/controllers/PaymentController-0.php",
        "web/resources/mall/default/views/payment/succeeded-0.php"
    )
    if ($forbiddenExact -contains $path) {
        return $true
    }

    if ($path -match '\.(sql|jpg|jpeg|png|gif|webp|zip|tar|gz|rar|7z|log)$') {
        return $true
    }

    return $false
}

function Is-AllowedSource {
    param([string]$Path)
    $path = Normalize-PathText $Path

    if (Is-ForbiddenSource $path) {
        return $false
    }

    $allowedExact = @(
        ".env.example",
        ".env.test.example",
        "MONGOYIA_README.md"
    )
    if ($allowedExact -contains $path) {
        return $true
    }

    $allowedPatterns = @(
        '^backend/modules/mall/(controllers|views)/.+\.php$',
        '^common/(helpers|messages|models)/.+\.php$',
        '^console/controllers/.+\.php$',
        '^console/migrations/m260608_.+\.php$',
        '^console/shell/mongoyia-.+\.(ps1|sh)$',
        '^docs/mongoyia-.+\.md$',
        '^frontend/modules/mall/controllers/.+\.php$',
        '^web/resources/mall/default/views/.+\.php$'
    )
    foreach ($pattern in $allowedPatterns) {
        if ($path -match $pattern) {
            return $true
        }
    }

    return $false
}

function Copy-ToStage {
    param([string]$Relative)
    $source = Join-Path $Root $Relative
    if (!(Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Untracked source file is missing: $Relative"
    }

    $dest = Join-Path $Stage (Normalize-PathText $Relative)
    $destDir = Split-Path -Parent $dest
    if (!(Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $source -Destination $dest -Force
}

New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
if (Test-Path -LiteralPath $Stage) {
    $resolvedStage = (Resolve-Path -LiteralPath $Stage).Path
    $resolvedOutput = (Resolve-Path -LiteralPath $OutputRoot).Path
    if (!$resolvedStage.StartsWith($resolvedOutput)) {
        throw "Refusing to remove unexpected stage path: $resolvedStage"
    }
    Remove-Item -LiteralPath $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

$statusOutput = @(& git -c color.status=false status --short --untracked-files=all)
if ($LASTEXITCODE -ne 0) {
    throw "git status failed"
}

$untrackedFiles = @(
    $statusOutput |
        Where-Object { $_.StartsWith("?? ") } |
        ForEach-Object { Normalize-PathText $_.Substring(3) } |
        Where-Object { $_ -ne "" } |
        Sort-Object -Unique
)

$included = @()
$excluded = @()
foreach ($path in $untrackedFiles) {
    if (Is-AllowedSource $path) {
        $included += $path
    } else {
        $excluded += $path
    }
}

if ($included.Count -eq 0) {
    throw "No untracked source files matched the Mongoyia source whitelist."
}

foreach ($path in $included) {
    if (Is-ForbiddenSource $path) {
        throw "Forbidden file matched source bundle whitelist: $path"
    }
    Copy-ToStage $path
}

$manifest = @(
    "# Mongoyia Untracked Source Bundle Manifest",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Bundle: $bundleFullPath",
    "- Included untracked source files: $($included.Count)",
    "- Excluded untracked entries: $($excluded.Count)",
    "",
    "This bundle is intentionally limited to untracked source, docs, templates, and handover scripts. It excludes SQL dumps, runtime output, uploaded/demo files, logs, images, `.well-known`, and backup controller/view copies.",
    "",
    "## Included Files",
    ""
)
$manifest += ($included | ForEach-Object { "- ``$($_)``" })
$manifest += @(
    "",
    "## Excluded Untracked Entries",
    ""
)
$manifest += ($excluded | ForEach-Object { "- ``$($_)``" })
$manifest | Set-Content -LiteralPath (Join-Path $Stage "MANIFEST.md") -Encoding UTF8

if (Test-Path -LiteralPath $bundleFullPath) {
    Remove-Item -LiteralPath $bundleFullPath -Force
}
if (Test-Path -LiteralPath "$bundleFullPath.sha256") {
    Remove-Item -LiteralPath "$bundleFullPath.sha256" -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($bundleFullPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $Stage -Recurse -File -Force | ForEach-Object {
        $relative = Normalize-PathText $_.FullName.Substring($Stage.Length).TrimStart("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $relative) | Out-Null
    }
} finally {
    $zip.Dispose()
}
$bundleHash = (Get-FileHash -LiteralPath $bundleFullPath -Algorithm SHA256).Hash.ToLowerInvariant()
"$bundleHash  $(Split-Path -Leaf $bundleFullPath)" | Set-Content -LiteralPath "$bundleFullPath.sha256" -Encoding ASCII

$reportLines = @(
    "# Mongoyia Untracked Source Export",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Bundle: $bundleFullPath",
    "- Bundle SHA256: $bundleHash",
    "- Bundle size: $((Get-Item -LiteralPath $bundleFullPath).Length) bytes",
    "- Included untracked source files: $($included.Count)",
    "- Excluded untracked entries: $($excluded.Count)",
    "",
    "## Scope",
    "",
    "This archive complements the tracked patch generated by `mongoyia-source-diff-export`. It contains only safe untracked source files selected by a whitelist.",
    "",
    "## Included Files",
    ""
)
$reportLines += ($included | ForEach-Object { "- ``$($_)``" })
$reportLines += @(
    "",
    "## Excluded Entries For Manual Review",
    ""
)
$reportLines += ($excluded | ForEach-Object { "- ``$($_)``" })
$reportLines += @(
    "",
    "## Receiver Notes",
    "",
    "1. Apply the tracked patch first, then copy these untracked source files onto the same baseline.",
    "2. Review excluded entries manually; do not include SQL dumps, real `.env`, uploaded files, generated `runtime`, logs, images, or `.well-known` content in source handover.",
    "3. Run `console/shell/mongoyia-validate-untracked-source.ps1 -BundlePath <bundle>` after copying or receiving this archive."
)
$reportLines | Set-Content -LiteralPath $reportFullPath -Encoding UTF8

Write-Output "Untracked source bundle: $bundleFullPath"
Write-Output "Untracked source checksum: $bundleFullPath.sha256"
Write-Output "Untracked source report: $reportFullPath"
Write-Output "Included files: $($included.Count)"
Write-Output "Excluded entries: $($excluded.Count)"
