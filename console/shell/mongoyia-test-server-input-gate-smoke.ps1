param(
    [string]$OutputDir = "runtime/handover/input-gate-smoke"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

$SmokeRoot = Join-Path $Root $OutputDir
if (Test-Path -LiteralPath $SmokeRoot) {
    $resolvedSmoke = (Resolve-Path -LiteralPath $SmokeRoot).Path
    $resolvedRuntime = (Resolve-Path -LiteralPath (Join-Path $Root "runtime")).Path
    if (!$resolvedSmoke.StartsWith($resolvedRuntime)) {
        throw "Refusing to remove unexpected smoke path: $resolvedSmoke"
    }
    Remove-Item -LiteralPath $SmokeRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $SmokeRoot -Force | Out-Null

$secret = "0123456789abcdef0123456789abcdef"
$phpGood = Join-Path $SmokeRoot "php-good.env"
$imGood = Join-Path $SmokeRoot "im-good.env"
$phpBad = Join-Path $SmokeRoot "php-bad.env"
$imBad = Join-Path $SmokeRoot "im-bad.env"
$goodReport = Join-Path $SmokeRoot "good.md"
$badReport = Join-Path $SmokeRoot "bad.md"
$powerShell = (Get-Command pwsh -ErrorAction SilentlyContinue | Select-Object -First 1).Source
if ($null -eq $powerShell -or $powerShell -eq "") {
    $powerShell = (Get-Command powershell -ErrorAction Stop | Select-Object -First 1).Source
}
$inputGateScript = Join-Path $PSScriptRoot "mongoyia-test-server-input-gate.ps1"

@"
DB_DSN=mysql:host=10.0.0.10;port=3306;dbname=outer
DB_USERNAME=outer_user
DB_PASSWORD=outer_password
DB_TABLE_PREFIX=fb_
YII_ENV=test
YII_DEBUG=false
DEFAULT_STORE_ID=5
DEFAULT_ROUTE=mall
STORE_PLATFORM_DOMAIN=test.mongoyia.local
WEB_BASE_URL=https://test.mongoyia.local
MALL_PLATFORM_MODE=1
MALL_PLATFORM_OPERATOR_STORE_IDS=5
REDIS_HOST=10.0.0.11
REDIS_PORT=6379
REDIS_DATABASE=0
UPLOAD_HTTP_PREFIX=/attachment
CHAT_UPLOAD_URL=/attachment/chat
IM_WEBSOCKET_URL=wss://test.mongoyia.local/ws
QPAY_AUTH_BASIC=qpay-basic-token
QPAY_INVOICE_CODE=qpay-invoice-code
QPAY_AUTH_URL=https://merchant.qpay.mn/v2/auth/token
QPAY_INVOICE_URL=https://merchant.qpay.mn/v2/invoice
QPAY_CALLBACK_BASE=https://test.mongoyia.local
QPAY_CALLBACK_HMAC_SECRET=$secret
QPAY_CALLBACK_MAX_AGE_SECONDS=300
LIANLIAN_SANDBOX=true
LIANLIAN_MERCHANT_ID=lianlian-merchant
LIANLIAN_PUBLIC_KEY=lianlian-public-key
LIANLIAN_PRIVATE_KEY=lianlian-private-key
LIANLIAN_CALLBACK_BASE=https://test.mongoyia.local
LIANLIAN_CALLBACK_HMAC_SECRET=$secret
LIANLIAN_CALLBACK_MAX_AGE_SECONDS=300
IM_AUTH_SECRET=$secret
"@ | Set-Content -LiteralPath $phpGood -Encoding ASCII

@"
DB_HOST=10.0.0.10
DB_PORT=3306
DB_USERNAME=outer_user
DB_PASSWORD=outer_password
DB_DATABASE=outer
DB_TABLE_PREFIX=fb_
IM_HOST=0.0.0.0
IM_PORT=8767
IM_CHAT_TABLE=fb_chat
IM_MAX_TEXT_MESSAGE_LENGTH=5000
IM_MAX_IMAGE_MESSAGE_LENGTH=4096
IM_AUTH_SECRET=$secret
"@ | Set-Content -LiteralPath $imGood -Encoding ASCII

@"
DB_DSN=mysql:host=10.0.0.10;port=3306;dbname=outer
DB_USERNAME=outer_user
DB_PASSWORD=password
DB_TABLE_PREFIX=fb_
YII_ENV=test
YII_DEBUG=true
DEFAULT_STORE_ID=5
DEFAULT_ROUTE=funpay
STORE_PLATFORM_DOMAIN=www.mongoyia.com
WEB_BASE_URL=http://127.0.0.1:8089
MALL_PLATFORM_MODE=1
MALL_PLATFORM_OPERATOR_STORE_IDS=5
REDIS_HOST=10.0.0.11
REDIS_PORT=6379
REDIS_DATABASE=0
UPLOAD_HTTP_PREFIX=http://cdn.example.com/attachment
CHAT_UPLOAD_URL=http://cdn.example.com/chat
IM_WEBSOCKET_URL=ws://127.0.0.1:8767
QPAY_AUTH_BASIC=qpay-basic-token
QPAY_INVOICE_CODE=qpay-invoice-code
QPAY_AUTH_URL=http://127.0.0.1/qpay/auth
QPAY_INVOICE_URL=http://127.0.0.1/qpay/invoice
QPAY_CALLBACK_BASE=http://127.0.0.1:8089
QPAY_CALLBACK_HMAC_SECRET=$secret
QPAY_CALLBACK_MAX_AGE_SECONDS=0
LIANLIAN_SANDBOX=false
LIANLIAN_MERCHANT_ID=lianlian-merchant
LIANLIAN_PUBLIC_KEY=lianlian-public-key
LIANLIAN_PRIVATE_KEY=lianlian-private-key
LIANLIAN_CALLBACK_BASE=http://127.0.0.1:8089
LIANLIAN_CALLBACK_HMAC_SECRET=$secret
LIANLIAN_CALLBACK_MAX_AGE_SECONDS=0
IM_AUTH_SECRET=$secret
"@ | Set-Content -LiteralPath $phpBad -Encoding ASCII

@"
DB_HOST=10.0.0.10
DB_PORT=3306
DB_USERNAME=outer_user
DB_PASSWORD=outer_password
DB_DATABASE=outer
DB_TABLE_PREFIX=fb_
IM_HOST=http://127.0.0.1
IM_PORT=70000
IM_CHAT_TABLE=chat
IM_MAX_TEXT_MESSAGE_LENGTH=20000
IM_MAX_IMAGE_MESSAGE_LENGTH=9000
IM_AUTH_SECRET=bad
"@ | Set-Content -LiteralPath $imBad -Encoding ASCII

& $powerShell -NoProfile -ExecutionPolicy Bypass -File $inputGateScript `
    -PhpEnv $phpGood `
    -ImEnv $imGood `
    -BaseUrl "https://test.mongoyia.local" `
    -ImUrl "wss://test.mongoyia.local/ws" `
    -OutputPath $goodReport `
    -Profile test
$goodExitCode = $LASTEXITCODE
$goodReportText = if (Test-Path -LiteralPath $goodReport) { Get-Content -LiteralPath $goodReport -Raw } else { "" }
if ($goodExitCode -ne 0 -or $goodReportText -notmatch '(?m)^- Result: PASS\r?$') {
    throw "Expected good input-gate smoke to pass."
}

& $powerShell -NoProfile -ExecutionPolicy Bypass -File $inputGateScript `
    -PhpEnv $phpBad `
    -ImEnv $imBad `
    -BaseUrl "http://127.0.0.1:8089" `
    -ImUrl "ws://127.0.0.1:8767" `
    -OutputPath $badReport `
    -Profile test
$badExitCode = $LASTEXITCODE
$badReportText = if (Test-Path -LiteralPath $badReport) { Get-Content -LiteralPath $badReport -Raw } else { "" }
if ($badExitCode -eq 0 -or $badReportText -notmatch '(?m)^- Result: FAIL\r?$') {
    throw "Expected bad input-gate smoke to fail."
}

Remove-Item -LiteralPath $SmokeRoot -Recurse -Force
Write-Output "Mongoyia test-server input-gate smoke: PASS"
exit 0
