param(
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [string]$BaseUrl = "",
    [string]$ImUrl = "",
    [string]$DeliveryArchivePath = "",
    [string]$SqlDumpPath = "",
    [string]$SqlChecksumPath = "",
    [string]$ExpectedSqlSha256 = "",
    [string]$Database = "outer",
    [string]$BackupReference = "",
    [string]$BackupArtifactPath = "",
    [string]$BackupChecksumPath = "",
    [string]$ExpectedBackupSha256 = "",
    [string]$OutputPath = "",
    [ValidateSet("test", "prod")]
    [string]$Profile = "test",
    [switch]$RequireRestoreInputs,
    [switch]$AllowProductionDomainForTest
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

function Read-EnvFile {
    param([string]$Path)
    $env = @{}
    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) {
        return $env
    }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trim = $line.Trim()
        if ($trim -eq "" -or $trim.StartsWith("#") -or $trim -notmatch "^[A-Za-z_][A-Za-z0-9_]*=") {
            continue
        }
        $parts = $trim -split "=", 2
        $value = $parts[1].Trim()
        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $env[$parts[0]] = $value
    }
    return $env
}

function Env-Value {
    param([hashtable]$Env, [string]$Key)
    if ($Env.ContainsKey($Key)) { return [string]$Env[$Key] }
    return ""
}

function Is-Placeholder {
    param([string]$Value)
    $lower = $Value.ToLowerInvariant()
    return $lower -eq "" -or
        $lower -eq "password" -or
        $lower -like "replace-with-*" -or
        $lower -like "*example.com*" -or
        $lower -like "*placeholder*" -or
        $lower -like "*changeme*" -or
        $lower -like "*change-me*" -or
        $lower -like "*your-*"
}

function Add-Fail {
    param([string]$Message)
    $script:failures++
    $script:checks += "- FAIL $Message"
}

function Add-Pass {
    param([string]$Message)
    $script:checks += "- PASS $Message"
}

function Require-File {
    param([string]$Label, [string]$Path)
    if ($Path -eq "") {
        Add-Fail "$Label is required."
        return ""
    }
    $resolved = Resolve-ProjectPath $Path
    if (!(Test-Path -LiteralPath $resolved -PathType Leaf)) {
        Add-Fail "$Label does not exist: $resolved"
        return ""
    }
    Add-Pass "$Label exists."
    return $resolved
}

