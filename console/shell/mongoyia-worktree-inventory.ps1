param(
    [string]$OutputPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $Root

$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
if ($OutputPath -eq "") {
    $OutputPath = "runtime/handover/mongoyia-worktree-inventory-$Stamp.md"
}

function Git-Lines {
    param([string[]]$ArgsList)
    $output = & git @ArgsList
    if ($LASTEXITCODE -ne 0) {
        throw "git $($ArgsList -join ' ') failed"
    }
    return @($output)
}

$statusLines = Git-Lines @("status", "--short")
$trackedModified = @()
$untracked = @()
$runtimeGenerated = @()
$handoverGenerated = @()

foreach ($line in $statusLines) {
    if ($line.Length -lt 4) {
        continue
    }
    $code = $line.Substring(0, 2)
    $path = $line.Substring(3)

    if ($path -like "runtime/*" -or $path -like "runtime\*") {
        $runtimeGenerated += $line
        if ($path -like "runtime/handover/*" -or $path -like "runtime\handover\*") {
            $handoverGenerated += $line
        }
        continue
    }

    if ($code -eq "??") {
        $untracked += $line
    } else {
        $trackedModified += $line
    }
}

$outputFullPath = Join-Path $Root $OutputPath
$outputDir = Split-Path -Parent $outputFullPath
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$lines = @(
    "# Mongoyia Worktree Inventory",
    "",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Tracked modified/deleted/renamed files: $($trackedModified.Count)",
    "- Untracked non-runtime files/directories: $($untracked.Count)",
    "- Runtime/generated entries: $($runtimeGenerated.Count)",
    "- Handover generated entries: $($handoverGenerated.Count)",
    "",
    "## Important Scope Note",
    "",
    'The `runtime/handover/mongoyia-handover-*.zip` archive is a handover documentation, scripts, templates, and report bundle. It is not a complete deployable source archive.',
    "",
    "For source-code handover, pass the full working tree or create a proper Git commit/patch after reviewing this inventory. The worktree was already dirty before the later handover packaging work, so treat this report as an inventory for review rather than proof that every listed file was changed in the final packaging phase.",
    "",
    "## Tracked Modified Files",
    "",
    '```text'
)
$lines += $trackedModified
$lines += @(
    '```',
    "",
    "## Untracked Non-Runtime Files And Directories",
    "",
    '```text'
)
$lines += $untracked
$lines += @(
    '```',
    "",
    "## Runtime And Generated Entries",
    "",
    '```text'
)
$lines += $runtimeGenerated
$lines += @(
    '```',
    "",
    "## Suggested Receiver Review Order",
    "",
    '1. Read `docs/mongoyia-change-index.md` for the functional map.',
    "2. Review tracked modified files first because they patch existing application behavior.",
    "3. Review untracked controllers, migrations, helper/model files, docs, and shell scripts that are part of Mongoyia delivery.",
    '4. Treat `runtime/handover/*` and `runtime/acceptance/*` as generated evidence, not deploy source.',
    '5. Do not commit real `.env`, database dumps, uploaded files, or generated web assets.'
)

$lines | Set-Content -LiteralPath $outputFullPath -Encoding UTF8
Write-Output "Worktree inventory report: $outputFullPath"
