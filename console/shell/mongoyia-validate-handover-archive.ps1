param(
    [string]$ArchivePath = "",
    [string]$ChecksumPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Latest-Archive {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter "mongoyia-handover-*.zip" -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) {
        throw "No handover archive found under runtime/handover."
    }
    return $file.FullName
}

function Normalize-Entry {
    param([string]$Path)
    return $Path.Replace("\", "/").TrimStart("./")
}

function Assert-Entry {
    param([string[]]$Entries, [string]$Relative)
    $expected = Normalize-Entry $Relative
    if (!($Entries -contains $expected)) {
        throw "Missing required archive entry: $Relative"
    }
}

function Assert-NoForbiddenEntry {
    param([string[]]$Entries)

    foreach ($entry in $Entries) {
        if ($entry -match '(^|/)\.env$') {
            throw "Forbidden real env file in archive: $entry"
        }
        if ($entry -match '(^|/)vendor/') {
            throw "Forbidden vendor dependency in archive: $entry"
        }
        if ($entry -match '(^|/)node_modules/') {
            throw "Forbidden node_modules dependency in archive: $entry"
        }
        if ($entry -match '(^|/)web/attachment/') {
            throw "Forbidden uploaded attachment in archive: $entry"
        }
        if ($entry -match '(^|/)web/assets/') {
            throw "Forbidden generated asset in archive: $entry"
        }
        if ($entry -match '\.(sql|dump|bak|7z|rar)$') {
            throw "Forbidden dump/archive payload in archive: $entry"
        }
    }
}

if ($ArchivePath -eq "") {
    $ArchivePath = Latest-Archive
}
$ArchivePath = (Resolve-Path $ArchivePath).Path
if ($ChecksumPath -eq "") {
    $defaultChecksumPath = "$ArchivePath.sha256"
    if (Test-Path -LiteralPath $defaultChecksumPath -PathType Leaf) {
        $ChecksumPath = $defaultChecksumPath
    }
}

if ($ChecksumPath -ne "") {
    $ChecksumPath = (Resolve-Path $ChecksumPath).Path
    $expectedHash = ((Get-Content -LiteralPath $ChecksumPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
    $actualHash = (Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($expectedHash -ne $actualHash) {
        throw "Archive checksum mismatch. expected=$expectedHash actual=$actualHash"
    }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($ArchivePath)
try {
    $entries = @($zip.Entries | ForEach-Object { Normalize-Entry $_.FullName })
} finally {
    $zip.Dispose()
}

$required = @(
    "MANIFEST.md",
    "MONGOYIA_README.md",
    ".env.example",
    ".env.test.example",
    "docs/mongoyia-package-index.md",
    "docs/mongoyia-test-server-runbook.md",
    "docs/mongoyia-local-baseline.md",
    "console/shell/mongoyia-test-profile-preflight.ps1",
    "console/shell/mongoyia-test-server-dry-run.ps1",
    "console/shell/mongoyia-final-handover.ps1",
    "console/shell/mongoyia-archive-handover.ps1",
    "console/shell/mongoyia-validate-handover-archive.ps1",
    "console/controllers/MongoyiaAcceptanceController.php",
    "console/controllers/MongoyiaPackageCheckController.php",
    "im-backend/main.py",
    "im-backend/.env.example",
    "im-backend/scripts/im-healthcheck.py"
)

foreach ($rel in $required) {
    Assert-Entry $entries $rel
}
Assert-NoForbiddenEntry $entries

$acceptanceCount = @($entries | Where-Object { $_ -match '^runtime/acceptance/mongoyia-acceptance-.+\.md$' }).Count
$signoffCount = @($entries | Where-Object { $_ -match '^runtime/acceptance/mongoyia-signoff-.+\.md$' }).Count
$riskCount = @($entries | Where-Object { $_ -match '^runtime/acceptance/mongoyia-risk-register-.+\.md$' }).Count
$deliveryCount = @($entries | Where-Object { $_ -match '^runtime/acceptance/mongoyia-delivery-index-.+\.md$' }).Count
if ($acceptanceCount -lt 1 -or $signoffCount -lt 1 -or $riskCount -lt 1 -or $deliveryCount -lt 1) {
    throw "Archive must include latest acceptance, signoff, risk register, and delivery index reports."
}

Write-Output "Handover archive validation: PASS"
Write-Output "Archive: $ArchivePath"
if ($ChecksumPath -ne "") {
    Write-Output "Checksum: PASS ($ChecksumPath)"
}
Write-Output "Entries: $($entries.Count)"
Write-Output "Required static files: $($required.Count)"
Write-Output "Reports: acceptance=$acceptanceCount signoff=$signoffCount risk=$riskCount delivery=$deliveryCount"