function Read-Sha256 {
    param([string]$Path)
    return ((Get-Content -LiteralPath $Path -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
}

function Check-Sha256 {
    param([string]$Label, [string]$ArtifactPath, [string]$ChecksumPath, [string]$ExpectedSha256)
    if ($ArtifactPath -eq "") { return }
    if (!(Test-Path -LiteralPath $ArtifactPath -PathType Leaf)) {
        Add-Fail "$Label file does not exist for SHA256 check: $ArtifactPath"
        return
    }
    $actual = (Get-FileHash -LiteralPath $ArtifactPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $expected = $ExpectedSha256
    if ($ChecksumPath -ne "") {
        if (!(Test-Path -LiteralPath $ChecksumPath -PathType Leaf)) {
            Add-Fail "$Label checksum file does not exist for SHA256 check: $ChecksumPath"
            return
        }
        $expected = Read-Sha256 $ChecksumPath
    }
    if ($expected -eq "") {
        Add-Fail "$Label SHA256 expectation is required."
        return
    }
    if ($actual -ne $expected.ToLowerInvariant()) {
        Add-Fail "$Label SHA256 mismatch. expected=$expected actual=$actual"
        return
    }
    Add-Pass "$Label SHA256 matches."
}

function Require-Key {
    param([string]$Source, [hashtable]$Env, [string]$Key)
    $value = Env-Value $Env $Key
    if ($value -eq "") {
        Add-Fail "$Source $Key is missing or empty."
        return
    }
    if (Is-Placeholder $value) {
        Add-Fail "$Source $Key still looks like a placeholder."
        return
    }
    Add-Pass "$Source $Key is set."
}

function Require-Url {
    param([string]$Source, [string]$Key, [string]$Value, [string]$Scheme)
    if ($Value -eq "") {
        Add-Fail "$Source $Key is missing or empty."
        return
    }
    if (Is-Placeholder $Value) {
        Add-Fail "$Source $Key still looks like a placeholder."
        return
    }
    if (!$Value.StartsWith("${Scheme}://")) {
        Add-Fail "$Source $Key must use ${Scheme}://."
        return
    }
    if ($Value -match "localhost|127\.0\.0\.1|0\.0\.0\.0") {
        Add-Fail "$Source $Key must not point to a local-only host on test/prod."
        return
    }
    Add-Pass "$Source $Key uses $Scheme and is not local."
}

function Production-Domain-Host {
    param([string]$Value)
    if ($Value -eq "") { return "" }
    $candidate = $Value.Trim()
    $domainHost = ""
    try {
        if ($candidate -match "^[a-z][a-z0-9+.-]*://") {
            $uri = [System.Uri]$candidate
            $domainHost = $uri.Host
        } else {
            $domainHost = ($candidate -split "/", 2)[0]
            $domainHost = ($domainHost -split ":", 2)[0]
        }
    } catch {
        $domainHost = $candidate
    }
    $domainHost = $domainHost.Trim().TrimEnd(".").ToLowerInvariant()
    if ($domainHost -in @("mongoyia.com", "www.mongoyia.com")) { return $domainHost }
    return ""
}

function Env-Host {
    param([string]$Value)
    if ($Value -eq "") { return "" }
    $candidate = $Value.Trim()
    try {
        if ($candidate -match "^[a-z][a-z0-9+.-]*://") {
            $uri = [System.Uri]$candidate
            return $uri.Host.Trim().TrimEnd(".").ToLowerInvariant()
        }
    } catch {
        return ""
    }
    $parsedHost = ($candidate -split "/", 2)[0]
    $parsedHost = ($parsedHost -split ":", 2)[0]
    return $parsedHost.Trim().TrimEnd(".").ToLowerInvariant()
}

function Check-Production-Domain {
    param([string]$Source, [string]$Key, [string]$Value)
    if ($Profile -ne "test") { return }
    $domainHost = Production-Domain-Host $Value
    if ($domainHost -eq "") {
        Add-Pass "$Source $Key does not point to the production domain."
        return
    }
    if ($script:allowProductionDomain) {
        Add-Pass "$Source $Key points to production domain $domainHost, allowed by explicit override."
    } else {
        Add-Fail "$Source $Key points to production domain $domainHost. Use a test domain, or pass -AllowProductionDomainForTest only for an intentional exception."
    }
}

function Require-RelativeOrHttpsUrl {
    param([string]$Source, [string]$Key, [string]$Value, [string]$PlatformHost)
    if ($Value -eq "") {
        Add-Fail "$Source $Key is missing or empty."
        return
    }
    if ($Value.StartsWith("/")) {
        Add-Pass "$Source $Key is root-relative."
        return
    }
    if (Is-Placeholder $Value) {
        Add-Fail "$Source $Key still looks like a placeholder."
        return
    }
    if (!$Value.StartsWith("https://")) {
        Add-Fail "$Source $Key must be root-relative or use https:// on test/prod."
        return
    }
    $host = Env-Host $Value
    if ($host -eq "") {
        Add-Fail "$Source $Key must be root-relative or an absolute https URL."
        return
    }
    if ($PlatformHost -ne "" -and $host -ne $PlatformHost) {
        Add-Fail "$Source $Key host must match STORE_PLATFORM_DOMAIN ($PlatformHost)."
        return
    }
    Add-Pass "$Source $Key is an allowed URL."
}

function Require-Secret {
    param([string]$Source, [hashtable]$Env, [string]$Key, [int]$MinLength = 32)
    $value = Env-Value $Env $Key
    if ($value -eq "") {
        Add-Fail "$Source $Key is missing or empty."
        return
    }
    if (Is-Placeholder $value) {
        Add-Fail "$Source $Key still looks like a placeholder."
        return
    }
    if ($value.Length -lt $MinLength) {
        Add-Fail "$Source $Key must be at least $MinLength characters."
        return
    }
    Add-Pass "$Source $Key is present and long enough."
}

function Require-PositiveInt {
    param([string]$Source, [hashtable]$Env, [string]$Key, [int64]$Max = 0)
    $value = Env-Value $Env $Key
    if ($value -eq "") {
        Add-Fail "$Source $Key is missing or empty."
        return
    }
    if ($value -notmatch "^[0-9]+$" -or [int64]$value -le 0) {
        Add-Fail "$Source $Key must be a positive integer."
        return
    }
    if ($Max -gt 0 -and [int64]$value -gt $Max) {
        Add-Fail "$Source $Key must be less than or equal to $Max."
        return
    }
    Add-Pass "$Source $Key is a positive integer."
}

function Require-BindHost {
    param([string]$Source, [hashtable]$Env, [string]$Key)
    $value = (Env-Value $Env $Key).Trim()
    if ($value -eq "") {
        Add-Fail "$Source $Key is missing or empty."
        return
    }
    if (Is-Placeholder $value) {
        Add-Fail "$Source $Key still looks like a placeholder."
        return
    }
    if ($value -match "^[a-z][a-z0-9+\-.]*://" -or $value.Contains("/") -or $value.Contains("?") -or $value.Contains("#")) {
        Add-Fail "$Source $Key must be a bind host such as 0.0.0.0 or 127.0.0.1, not a URL."
        return
    }
    Add-Pass "$Source $Key is a bind host."
}

function Mysql-Dsn-Parts {
    param([string]$Dsn)
    $parts = @{}
    if ($Dsn -notmatch "^mysql:") { return $parts }
    foreach ($part in $Dsn.Substring(6).Split(";")) {
        if ($part -match "=") {
            $kv = $part -split "=", 2
            $parts[$kv[0]] = $kv[1]
        }
    }
    return $parts
}

$phpEnvPath = Resolve-ProjectPath $PhpEnv
$imEnvPath = Resolve-ProjectPath $ImEnv
$deliveryPath = Resolve-ProjectPath $DeliveryArchivePath
$sqlDumpPath = Resolve-ProjectPath $SqlDumpPath
$sqlChecksumPath = Resolve-ProjectPath $SqlChecksumPath
$backupArtifactPath = Resolve-ProjectPath $BackupArtifactPath
$backupChecksumPath = Resolve-ProjectPath $BackupChecksumPath
$php = Read-EnvFile $phpEnvPath
$im = Read-EnvFile $imEnvPath
$script:checks = @()
$script:failures = 0
$script:allowProductionDomain = $AllowProductionDomainForTest.IsPresent -or ($env:ALLOW_PRODUCTION_DOMAIN_FOR_TEST -in @("1", "true", "TRUE", "yes", "YES"))

if ($php.Count -eq 0) { Add-Fail "PHP env file is missing or empty: $phpEnvPath" } else { Add-Pass "PHP env file is readable." }
if ($im.Count -eq 0) { Add-Fail "Python IM env file is missing or empty: $imEnvPath" } else { Add-Pass "Python IM env file is readable." }

if ($RequireRestoreInputs.IsPresent) {
    $deliveryPath = Require-File "Delivery archive" $DeliveryArchivePath
    $sqlDumpPath = Require-File "SQL dump" $SqlDumpPath
    $sqlChecksumPath = Require-File "SQL checksum sidecar" $SqlChecksumPath
    if ($Database -eq "" -or $Database.ToLowerInvariant() -in @("mysql", "information_schema", "performance_schema", "sys")) {
        Add-Fail "Target database must be a non-system database name, usually outer."
    } else {
        Add-Pass "Target database name is allowed."
    }
    if ($BackupReference -eq "" -and $BackupArtifactPath -eq "") {
        Add-Fail "BackupReference or BackupArtifactPath is required before restore apply."
    } else {
        Add-Pass "Backup reference or artifact is present."
    }
    if ($BackupArtifactPath -ne "") {
        $backupArtifactPath = Require-File "Backup artifact" $BackupArtifactPath
    }
    if ($BackupChecksumPath -ne "") {
        $backupChecksumPath = Require-File "Backup checksum sidecar" $BackupChecksumPath
    }
    if ($sqlDumpPath -ne "" -and $sqlChecksumPath -ne "") {
        Check-Sha256 "SQL dump" $sqlDumpPath $sqlChecksumPath $ExpectedSqlSha256
    }
    if ($backupArtifactPath -ne "" -and ($backupChecksumPath -ne "" -or $ExpectedBackupSha256 -ne "")) {
        Check-Sha256 "Backup artifact" $backupArtifactPath $backupChecksumPath $ExpectedBackupSha256
    }
}

foreach ($key in @("DB_DSN", "DB_USERNAME", "DB_PASSWORD", "DB_TABLE_PREFIX", "YII_ENV", "YII_DEBUG", "DEFAULT_STORE_ID", "DEFAULT_ROUTE", "STORE_PLATFORM_DOMAIN", "WEB_BASE_URL", "MALL_PLATFORM_MODE", "MALL_PLATFORM_OPERATOR_STORE_IDS", "REDIS_HOST", "REDIS_PORT", "REDIS_DATABASE", "UPLOAD_HTTP_PREFIX", "CHAT_UPLOAD_URL", "IM_WEBSOCKET_URL")) {
    Require-Key "PHP" $php $key
}
foreach ($key in @("QPAY_AUTH_BASIC", "QPAY_INVOICE_CODE", "QPAY_AUTH_URL", "QPAY_INVOICE_URL", "QPAY_CALLBACK_BASE", "LIANLIAN_SANDBOX", "LIANLIAN_MERCHANT_ID", "LIANLIAN_PUBLIC_KEY", "LIANLIAN_PRIVATE_KEY", "LIANLIAN_CALLBACK_BASE")) {
    Require-Key "PHP" $php $key
}
Require-Secret "PHP" $php "IM_AUTH_SECRET" 32
Require-Secret "PHP" $php "QPAY_CALLBACK_HMAC_SECRET" 32
Require-Secret "PHP" $php "LIANLIAN_CALLBACK_HMAC_SECRET" 32
Require-PositiveInt "PHP" $php "QPAY_CALLBACK_MAX_AGE_SECONDS"
Require-PositiveInt "PHP" $php "LIANLIAN_CALLBACK_MAX_AGE_SECONDS"

foreach ($key in @("DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD", "DB_DATABASE", "DB_TABLE_PREFIX", "IM_HOST", "IM_PORT", "IM_CHAT_TABLE", "IM_MAX_TEXT_MESSAGE_LENGTH", "IM_MAX_IMAGE_MESSAGE_LENGTH")) {
    Require-Key "Python IM" $im $key
}
Require-Secret "Python IM" $im "IM_AUTH_SECRET" 32
Require-PositiveInt "Python IM" $im "IM_PORT" 65535
Require-PositiveInt "Python IM" $im "IM_MAX_TEXT_MESSAGE_LENGTH" 10000
Require-PositiveInt "Python IM" $im "IM_MAX_IMAGE_MESSAGE_LENGTH" 8192

$yiiDebug = (Env-Value $php "YII_DEBUG").ToLowerInvariant()
if ($yiiDebug -in @("false", "0", "no")) { Add-Pass "PHP YII_DEBUG is disabled." } else { Add-Fail "PHP YII_DEBUG must be false/0/no on $Profile." }

$yiiEnv = (Env-Value $php "YII_ENV").ToLowerInvariant()
if ($yiiEnv -eq $Profile) { Add-Pass "PHP YII_ENV matches $Profile." } else { Add-Fail "PHP YII_ENV must be $Profile." }

if ((Env-Value $php "DEFAULT_ROUTE") -eq "mall") { Add-Pass "PHP DEFAULT_ROUTE is mall." } else { Add-Fail "PHP DEFAULT_ROUTE must be mall." }
if ($Profile -eq "test" -and (Env-Value $php "LIANLIAN_SANDBOX") -ne "true") {
    Add-Fail "PHP LIANLIAN_SANDBOX must be true for test profile."
} elseif ($Profile -eq "prod" -and (Env-Value $php "LIANLIAN_SANDBOX") -eq "true") {
    Add-Fail "PHP LIANLIAN_SANDBOX must be false for prod profile."
} else {
    Add-Pass "PHP LIANLIAN_SANDBOX is compatible with $Profile profile."
}
Require-BindHost "Python IM" $im "IM_HOST"

Require-Url "PHP" "WEB_BASE_URL" (Env-Value $php "WEB_BASE_URL") "https"
Require-Url "PHP" "IM_WEBSOCKET_URL" (Env-Value $php "IM_WEBSOCKET_URL") "wss"
Require-Url "PHP" "QPAY_AUTH_URL" (Env-Value $php "QPAY_AUTH_URL") "https"
Require-Url "PHP" "QPAY_INVOICE_URL" (Env-Value $php "QPAY_INVOICE_URL") "https"
Require-Url "PHP" "QPAY_CALLBACK_BASE" (Env-Value $php "QPAY_CALLBACK_BASE") "https"
Require-Url "PHP" "LIANLIAN_CALLBACK_BASE" (Env-Value $php "LIANLIAN_CALLBACK_BASE") "https"
Require-Url "Argument" "BaseUrl" $BaseUrl "https"
Require-Url "Argument" "ImUrl" $ImUrl "wss"

foreach ($item in @(
    @("PHP", "STORE_PLATFORM_DOMAIN", (Env-Value $php "STORE_PLATFORM_DOMAIN")),
    @("PHP", "WEB_BASE_URL", (Env-Value $php "WEB_BASE_URL")),
    @("PHP", "IM_WEBSOCKET_URL", (Env-Value $php "IM_WEBSOCKET_URL")),
    @("PHP", "QPAY_CALLBACK_BASE", (Env-Value $php "QPAY_CALLBACK_BASE")),
    @("PHP", "LIANLIAN_CALLBACK_BASE", (Env-Value $php "LIANLIAN_CALLBACK_BASE")),
    @("Argument", "BaseUrl", $BaseUrl),
    @("Argument", "ImUrl", $ImUrl)
)) {
    Check-Production-Domain $item[0] $item[1] $item[2]
}

$platformHost = Env-Host (Env-Value $php "STORE_PLATFORM_DOMAIN")
$webBaseHost = Env-Host (Env-Value $php "WEB_BASE_URL")
if ($platformHost -ne "" -and $webBaseHost -ne "" -and $platformHost -eq $webBaseHost) {
    Add-Pass "PHP WEB_BASE_URL host matches STORE_PLATFORM_DOMAIN."
} else {
    Add-Fail "PHP WEB_BASE_URL host must match STORE_PLATFORM_DOMAIN."
}
$phpImHost = Env-Host (Env-Value $php "IM_WEBSOCKET_URL")
if ($platformHost -ne "" -and $phpImHost -ne "" -and $phpImHost -eq $platformHost) {
    Add-Pass "PHP IM_WEBSOCKET_URL host matches STORE_PLATFORM_DOMAIN."
} else {
    Add-Fail "PHP IM_WEBSOCKET_URL host must match STORE_PLATFORM_DOMAIN."
}
$argumentBaseHost = Env-Host $BaseUrl
if ($platformHost -ne "" -and $argumentBaseHost -ne "" -and $argumentBaseHost -eq $platformHost) {
    Add-Pass "Argument BaseUrl host matches STORE_PLATFORM_DOMAIN."
} else {
    Add-Fail "Argument BaseUrl host must match STORE_PLATFORM_DOMAIN."
}
$argumentImHost = Env-Host $ImUrl
if ($platformHost -ne "" -and $argumentImHost -ne "" -and $argumentImHost -eq $platformHost) {
    Add-Pass "Argument ImUrl host matches STORE_PLATFORM_DOMAIN."
} else {
    Add-Fail "Argument ImUrl host must match STORE_PLATFORM_DOMAIN."
}
foreach ($callbackKey in @("QPAY_CALLBACK_BASE", "LIANLIAN_CALLBACK_BASE")) {
    $callbackHost = Env-Host (Env-Value $php $callbackKey)
    if ($platformHost -ne "" -and $callbackHost -ne "" -and $callbackHost -eq $platformHost) {
        Add-Pass "PHP $callbackKey host matches STORE_PLATFORM_DOMAIN."
    } else {
        Add-Fail "PHP $callbackKey host must match STORE_PLATFORM_DOMAIN."
    }
}
Require-RelativeOrHttpsUrl "PHP" "CHAT_UPLOAD_URL" (Env-Value $php "CHAT_UPLOAD_URL") $platformHost
Require-RelativeOrHttpsUrl "PHP" "UPLOAD_HTTP_PREFIX" (Env-Value $php "UPLOAD_HTTP_PREFIX") $platformHost

if ((Env-Value $php "IM_AUTH_SECRET") -ne "" -and (Env-Value $im "IM_AUTH_SECRET") -ne "") {
    if ((Env-Value $php "IM_AUTH_SECRET") -eq (Env-Value $im "IM_AUTH_SECRET")) {
        Add-Pass "PHP and Python IM_AUTH_SECRET match."
    } else {
        Add-Fail "PHP and Python IM_AUTH_SECRET must match."
    }
}

$expectedChatTable = (Env-Value $php "DB_TABLE_PREFIX") + "chat"
if ((Env-Value $im "IM_CHAT_TABLE") -ne "" -and (Env-Value $php "DB_TABLE_PREFIX") -ne "") {
    if ((Env-Value $im "IM_CHAT_TABLE") -eq $expectedChatTable) {
        Add-Pass "Python IM_CHAT_TABLE matches PHP DB_TABLE_PREFIX + chat."
    } else {
        Add-Fail "Python IM_CHAT_TABLE must equal PHP DB_TABLE_PREFIX + chat ($expectedChatTable)."
    }
}

$dsn = Mysql-Dsn-Parts (Env-Value $php "DB_DSN")
if ($dsn.Count -eq 0) {
    Add-Fail "PHP DB_DSN must be a mysql DSN."
} else {
    $dsnHost = if ($dsn.ContainsKey("host")) { [string]$dsn["host"] } else { "" }
    $dsnPort = if ($dsn.ContainsKey("port")) { [string]$dsn["port"] } else { "3306" }
    $dsnDb = if ($dsn.ContainsKey("dbname")) { [string]$dsn["dbname"] } else { "" }
    if ($dsnHost -ne "" -and (Env-Value $im "DB_HOST") -ne "" -and $dsnHost -ne (Env-Value $im "DB_HOST")) { Add-Fail "PHP and Python IM database host differ." }
    if ((Env-Value $im "DB_PORT") -ne "" -and $dsnPort -ne (Env-Value $im "DB_PORT")) { Add-Fail "PHP and Python IM database port differ." }
    if ($dsnDb -ne "" -and (Env-Value $im "DB_DATABASE") -ne "" -and $dsnDb -ne (Env-Value $im "DB_DATABASE")) { Add-Fail "PHP and Python IM database name differ." }
    if ((Env-Value $php "DB_USERNAME") -ne "" -and (Env-Value $im "DB_USERNAME") -ne "" -and (Env-Value $php "DB_USERNAME") -ne (Env-Value $im "DB_USERNAME")) { Add-Fail "PHP and Python IM database username differ." }
}

$result = if ($failures -eq 0) { "PASS" } else { "FAIL" }
if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-test-server-input-gate-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$report = @(
    "# Mongoyia Test Server Input Gate",
    "",
    "- Result: $result",
    "- Failures: $failures",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Profile: $Profile",
    "- Allow production domain override: $script:allowProductionDomain",
    "- PHP env: $phpEnvPath",
    "- Python IM env: $imEnvPath",
    "- Base URL: $BaseUrl",
    "- IM URL: $ImUrl",
    "- Require restore inputs: $($RequireRestoreInputs.IsPresent)",
    "- Delivery archive: $deliveryPath",
    "- SQL dump: $sqlDumpPath",
    "- SQL checksum: $sqlChecksumPath",
    "- Database: $Database",
    "- Backup artifact: $backupArtifactPath",
    "- Backup reference: $BackupReference",
    "",
    "Secrets are not printed in this report. This gate is intended to run before a real test-server restore with Apply enabled.",
    "",
    "## Checks",
    ""
) + $checks
$report | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Test-server input gate: $result"
Write-Output "Failures: $failures"
Write-Output "Report: $outputFull"

if ($failures -gt 0) {
    exit 1
}
