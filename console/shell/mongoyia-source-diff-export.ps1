param(
    [string]$OutputDir = "runtime/handover",
    [string]$Stamp = "",
    [string]$PatchPath = "",
    [string]$ReportPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

if ($Stamp -eq "") {
    $Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
}

$OutputRoot = Join-Path $Root $OutputDir
if (!(Test-Path -LiteralPath $OutputRoot)) {
    New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
}
if ($PatchPath -eq "") {
    $PatchPath = Join-Path $OutputRoot "mongoyia-source-tracked-diff-$Stamp.patch"
}
if ($ReportPath -eq "") {
    $ReportPath = Join-Path $OutputRoot "mongoyia-source-diff-export-$Stamp.md"
}

$patchFullPath = if ([System.IO.Path]::IsPathRooted($PatchPath)) { $PatchPath } else { Join-Path $Root $PatchPath }
$reportFullPath = if ([System.IO.Path]::IsPathRooted($ReportPath)) { $ReportPath } else { Join-Path $Root $ReportPath }

$patchDir = Split-Path -Parent $patchFullPath
if (!(Test-Path -LiteralPath $patchDir)) {
    New-Item -ItemType Directory -Path $patchDir -Force | Out-Null
}

$diffOutput = & git diff --binary "--output=$patchFullPath" -- .
if ($LASTEXITCODE -ne 0) {
    throw "git diff --binary failed"
}

$statOutput = @(& git diff --stat -- .)
if ($LASTEXITCODE -ne 0) {
    throw "git diff --stat failed"
}
$statusOutput = @(& git status --short)
if ($LASTEXITCODE -ne 0) {
    throw "git status --short failed"
}

$trackedCount = @($statusOutput | Where-Object { $_.Length -ge 4 -and $_.Substring(0, 2) -ne "??" }).Count
$untrackedCount = @($statusOutput | Where-Object { $_.Length -ge 4 -and $_.Substring(0, 2) -eq "??" -and $_.Substring(3) -notmatch '^runtime[\\\/]' }).Count
$patchHash = (Get-FileHash -LiteralPath $patchFullPath -Algorithm SHA256).Hash.ToLowerInvariant()
$hashPath = "$patchFullPath.sha256"
"$patchHash  $(Split-Path -Leaf $patchFullPath)" | Set-Content -LiteralPath $hashPath -Encoding ASCII

$reportLines = @(
    "# Mongoyia Source Diff Export",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Patch: $patchFullPath",
    "- Patch SHA256: $patchHash",
    "- Patch size: $((Get-Item -LiteralPath $patchFullPath).Length) bytes",
    "- Tracked changed files in git status: $trackedCount",
    "- Untracked non-runtime entries in git status: $untrackedCount",
    "",
    "## Scope",
    "",
    'This patch is generated from `git diff --binary -- .` and only includes modifications to already tracked files.',
    "",
    'It does not include untracked delivery files such as new controllers, migrations, docs, shell scripts, language folders, or generated handover reports. Review `runtime/handover/mongoyia-worktree-inventory-*.md` before creating a final source commit or deployment bundle.',
    "",
    "## Git Diff Stat",
    "",
    '```text'
)
$reportLines += $statOutput
$reportLines += @(
    '```',
    "",
    "## Receiver Notes",
    "",
    "1. Apply this patch only to the same source baseline it was generated from.",
    "2. Review and add required untracked Mongoyia files separately.",
    '3. Do not include real `.env`, SQL dumps, uploaded files, generated `runtime`, or generated `web/assets` content in a source commit.',
    '4. After applying source changes and untracked delivery files, run `php yii mongoyia-package-check/run --interactive=0` and the test-server dry-run.'
)
$reportLines | Set-Content -LiteralPath $reportFullPath -Encoding UTF8

Write-Output "Source tracked diff patch: $patchFullPath"
Write-Output "Source tracked diff checksum: $hashPath"
Write-Output "Source diff export report: $reportFullPath"
