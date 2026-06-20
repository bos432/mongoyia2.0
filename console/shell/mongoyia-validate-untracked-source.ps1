param(
    [string]$BundlePath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($BundlePath -eq "") {
    $latest = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter "mongoyia-untracked-source-*.zip" -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $latest) {
        throw "BundlePath is required because no local mongoyia-untracked-source-*.zip bundle was found."
    }
    $BundlePath = $latest.FullName
}

$bundleFullPath = if ([System.IO.Path]::IsPathRooted($BundlePath)) { $BundlePath } else { Join-Path $Root $BundlePath }
if (!(Test-Path -LiteralPath $bundleFullPath -PathType Leaf)) {
    throw "Bundle not found: $bundleFullPath"
}

function Normalize-ArchiveEntry {
    param([string]$Path)
    $path = $Path.Replace("\", "/")
    while ($path.StartsWith("./")) {
        $path = $path.Substring(2)
    }
    return $path
}

$requiredFiles = @(
    "MANIFEST.md",
    ".env.example",
    ".env.test.example",
    "MONGOYIA_README.md",
    "backend/modules/mall/controllers/PaymentAttemptController.php",
    "common/helpers/MallPlatformHelper.php",
    "common/messages/mn/mall.php",
    "common/models/mall/PaymentAttempt.php",
    "console/controllers/MongoyiaPackageCheckController.php",
    "console/migrations/m260608_180000_mongoyia_payment_attempt.php",
    "console/shell/mongoyia-untracked-source-export.ps1",
    "console/shell/mongoyia-validate-untracked-source.ps1",
    "docs/mongoyia-local-baseline.md",
    "frontend/modules/mall/controllers/ChatController.php",
    "web/resources/mall/default/views/chat/index.php"
)

$forbiddenPatterns = @(
    '^194\.sql$',
    '^petever1\.jpg$',
    '^\.well-known/',
    '^demo/',
    '^runtime/',
    '^web/\.well-known/',
    '^web/log\.txt$',
    '^web/success\.php$',
    '(^|/)[^/]+-0\.php$',
    '\.(sql|jpg|jpeg|png|gif|webp|zip|tar|gz|rar|7z|log)$'
)

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($bundleFullPath)
try {
    $entries = @($zip.Entries | Where-Object { $_.FullName -ne "" -and !$_.FullName.EndsWith("/") } | ForEach-Object { Normalize-ArchiveEntry $_.FullName })

    $failures = 0
    foreach ($required in $requiredFiles) {
        if ($entries -notcontains $required) {
            $failures++
            Write-Error "Missing required untracked source bundle entry: $required" -ErrorAction Continue
        }
    }

    foreach ($entry in $entries) {
        foreach ($pattern in $forbiddenPatterns) {
            if ($entry -match $pattern) {
                $failures++
                Write-Error "Forbidden entry in untracked source bundle: $entry" -ErrorAction Continue
                break
            }
        }
    }

    if ($entries.Count -lt 30) {
        $failures++
        Write-Error "Unexpectedly small untracked source bundle: $($entries.Count) entries" -ErrorAction Continue
    }

    $hashPath = "$bundleFullPath.sha256"
    if (Test-Path -LiteralPath $hashPath -PathType Leaf) {
        $expectedHash = ((Get-Content -LiteralPath $hashPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
        $actualHash = (Get-FileHash -LiteralPath $bundleFullPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($expectedHash -ne $actualHash) {
            $failures++
            Write-Error "Checksum mismatch: expected $expectedHash, got $actualHash" -ErrorAction Continue
        }
    } else {
        $failures++
        Write-Error "Missing checksum file: $hashPath" -ErrorAction Continue
    }

    if ($failures -gt 0) {
        throw "Untracked source bundle validation failed: $failures failure(s)."
    }

    Write-Output "Untracked source bundle validation: PASS"
    Write-Output "Bundle: $bundleFullPath"
    Write-Output "Entries: $($entries.Count)"
} finally {
    $zip.Dispose()
}
