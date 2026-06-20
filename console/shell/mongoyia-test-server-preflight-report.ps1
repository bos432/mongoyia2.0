param(
    [string]$BaseUrl = "",
    [ValidateSet("local", "test", "prod")]
    [string]$Profile = "test",
    [int]$Strict = 1,
    [string]$Php = "php",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [string]$OutputPath = "",
    [string]$HandoverArchivePath = "",
    [string]$SourceHandoverArchivePath = "",
    [switch]$SkipConnectivity,
    [switch]$SkipApi
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-test-server-preflight-$stamp.md"
}

function Resolve-ProjectPath {
    param([string]$Path)
    if ($Path -eq "") { return "" }
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Latest-Archive {
    param([string]$Pattern)
    $file = Get-ChildItem -Path (Join-Path $Root "runtime/handover") -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return "" }
    return $file.FullName
}

function Append-Report {
    param([string[]]$Lines)
    $script:report += $Lines
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

    foreach ($line in $output) {
        Write-Output $line
    }

    $status = if ($exitCode -eq 0) { "PASS" } else { "FAIL" }
    Append-Report @(
        "",
        "## $Name",
        "",
        "- Status: $status",
        "- Exit code: $exitCode",
        "",
        "Command:",
        "",
        '```text',
        $CommandText,
        '```',
        "",
        "Output:",
        "",
        '```text'
    )
    Append-Report ($output | ForEach-Object { [string]$_ })
    Append-Report @('```')

    if ($exitCode -ne 0) {
        $script:failures++
    }
}

$outputFullPath = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFullPath
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

if ($HandoverArchivePath -eq "") {
    $HandoverArchivePath = Latest-Archive "mongoyia-handover-*.zip"
} else {
    $HandoverArchivePath = Resolve-ProjectPath $HandoverArchivePath
}
if ($SourceHandoverArchivePath -eq "") {
    $SourceHandoverArchivePath = Latest-Archive "mongoyia-source-handover-*.zip"
} else {
    $SourceHandoverArchivePath = Resolve-ProjectPath $SourceHandoverArchivePath
}

$script:failures = 0
$script:report = @(
    "# Mongoyia Test Server Preflight Report",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Profile: $Profile",
    "- Strict: $Strict",
    "- BaseUrl: $BaseUrl",
    "- PHP env: $PhpEnv",
    "- IM env: $ImEnv",
    "- Skip connectivity: $([int]$SkipConnectivity.IsPresent)",
    "- Skip API: $([int]$SkipApi.IsPresent)",
    "",
    "This report is intended to be generated on the restored test server before full acceptance. It does not create checkout, payment, or chat regression data."
)

Run-Step "Deployment configuration" "$Php yii deploy-check/run --profile=$Profile --strict=$Strict --phpEnv=$PhpEnv --imEnv=$ImEnv --skipConnectivity=$([int]$SkipConnectivity.IsPresent) --interactive=0" {
    & $Php yii deploy-check/run "--profile=$Profile" "--strict=$Strict" "--phpEnv=$PhpEnv" "--imEnv=$ImEnv" "--skipConnectivity=$([int]$SkipConnectivity.IsPresent)" --interactive=0
}

Run-Step "Package check" "$Php yii mongoyia-package-check/run --interactive=0" {
    & $Php yii mongoyia-package-check/run --interactive=0
}

Run-Step "Security scan" "$Php yii mongoyia-security-scan/run --strict=$Strict --interactive=0" {
    & $Php yii mongoyia-security-scan/run "--strict=$Strict" --interactive=0
}

if ($HandoverArchivePath -ne "") {
    Run-Step "Handover archive validation" ".\console\shell\mongoyia-validate-handover-archive.ps1 -ArchivePath '$HandoverArchivePath'" {
        & "$PSScriptRoot\mongoyia-validate-handover-archive.ps1" -ArchivePath $HandoverArchivePath
    }
}

if ($SourceHandoverArchivePath -ne "") {
    $patchMode = if ($Profile -eq "local") { "reverse" } else { "skip" }
    Run-Step "Source handover validation" ".\console\shell\mongoyia-validate-source-handover.ps1 -ArchivePath '$SourceHandoverArchivePath' -PatchMode $patchMode" {
        & "$PSScriptRoot\mongoyia-validate-source-handover.ps1" -ArchivePath $SourceHandoverArchivePath -PatchMode $patchMode
    }
}

$dryRunArgs = @(
    "yii",
    "mongoyia-data-readiness/run",
    "--interactive=0"
)
Run-Step "Data readiness" "$Php $($dryRunArgs -join ' ')" {
    & $Php @dryRunArgs
}

Run-Step "Catalog readiness" "$Php yii mongoyia-catalog-readiness/run --interactive=0" {
    & $Php yii mongoyia-catalog-readiness/run --interactive=0
}

Run-Step "Translation readiness" "$Php yii mongoyia-translation-readiness/run --strict=$Strict --interactive=0" {
    & $Php yii mongoyia-translation-readiness/run "--strict=$Strict" --interactive=0
}

Run-Step "Order integrity" "$Php yii mongoyia-order-integrity/run --interactive=0" {
    & $Php yii mongoyia-order-integrity/run --interactive=0
}

Run-Step "Payment audit" "$Php yii mongoyia-payment-audit/run --interactive=0" {
    & $Php yii mongoyia-payment-audit/run --interactive=0
}

if (!$SkipApi.IsPresent -and $BaseUrl -ne "") {
    Run-Step "API smoke" "$Php yii api-smoke-test/run --baseUrl=$BaseUrl --interactive=0" {
        & $Php yii api-smoke-test/run "--baseUrl=$BaseUrl" --interactive=0
    }
}

Run-Step "Generated test-data cleanup verification" "$Php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0" {
    & $Php yii mongoyia-test-cleanup/run --failOnPending=1 --interactive=0
}

$result = if ($script:failures -eq 0) { "PASS" } else { "FAIL" }
$script:report = @(
    $script:report[0],
    "",
    "- Result: $result",
    "- Failures: $script:failures"
) + $script:report[1..($script:report.Count - 1)]

$script:report | Set-Content -LiteralPath $outputFullPath -Encoding UTF8

Write-Output ""
Write-Output "Preflight report: $outputFullPath"
Write-Output "Result: $result ($script:failures failure(s))"

if ($script:failures -gt 0) {
    exit 1
}
