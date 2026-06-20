param(
    [string]$BaseUrl = "http://127.0.0.1:8089",
    [ValidateSet("local", "test", "prod")]
    [string]$Profile = "local",
    [string]$ImUrl = "ws://127.0.0.1:8767",
    [string]$Php = "php",
    [string]$Python = "python",
    [string]$ReportPath = "",
    [switch]$Strict,
    [switch]$CleanupAfterRun,
    [switch]$NoReport,
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

$argsList = @(
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
    "--cleanupAfterRun=$([int]$CleanupAfterRun.IsPresent)",
    "--noReport=$([int]$NoReport.IsPresent)",
    "--interactive=0"
)

if ($ReportPath -ne "") {
    $argsList += "--reportPath=$ReportPath"
}

Write-Output "Running Mongoyia acceptance from $Root"
Write-Output "$Php $($argsList -join ' ')"
& $Php @argsList
exit $LASTEXITCODE
