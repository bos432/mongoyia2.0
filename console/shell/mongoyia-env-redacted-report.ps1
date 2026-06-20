param(
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [string]$OutputPath = "",
    [ValidateSet("local", "test", "prod")]
    [string]$Profile = "test"
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

function Is-SecretKey {
    param([string]$Key)
    return $Key -match "(PASSWORD|SECRET|TOKEN|KEY|AUTH|PRIVATE|PUBLIC|BASIC|INVOICE_CODE|CALLBACK_SECRET)"
}

function Is-Placeholder {
    param([string]$Value)
    $lower = $Value.ToLowerInvariant()
    return $lower -eq "" -or
        $lower -like "replace-with-*" -or
        $lower -like "*example.com*" -or
        $lower -like "*placeholder*" -or
        $lower -like "*changeme*" -or
        $lower -like "*change-me*"
}

function Table-Value {
    param([string]$Key, [string]$Value)
    if (Is-SecretKey $Key) {
        if ($Value -eq "") { return "" }
        return "present (redacted)"
    }
    if ($Value.Length -gt 120) {
        return ($Value.Substring(0, 117) + "...")
    }
    return $Value.Replace("|", "\|")
}

function Env-Value {
    param([hashtable]$Env, [string]$Key)
    if ($Env.ContainsKey($Key)) { return [string]$Env[$Key] }
    return ""
}

function Row-State {
    param([hashtable]$Env, [string]$Key)
    if (!$Env.ContainsKey($Key)) { return "MISSING" }
    if ((Env-Value $Env $Key) -eq "") { return "EMPTY" }
    if (Is-Placeholder ([string]$Env[$Key])) { return "PLACEHOLDER" }
    return "SET"
}

function Is-OptionalEmpty {
    param([hashtable]$Env, [string]$Key, [string]$State)
    if ($State -ne "EMPTY") { return $false }
    if ($Key -eq "GOOGLE_TRANSLATE_API_KEY" -and (Env-Value $Env "FRONTEND_TRANSLATE_ENABLED").ToLowerInvariant() -in @("false", "0", "no", "")) {
        return $true
    }
    return $false
}

function Add-EnvRows {
    param([string]$Source, [hashtable]$Env, [string[]]$Keys)
    foreach ($key in $Keys) {
        $state = Row-State $Env $key
        $warningState = $state
        if (Is-OptionalEmpty $Env $key $state) {
            $state = "OPTIONAL_EMPTY"
            $warningState = "SET"
        }
        $value = if ($Env.ContainsKey($key)) { Table-Value $key ([string]$Env[$key]) } else { "" }
        $script:rows += "| $Source | $key | $state | $value |"
        if ($warningState -ne "SET") { $script:warnings++ }
    }
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
$php = Read-EnvFile $phpEnvPath
$im = Read-EnvFile $imEnvPath

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-env-redacted-report-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$phpKeys = @(
    "DB_DSN", "DB_USERNAME", "DB_PASSWORD", "DB_TABLE_PREFIX",
    "YII_DEBUG", "YII_ENV", "DEFAULT_STORE_ID", "DEFAULT_ROUTE",
    "STORE_PLATFORM_DOMAIN", "WEB_BASE_URL", "MALL_PLATFORM_MODE", "MALL_PLATFORM_OPERATOR_STORE_IDS",
    "REDIS_HOST", "REDIS_PORT", "REDIS_DATABASE",
    "UPLOAD_HTTP_PREFIX", "CHAT_UPLOAD_URL",
    "IM_WEBSOCKET_URL", "IM_AUTH_SECRET", "IM_AUTH_TOKEN_TTL",
    "QPAY_AUTH_BASIC", "QPAY_INVOICE_CODE", "QPAY_AUTH_URL", "QPAY_INVOICE_URL", "QPAY_CALLBACK_BASE", "QPAY_CALLBACK_HMAC_SECRET",
    "LIANLIAN_SANDBOX", "LIANLIAN_MERCHANT_ID", "LIANLIAN_PUBLIC_KEY", "LIANLIAN_PRIVATE_KEY", "LIANLIAN_CALLBACK_BASE", "LIANLIAN_CALLBACK_HMAC_SECRET",
    "GOOGLE_TRANSLATE_API_KEY", "GOOGLE_TRANSLATE_PROXY", "FRONTEND_TRANSLATE_ENABLED", "MALL_TRANSLATE_TARGETS"
)
$imKeys = @(
    "DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD", "DB_DATABASE", "DB_TABLE_PREFIX",
    "IM_CHAT_TABLE", "IM_HOST", "IM_PORT", "IM_AUTH_SECRET", "IM_MAX_TEXT_MESSAGE_LENGTH", "IM_MAX_IMAGE_MESSAGE_LENGTH"
)

$script:rows = @()
$script:warnings = 0
Add-EnvRows "PHP" $php $phpKeys
Add-EnvRows "Python IM" $im $imKeys

$checks = @()
if ($php.Count -eq 0) {
    $checks += "- WARN PHP env file missing or empty: $phpEnvPath"
    $warnings++
}
if ($im.Count -eq 0) {
    $checks += "- WARN Python IM env file missing or empty: $imEnvPath"
    $warnings++
}

if ((Env-Value $php "IM_AUTH_SECRET") -ne "" -and (Env-Value $im "IM_AUTH_SECRET") -ne "") {
    if ($php["IM_AUTH_SECRET"] -eq $im["IM_AUTH_SECRET"]) {
        $checks += "- PASS PHP and Python IM auth secrets match."
    } else {
        $checks += "- WARN PHP and Python IM auth secrets do not match."
        $warnings++
    }
}

$dsn = Mysql-Dsn-Parts (Env-Value $php "DB_DSN")
if ($dsn.Count -gt 0 -and $im.Count -gt 0) {
    $dbMismatches = @()
    $dsnHost = if ($dsn.ContainsKey("host")) { [string]$dsn["host"] } else { "" }
    $dsnPort = if ($dsn.ContainsKey("port")) { [string]$dsn["port"] } else { "3306" }
    $dsnDb = if ($dsn.ContainsKey("dbname")) { [string]$dsn["dbname"] } else { "" }
    if ($dsnHost -ne "" -and (Env-Value $im "DB_HOST") -ne "" -and $dsnHost -ne (Env-Value $im "DB_HOST")) { $dbMismatches += "host" }
    if ((Env-Value $im "DB_PORT") -ne "" -and $dsnPort -ne (Env-Value $im "DB_PORT")) { $dbMismatches += "port" }
    if ($dsnDb -ne "" -and (Env-Value $im "DB_DATABASE") -ne "" -and $dsnDb -ne (Env-Value $im "DB_DATABASE")) { $dbMismatches += "database" }
    if ((Env-Value $php "DB_USERNAME") -ne "" -and (Env-Value $im "DB_USERNAME") -ne "" -and (Env-Value $php "DB_USERNAME") -ne (Env-Value $im "DB_USERNAME")) { $dbMismatches += "username" }
    if ($dbMismatches.Count -eq 0) {
        $checks += "- PASS PHP and Python IM database settings match."
    } else {
        $checks += "- WARN PHP and Python IM database settings differ: $($dbMismatches -join ', ')."
        $warnings++
    }
}

if ($Profile -in @("test", "prod")) {
    foreach ($key in @("STORE_PLATFORM_DOMAIN", "WEB_BASE_URL", "IM_WEBSOCKET_URL", "QPAY_CALLBACK_BASE", "LIANLIAN_CALLBACK_BASE")) {
        if ((Env-Value $php $key) -like "*example.com*") {
            $checks += "- WARN $key still uses example.com."
            $warnings++
        }
    }
}

$result = if ($warnings -eq 0) { "PASS" } else { "WARN" }
$generatedAt = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
$report = @(
    "# Mongoyia Environment Redacted Report",
    "",
    "- Result: $result",
    "- Warnings: $warnings",
    "- Generated at: $generatedAt",
    "- Profile: $Profile",
    "- PHP env: $phpEnvPath",
    "- Python IM env: $imEnvPath",
    "",
    "This report is safe to share for handover review. Secrets, passwords, tokens, private keys, public keys, auth values, and provider credentials are redacted.",
    "",
    "## Key Status",
    "",
    "| Source | Key | State | Value |",
    "|---|---|---:|---|"
) + $rows + @(
    "",
    "## Cross Checks",
    ""
) + $checks

$report | Set-Content -LiteralPath $outputFull -Encoding UTF8

Write-Output "Environment redacted report: $outputFull"
Write-Output "Result: $result ($warnings warning(s))"
