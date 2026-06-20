param(
    [string]$BaseUrl = "",
    [ValidateSet("local", "test", "prod")]
    [string]$Profile = "test",
    [int]$Strict = 1,
    [string]$Php = "php",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [switch]$SkipConnectivity,
    [switch]$SkipApi,
    [int]$PlatformStoreId = 5,
    [string]$PlatformUsername = "codex_platform_backend_test_5",
    [string]$SellerUsername = "zhishichanquan",
    [int]$PaymentUserId = 71,
    [string]$ProductIds = "90,102",
    [int]$ImMerchantUid = 37,
    [int]$ImProductId = 102,
    [int]$ImStoreId = 9
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

function Run-Step {
    param([string]$Name, [string[]]$ArgsList)
    Write-Output ""
    Write-Output "== $Name =="
    Write-Output "$Php $($ArgsList -join ' ')"
    & $Php @ArgsList
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

Write-Output "Running Mongoyia test-server dry-run from $Root"
Write-Output "Profile=$Profile Strict=$Strict PhpEnv=$PhpEnv ImEnv=$ImEnv"

Run-Step "deployment configuration" @(
    "yii",
    "deploy-check/run",
    "--profile=$Profile",
    "--strict=$Strict",
    "--phpEnv=$PhpEnv",
    "--imEnv=$ImEnv",
    "--skipConnectivity=$([int]$SkipConnectivity.IsPresent)",
    "--interactive=0"
)

Run-Step "handover package check" @("yii", "mongoyia-package-check/run", "--interactive=0")
Run-Step "security hardcode scan" @("yii", "mongoyia-security-scan/run", "--strict=$Strict", "--interactive=0")
Run-Step "host cleanup dry-run" @("yii", "mongoyia-host-cleanup/run", "--interactive=0")
Run-Step "catalog cleanup dry-run" @("yii", "mongoyia-catalog-cleanup/run", "--interactive=0")
Run-Step "data readiness" @(
    "yii",
    "mongoyia-data-readiness/run",
    "--platformStoreId=$PlatformStoreId",
    "--platformUsername=$PlatformUsername",
    "--sellerUsername=$SellerUsername",
    "--paymentUserId=$PaymentUserId",
    "--productIds=$ProductIds",
    "--imMerchantUid=$ImMerchantUid",
    "--imProductId=$ImProductId",
    "--imStoreId=$ImStoreId",
    "--interactive=0"
)
Run-Step "catalog readiness" @("yii", "mongoyia-catalog-readiness/run", "--interactive=0")
Run-Step "translation readiness" @("yii", "mongoyia-translation-readiness/run", "--strict=$Strict", "--productIds=$ProductIds", "--interactive=0")
Run-Step "order integrity" @("yii", "mongoyia-order-integrity/run", "--interactive=0")
Run-Step "payment audit" @("yii", "mongoyia-payment-audit/run", "--interactive=0")

if (!$SkipApi.IsPresent -and $BaseUrl -ne "") {
    Run-Step "API smoke" @("yii", "api-smoke-test/run", "--baseUrl=$BaseUrl", "--interactive=0")
}

Run-Step "generated test-data cleanup verification" @("yii", "mongoyia-test-cleanup/run", "--failOnPending=1", "--interactive=0")

Write-Output ""
Write-Output "Mongoyia test-server dry-run: PASS"
