param(
    [string]$Php = "php",
    [string]$PhpEnv = ".env",
    [string]$ImEnv = "../../im后端/im后端/.env",
    [switch]$SkipConnectivity
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

$argsList = @(
    "yii",
    "deploy-check/run",
    "--profile=test",
    "--strict=1",
    "--phpEnv=$PhpEnv",
    "--imEnv=$ImEnv",
    "--skipConnectivity=$([int]$SkipConnectivity.IsPresent)",
    "--interactive=0"
)

Write-Output "Running Mongoyia test profile preflight from $Root"
Write-Output "$Php $($argsList -join ' ')"
& $Php @argsList
exit $LASTEXITCODE
