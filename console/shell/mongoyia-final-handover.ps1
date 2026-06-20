param(
    [string]$BaseUrl = "http://127.0.0.1:8089",
    [ValidateSet("local", "test", "prod")]
    [string]$Profile = "local",
    [string]$ImUrl = "ws://127.0.0.1:8767",
    [string]$Php = "php",
    [string]$Python = "python",
    [string]$Tester = "TBD",
    [string]$Notes = "TBD",
    [switch]$Strict,
    [string]$PlatformUsername = "codex_platform_backend_test_5",
    [string]$PlatformPassword = "CodexTest123",
    [string]$SellerUsername = "zhishichanquan",
    [string]$SellerPassword = "123456",
    [int]$PlatformStoreId = 5,
    [int]$PaymentUserId = 71,
    [string]$ProductIds = "90,102",
    [string]$PaymentProductIds = "90,102",
    [int]$ImMerchantUid = 37,
    [int]$ImProductId = 102,
    [int]$ImStoreId = 9
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$ReportPath = "runtime/acceptance/mongoyia-acceptance-$Stamp.md"
$SignoffPath = "runtime/acceptance/mongoyia-signoff-$Stamp.md"
$RiskPath = "runtime/acceptance/mongoyia-risk-register-$Stamp.md"
$DeliveryIndexPath = "runtime/acceptance/mongoyia-delivery-index-$Stamp.md"

function Run-Step {
    param([string[]]$ArgsList)
    Write-Output "$Php $($ArgsList -join ' ')"
    & $Php @ArgsList
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

Write-Output "Running Mongoyia final handover from $Root"

Run-Step @(
    "yii",
    "mongoyia-acceptance/run",
    "--baseUrl=$BaseUrl",
    "--profile=$Profile",
    "--strict=$([int]$Strict.IsPresent)",
    "--imUrl=$ImUrl",
    "--pythonBin=$Python",
    "--platformUsername=$PlatformUsername",
    "--platformPassword=$PlatformPassword",
    "--sellerUsername=$SellerUsername",
    "--sellerPassword=$SellerPassword",
    "--platformStoreId=$PlatformStoreId",
    "--paymentUserId=$PaymentUserId",
    "--productIds=$ProductIds",
    "--paymentProductIds=$PaymentProductIds",
    "--imMerchantUid=$ImMerchantUid",
    "--imProductId=$ImProductId",
    "--imStoreId=$ImStoreId",
    "--cleanupAfterRun=1",
    "--reportPath=$ReportPath",
    "--interactive=0"
)

Run-Step @("yii", "mongoyia-signoff/run", "--reportPath=$ReportPath", "--outputPath=$SignoffPath", "--tester=$Tester", "--notes=$Notes", "--interactive=0")
Run-Step @("yii", "mongoyia-risk-register/run", "--reportPath=$ReportPath", "--outputPath=$RiskPath", "--interactive=0")
Run-Step @("yii", "mongoyia-delivery-index/run", "--acceptancePath=$ReportPath", "--signoffPath=$SignoffPath", "--riskPath=$RiskPath", "--outputPath=$DeliveryIndexPath", "--interactive=0")
Run-Step @("yii", "mongoyia-test-cleanup/run", "--failOnPending=1", "--interactive=0")

Write-Output ""
Write-Output "Final handover files:"
Write-Output "- $ReportPath"
Write-Output "- $SignoffPath"
Write-Output "- $RiskPath"
Write-Output "- $DeliveryIndexPath"
