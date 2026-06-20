param(
    [string]$BaseUrl = "",
    [string]$ImUrl = "",
    [string]$DeliveryArchivePath = "",
    [string]$SqlDumpPath = "../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql",
    [string]$SqlChecksumPath = "runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256",
    [string]$Database = "outer",
    [string]$BackupReference = "",
    [string]$BackupArtifactPath = "",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [string]$OutputPath = "",
    [ValidateSet("test", "prod")]
    [string]$Profile = "test",
    [switch]$RequireExternalInputs
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

function Latest-Delivery {
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^mongoyia-test-server-delivery-.+\.(zip|tar\.gz)$' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Host-From-Url {
    param([string]$Value)
    if ($Value -eq "") { return "" }
    try {
        $uri = [System.Uri]$Value
        return $uri.Host.Trim().TrimEnd(".").ToLowerInvariant()
    } catch {
        return ""
    }
}

function Add-Report {
    param([string[]]$Lines)
    $script:report += $Lines
}

function Add-Pending {
    param([string]$Message)
    if ($RequireExternalInputs.IsPresent) {
        $script:failures++
        $script:checks += "- FAIL $Message"
    } else {
        $script:pending++
        $script:checks += "- PENDING $Message"
    }
}

function Add-Warn {
    param([string]$Message)
    $script:warnings++
    $script:checks += "- WARN $Message"
}

function Add-Pass {
    param([string]$Message)
    $script:checks += "- PASS $Message"
}

function Run-Step {
    param([string]$Name, [string]$CommandText, [scriptblock]$Block)
    Write-Output ""
    Write-Output "== $Name =="
    Write-Output $CommandText
    $output = @()
    $exitCode = 0
    try {
        $output = @(& $Block 2>&1)
        $exitCode = if ($global:LASTEXITCODE -is [int]) { $global:LASTEXITCODE } else { 0 }
    } catch {
        $output += $_.Exception.Message
        $exitCode = 1
    }
    foreach ($line in $output) { Write-Output $line }
    $status = if ($exitCode -eq 0) { "PASS" } else { "FAIL" }
    if ($exitCode -ne 0) { $script:failures++ }
    Add-Report @(
        "",
        "## $Name",
        "",
        "- Status: $status",
        "- Exit code: $exitCode",
        "",
        '```text',
        $CommandText,
        '```',
        "",
        "Output:",
        "",
        '```text'
    )
    Add-Report ($output | ForEach-Object { [string]$_ })
    Add-Report @('```')
}

if ($DeliveryArchivePath -eq "") {
    $DeliveryArchivePath = Latest-Delivery
} else {
    $DeliveryArchivePath = Resolve-ProjectPath $DeliveryArchivePath
}
$SqlDumpPath = Resolve-ProjectPath $SqlDumpPath
$SqlChecksumPath = Resolve-ProjectPath $SqlChecksumPath
$BackupArtifactPath = Resolve-ProjectPath $BackupArtifactPath

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-p2-readiness-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$script:failures = 0
$script:warnings = 0
$script:pending = 0
$script:checks = @()
$script:report = @(
    "# Mongoyia P2 Readiness Report",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Profile: $Profile",
    "- Require external inputs: $($RequireExternalInputs.IsPresent)",
    "- Base URL: $BaseUrl",
    "- IM URL: $ImUrl",
    "- Delivery archive: $DeliveryArchivePath",
    "- SQL dump: $SqlDumpPath",
    "- SQL checksum: $SqlChecksumPath",
    "- Database: $Database",
    "- Backup reference: $BackupReference",
    "- Backup artifact: $BackupArtifactPath",
    "",
    "This report closes local P2 preparation. Real restore, payment sandbox, and WSS acceptance still require real server/domain/provider inputs."
)

if ($DeliveryArchivePath -ne "" -and (Test-Path -LiteralPath $DeliveryArchivePath -PathType Leaf)) {
    Run-Step "Delivery archive validation" ".\console\shell\mongoyia-validate-test-server-delivery.ps1 -ArchivePath `"$DeliveryArchivePath`"" {
        & "$PSScriptRoot\mongoyia-validate-test-server-delivery.ps1" -ArchivePath $DeliveryArchivePath
    }
} else {
    Add-Pending "Latest test-server delivery archive is missing."
}

if ((Test-Path -LiteralPath $SqlDumpPath -PathType Leaf) -and (Test-Path -LiteralPath $SqlChecksumPath -PathType Leaf)) {
    $actualSql = (Get-FileHash -LiteralPath $SqlDumpPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedSql = ((Get-Content -LiteralPath $SqlChecksumPath -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
    if ($actualSql -eq $expectedSql) {
        Add-Pass "SQL dump SHA256 matches sidecar."
    } else {
        $script:failures++
        $script:checks += "- FAIL SQL dump SHA256 mismatch. expected=$expectedSql actual=$actualSql"
    }
} else {
    Add-Pending "SQL dump and checksum sidecar must be copied to the test server."
}

if ($BaseUrl -eq "" -or !$BaseUrl.StartsWith("https://") -or $BaseUrl -match "localhost|127\.0\.0\.1|example\.com|www\.mongoyia\.com|mongoyia\.com") {
    Add-Pending "Real HTTPS test BaseUrl is required before restore apply."
} else {
    Add-Pass "BaseUrl looks like a real test HTTPS URL."
}
if ($ImUrl -eq "" -or !$ImUrl.StartsWith("wss://") -or $ImUrl -match "localhost|127\.0\.0\.1|example\.com|www\.mongoyia\.com|mongoyia\.com") {
    Add-Pending "Real WSS IM URL is required before restore apply."
} else {
    Add-Pass "ImUrl looks like a real test WSS URL."
}
$baseHost = Host-From-Url $BaseUrl
$imHost = Host-From-Url $ImUrl
if ($baseHost -ne "" -and $imHost -ne "" -and $baseHost -ne $imHost) {
    Add-Warn "BaseUrl host and ImUrl host differ; input-gate requires them to match unless the gate is changed intentionally."
}
if ($BackupReference -eq "" -and $BackupArtifactPath -eq "") {
    Add-Pending "BackupReference or BackupArtifactPath is required before restore apply."
} else {
    Add-Pass "Backup reference/artifact is present."
}

foreach ($file in @(".env.example", ".env.test.example")) {
    $text = Get-Content -LiteralPath (Join-Path $Root $file) -Raw
    foreach ($token in @("QPAY_AUTH_URL", "QPAY_INVOICE_URL")) {
        if ($text -notmatch [regex]::Escape($token)) {
            $script:failures++
            $script:checks += "- FAIL $file is missing $token."
        }
    }
}
$paymentController = Join-Path $Root "frontend/modules/mall/controllers/PaymentController.php"
$paymentText = Get-Content -LiteralPath $paymentController -Raw
if ($paymentText -match "env\('QPAY_AUTH_URL'" -and $paymentText -match "env\('QPAY_INVOICE_URL'") {
    Add-Pass "Runtime QPay gateway URLs are configurable through .env."
} else {
    $script:failures++
    $script:checks += "- FAIL Runtime QPay gateway URLs are still hardcoded."
}
$legacyPayment = Join-Path $Root "frontend/modules/mall/controllers/PaymentController-0.php"
if (Test-Path -LiteralPath $legacyPayment -PathType Leaf) {
    $legacyText = Get-Content -LiteralPath $legacyPayment -Raw
    if ($legacyText -match "merchant\.qpay\.mn") {
        Add-Warn "Historical backup PaymentController-0.php still contains QPay hardcoded URLs; do not deploy it as runtime code."
    }
}

Run-Step "PHP syntax: payment controller" "php -l frontend\modules\mall\controllers\PaymentController.php" {
    & php -l frontend\modules\mall\controllers\PaymentController.php
}
Run-Step "PHP syntax: deploy check" "php -l console\controllers\DeployCheckController.php" {
    & php -l console\controllers\DeployCheckController.php
}
Run-Step "Input-gate smoke" ".\console\shell\mongoyia-test-server-input-gate-smoke.ps1" {
    & "$PSScriptRoot\mongoyia-test-server-input-gate-smoke.ps1"
}
Run-Step "Go/no-go smoke" ".\console\shell\mongoyia-test-server-go-no-go-smoke.ps1" {
    & "$PSScriptRoot\mongoyia-test-server-go-no-go-smoke.ps1"
}
Run-Step "Package check" "php yii mongoyia-package-check/run --interactive=0" {
    & php yii mongoyia-package-check/run --interactive=0
}
Run-Step "Security scan" "php yii mongoyia-security-scan/run --interactive=0" {
    & php yii mongoyia-security-scan/run --interactive=0
}
Run-Step "Focused translation readiness" "php yii mongoyia-translation-readiness/run --strict=0 --productIds=90,102 --categoryIds=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --interactive=0" {
    & php yii mongoyia-translation-readiness/run --strict=0 --productIds=90,102 --categoryIds=93,94,95,96,97,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114 --interactive=0
}
Run-Step "Translation audit" "php yii mongoyia-translation-audit/run --interactive=0" {
    & php yii mongoyia-translation-audit/run --interactive=0
}
Run-Step "Generated test-data cleanup verification" "php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0" {
    & php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
}

$result = if ($script:failures -gt 0) { "FAIL" } elseif ($script:warnings -gt 0 -or $script:pending -gt 0) { "WARN" } else { "PASS" }
$script:report = @(
    $script:report[0],
    "",
    "- Result: $result",
    "- Failures: $script:failures",
    "- Warnings: $script:warnings",
    "- Pending external inputs: $script:pending"
) + $script:report[1..($script:report.Count - 1)] + @(
    "",
    "## Readiness Checks",
    ""
) + $script:checks

$script:report | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output ""
Write-Output "P2 readiness report: $outputFull"
Write-Output "Result: $result ($script:failures failure(s), $script:warnings warning(s), $script:pending pending external input(s))"
if ($script:failures -gt 0) {
    exit 1
}
