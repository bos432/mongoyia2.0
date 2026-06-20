param(
    [string]$BaseUrl = "",
    [string]$ImUrl = "",
    [string]$OutputPath = "",
    [int]$RequestsPerPath = 3,
    [int]$WarnMs = 2000,
    [int]$FailMs = 5000,
    [int]$TimeoutSec = 15,
    [string[]]$Paths = @("/", "/product/90?lang=en", "/product/102?lang=mn", "/mall/cart/index?lang=en"),
    [string]$Python = "python",
    [string]$ImRoot = "../../im后端/im后端",
    [switch]$SkipIm
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Add-Result {
    param([string]$Status, [string]$Name, [string]$Detail)
    $script:rows += "| $Status | $Name | $Detail |"
    if ($Status -eq "FAIL") { $script:failures++ }
    if ($Status -eq "WARN") { $script:warnings++ }
}

function Resolve-ProjectPath {
    param([string]$Path)
    if ($Path -eq "") { return "" }
    if ([System.IO.Path]::IsPathRooted($Path)) { return $Path }
    return (Join-Path $Root $Path)
}

function Join-Url {
    param([string]$Base, [string]$Path)
    $baseTrim = $Base.TrimEnd("/")
    if ($Path.StartsWith("/")) { return $baseTrim + $Path }
    return $baseTrim + "/" + $Path
}

$script:rows = @()
$script:failures = 0
$script:warnings = 0
$durations = @()

if ($BaseUrl -eq "") {
    throw "BaseUrl is required, for example -BaseUrl https://test.example.com"
}
if ($BaseUrl -notmatch '^https?://') {
    throw "BaseUrl must start with http:// or https://"
}
if ($RequestsPerPath -le 0) {
    throw "RequestsPerPath must be greater than 0"
}

foreach ($path in $Paths) {
    for ($i = 1; $i -le $RequestsPerPath; $i++) {
        $url = Join-Url $BaseUrl $path
        $watch = [System.Diagnostics.Stopwatch]::StartNew()
        try {
            $response = Invoke-WebRequest -Uri $url -Method GET -TimeoutSec $TimeoutSec -MaximumRedirection 5 -UseBasicParsing
            $watch.Stop()
            $elapsed = [int]$watch.ElapsedMilliseconds
            $durations += $elapsed
            $status = [int]$response.StatusCode
            if ($status -lt 200 -or $status -ge 400) {
                Add-Result "FAIL" $path "request $i returned HTTP $status in ${elapsed}ms"
            } elseif ($elapsed -ge $FailMs) {
                Add-Result "FAIL" $path "request $i returned HTTP $status but exceeded fail threshold: ${elapsed}ms"
            } elseif ($elapsed -ge $WarnMs) {
                Add-Result "WARN" $path "request $i returned HTTP $status but exceeded warn threshold: ${elapsed}ms"
            } else {
                Add-Result "PASS" $path "request $i returned HTTP $status in ${elapsed}ms"
            }
        } catch {
            $watch.Stop()
            Add-Result "FAIL" $path "request $i failed: $($_.Exception.Message)"
        }
    }
}

if (!$SkipIm.IsPresent -and $ImUrl -ne "") {
    $imRootFull = Resolve-ProjectPath $ImRoot
    $imConcurrency = Join-Path $imRootFull "scripts/im-concurrency.py"
    if (Test-Path -LiteralPath $imConcurrency -PathType Leaf) {
        Push-Location $imRootFull
        try {
            & $Python "scripts/im-concurrency.py" "--url" $ImUrl "--connections" 5 "--timeout" $TimeoutSec
            if ($LASTEXITCODE -eq 0) {
                Add-Result "PASS" "IM concurrency" "lightweight IM concurrency regression passed for $ImUrl"
            } else {
                Add-Result "FAIL" "IM concurrency" "im-concurrency.py exited with $LASTEXITCODE"
            }
        } finally {
            Pop-Location
        }
    } else {
        Add-Result "WARN" "IM concurrency" "script not found: $imConcurrency"
    }
} elseif ($SkipIm.IsPresent) {
    Add-Result "WARN" "IM concurrency" "skipped by operator"
} else {
    Add-Result "WARN" "IM concurrency" "skipped because ImUrl was not provided"
}

$count = $durations.Count
$avg = if ($count -gt 0) { [math]::Round(($durations | Measure-Object -Average).Average, 2) } else { 0 }
$max = if ($count -gt 0) { ($durations | Measure-Object -Maximum).Maximum } else { 0 }
$result = if ($failures -gt 0) { "FAIL" } elseif ($warnings -gt 0) { "WARN" } else { "PASS" }

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-production-load-smoke-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$report = @(
    "# Mongoyia Production Load Smoke",
    "",
    "- Result: $result",
    "- Failures: $failures",
    "- Warnings: $warnings",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Base URL: $BaseUrl",
    "- IM URL: $ImUrl",
    "- Requests per path: $RequestsPerPath",
    "- Warn threshold: ${WarnMs}ms",
    "- Fail threshold: ${FailMs}ms",
    "- HTTP samples: $count",
    "- Average HTTP duration: ${avg}ms",
    "- Max HTTP duration: ${max}ms",
    "",
    "This is a non-destructive baseline smoke. It does not create orders, trigger payment callbacks, or mutate database rows.",
    "",
    "| Status | Check | Detail |",
    "|---|---|---|"
) + $rows
$report | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Production load smoke: $result"
Write-Output "Failures: $failures"
Write-Output "Warnings: $warnings"
Write-Output "Report: $outputFull"

if ($failures -gt 0) {
    exit 1
}
