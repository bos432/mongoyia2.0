param(
    [string]$OutputPath = "",
    [string]$HandoverDir = "runtime/handover",
    [string]$AcceptanceDir = "runtime/acceptance",
    [string]$SqlDumpPath = "../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql",
    [string]$SqlChecksumPath = "runtime/handover/outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql.sha256",
    [switch]$ValidateDelivery
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

function Latest-File {
    param([string]$Pattern)
    $dir = Resolve-ProjectPath $HandoverDir
    $file = Get-ChildItem -Path $dir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return $null }
    return $file
}

function Latest-StatusFile {
    param([string]$DirectoryPattern, [string]$FileName)
    $dir = Resolve-ProjectPath $HandoverDir
    $folder = Get-ChildItem -Path $dir -Directory -Filter $DirectoryPattern -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $folder) { return $null }
    $file = Join-Path $folder.FullName $FileName
    if (!(Test-Path -LiteralPath $file -PathType Leaf)) { return $null }
    return Get-Item -LiteralPath $file
}

function Latest-NonSmokeFile {
    param([string]$Pattern)
    $dir = Resolve-ProjectPath $HandoverDir
    $file = Get-ChildItem -Path $dir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -notmatch '(smoke|expected)' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return $null }
    return $file
}

function Latest-AcceptanceFile {
    param([string]$Pattern)
    $dir = Resolve-ProjectPath $AcceptanceDir
    $file = Get-ChildItem -Path $dir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($null -eq $file) { return $null }
    return $file
}

function Read-FirstHash {
    param([string]$Path)
    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { return "" }
    return ((Get-Content -LiteralPath $Path -TotalCount 1) -split '\s+')[0].ToLowerInvariant()
}

function Sha-State {
    param([string]$FilePath, [string]$ChecksumPath)
    if ($FilePath -eq "" -or !(Test-Path -LiteralPath $FilePath -PathType Leaf)) { return @("MISSING", "") }
    if ($ChecksumPath -eq "" -or !(Test-Path -LiteralPath $ChecksumPath -PathType Leaf)) { return @("NO_CHECKSUM", "") }
    $expected = Read-FirstHash $ChecksumPath
    $actual = (Get-FileHash -LiteralPath $FilePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($expected -eq $actual) { return @("PASS", $actual) }
    return @("MISMATCH", $actual)
}

function Read-ReportResult {
    param([string]$Path)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) { return "MISSING" }
    $text = Get-Content -LiteralPath $Path -Raw
    $match = [regex]::Match($text, '(?m)^-\s*Result:\s*(.+?)\r?$')
    if ($match.Success) { return $match.Groups[1].Value.Trim() }
    $modeMatch = [regex]::Match($text, '(?m)^-\s*Mode:\s*DRY-RUN\s*\r?$')
    if ($modeMatch.Success) { return "DRY_RUN" }
    $statusMatches = [regex]::Matches($text, '(?m)^-\s*Status:\s*(.+?)\r?$')
    if ($statusMatches.Count -gt 0) {
        $statuses = @($statusMatches | ForEach-Object { $_.Groups[1].Value.Trim() })
        if (@($statuses | Where-Object { $_ -ne "PASS" -and $_ -ne "DRY-RUN" }).Count -eq 0) {
            if ($statuses -contains "DRY-RUN") { return "DRY_RUN" }
            return "PASS"
        }
    }
    if ($text -match 'PASS') { return "PASS" }
    return "UNKNOWN"
}

function Read-ReportProfile {
    param([string]$Path)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) { return "" }
    $text = Get-Content -LiteralPath $Path -Raw
    $match = [regex]::Match($text, '(?m)^-\s*Profile:\s*(.+?)\r?$')
    if ($match.Success) { return $match.Groups[1].Value.Trim().ToLowerInvariant() }
    return ""
}

function Read-ReportStrict {
    param([string]$Path)
    if ($Path -eq "" -or !(Test-Path -LiteralPath $Path -PathType Leaf)) { return "" }
    $text = Get-Content -LiteralPath $Path -Raw
    $match = [regex]::Match($text, '(?m)^-\s*Strict mode:\s*(.+?)\r?$')
    if ($match.Success) { return $match.Groups[1].Value.Trim().ToLowerInvariant() }
    $tableMatch = [regex]::Match($text, '(?m)^\|\s*Strict mode\s*\|\s*(.+?)\s*\|')
    if ($tableMatch.Success) { return $tableMatch.Groups[1].Value.Trim().ToLowerInvariant() }
    return ""
}

function Acceptance-DisplayResult {
    param([object]$File)
    if ($null -eq $File) { return @("OPTIONAL_MISSING", "Run after the real test server is ready.") }
    $result = Read-ReportResult $File.FullName
    $profile = Read-ReportProfile $File.FullName
    $strict = Read-ReportStrict $File.FullName
    $display = $result
    if ($result -eq "PASS" -and $profile -eq "test" -and $strict -in @("yes", "1", "true")) {
        $display = "TEST_STRICT_PASS"
    } elseif ($result -eq "PASS" -and $profile -eq "local") {
        $display = "PASS_LOCAL_ONLY"
    } elseif ($result -eq "PASS") {
        $display = "PASS_NON_FINAL"
    } elseif (Is-ReportWarning $result) {
        $script:warnings++
    }
    return @($display, $File.FullName)
}

function Is-ReportWarning {
    param([string]$Result)
    return ($Result -ne "PASS" -and $Result -ne "DRY_RUN")
}

function Add-ArtifactRow {
    param([string]$Name, [object]$File, [string]$ChecksumPath)
    if ($null -eq $File) {
        $script:artifactRows += "| $Name | MISSING |  |  |  |"
        $script:warnings++
        return
    }
    $stateHash = Sha-State $File.FullName $ChecksumPath
    $state = $stateHash[0]
    $hash = $stateHash[1]
    if ($state -ne "PASS") { $script:warnings++ }
    $script:artifactRows += "| $Name | $state | $($File.Name) | $hash | $($File.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")) |"
}

function Add-OptionalArtifactRow {
    param([string]$Name, [object]$File, [string]$ChecksumPath)
    if ($null -eq $File) {
        return
    }
    Add-ArtifactRow $Name $File $ChecksumPath
}

if ($OutputPath -eq "") {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = "runtime/handover/mongoyia-handoff-status-$stamp.md"
}
$outputFull = Resolve-ProjectPath $OutputPath
$outputDir = Split-Path -Parent $outputFull
if (!(Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$script:warnings = 0
$script:artifactRows = @()

$delivery = Latest-File "mongoyia-test-server-delivery-*.zip"
$deliveryTarGz = Latest-File "mongoyia-test-server-delivery-*.tar.gz"
$handover = Latest-File "mongoyia-handover-*.zip"
$sourceHandover = Latest-File "mongoyia-source-handover-*.zip"
$untracked = Latest-File "mongoyia-untracked-source-*.zip"
$patch = Latest-File "mongoyia-source-tracked-diff-*.patch"
$preflight = Latest-File "mongoyia-test-server-preflight-*.md"
$handoverVerify = Latest-File "mongoyia-handover-verify-*.md"
$sqlManifest = Latest-File "mongoyia-sql-dump-manifest-*.md"
$envReport = Latest-File "mongoyia-env-redacted-report-*.md"
$receiverStatus = Latest-StatusFile "receiver-*" "RECEIVER_STATUS.md"
$restoreStatus = Latest-StatusFile "restore-*" "RESTORE_STATUS.md"
$restorePlan = Latest-NonSmokeFile "mongoyia-test-server-restore-plan-*.md"
$goNoGoReport = Latest-NonSmokeFile "mongoyia-test-server-go-no-go-*.md"
$acceptanceReport = Latest-AcceptanceFile "mongoyia-acceptance-*.md"
$signoffReport = Latest-AcceptanceFile "mongoyia-signoff-*.md"
$riskReport = Latest-AcceptanceFile "mongoyia-risk-register-*.md"
$deliveryIndex = Latest-AcceptanceFile "mongoyia-delivery-index-*.md"

if ($null -ne $delivery) { Add-ArtifactRow "test-server delivery" $delivery ($delivery.FullName + ".sha256") } else { Add-ArtifactRow "test-server delivery" $null "" }
Add-OptionalArtifactRow "test-server delivery tar.gz" $deliveryTarGz ($deliveryTarGz.FullName + ".sha256")
if ($null -ne $handover) { Add-ArtifactRow "handover archive" $handover ($handover.FullName + ".sha256") } else { Add-ArtifactRow "handover archive" $null "" }
if ($null -ne $sourceHandover) { Add-ArtifactRow "source handover archive" $sourceHandover ($sourceHandover.FullName + ".sha256") } else { Add-ArtifactRow "source handover archive" $null "" }
if ($null -ne $untracked) { Add-ArtifactRow "untracked source bundle" $untracked ($untracked.FullName + ".sha256") } else { Add-ArtifactRow "untracked source bundle" $null "" }
if ($null -ne $patch) { Add-ArtifactRow "tracked source patch" $patch ($patch.FullName + ".sha256") } else { Add-ArtifactRow "tracked source patch" $null "" }

$resolvedSql = Resolve-ProjectPath $SqlDumpPath
$resolvedSqlChecksum = Resolve-ProjectPath $SqlChecksumPath
$sqlStateHash = Sha-State $resolvedSql $resolvedSqlChecksum
$sqlState = $sqlStateHash[0]
$sqlHash = $sqlStateHash[1]
if ($sqlState -ne "PASS") { $warnings++ }
$artifactRows += "| SQL dump | $sqlState | $(Split-Path -Leaf $resolvedSql) | $sqlHash |  |"

$reportRows = @()
foreach ($pair in @(
    @("preflight report", $preflight),
    @("handover verify report", $handoverVerify),
    @("SQL dump manifest", $sqlManifest),
    @("env redacted report", $envReport),
    @("restore plan", $restorePlan),
    @("go/no-go checklist", $goNoGoReport),
    @("receiver status", $receiverStatus),
    @("restore status", $restoreStatus),
    @("acceptance report", $acceptanceReport),
    @("acceptance signoff", $signoffReport),
    @("risk register", $riskReport),
    @("acceptance delivery index", $deliveryIndex)
)) {
    $name = $pair[0]
    $file = $pair[1]
    if ($null -eq $file) {
        if ($name -eq "restore plan") {
            $reportRows += "| $name | OPTIONAL_MISSING | Generate this after real test-server inputs are known. |"
        } elseif ($name -in @("acceptance report", "acceptance signoff", "risk register", "acceptance delivery index")) {
            $reportRows += "| $name | OPTIONAL_MISSING | Generate this after the real test-server acceptance run. |"
        } else {
            $reportRows += "| $name | MISSING |  |"
            $warnings++
        }
    } else {
        if ($name -eq "acceptance report") {
            $acceptanceState = Acceptance-DisplayResult $file
            $reportRows += "| $name | $($acceptanceState[0]) | $($acceptanceState[1]) |"
            continue
        }
        $reportResult = Read-ReportResult $file.FullName
        $displayResult = $reportResult
        if ($name -eq "env redacted report" -and $reportResult -ne "PASS" -and (Read-ReportProfile $file.FullName) -eq "local") {
            $displayResult = "${reportResult}_LOCAL_EXPECTED"
        } elseif ($name -eq "restore plan" -and $reportResult -eq "PENDING") {
            $displayResult = "PENDING_EXTERNAL_INPUTS"
        } elseif ($name -eq "go/no-go checklist" -and $reportResult -eq "NO-GO") {
            $warnings++
        } elseif ($name -in @("risk register", "acceptance delivery index") -and $reportResult -eq "UNKNOWN") {
            $displayResult = "PRESENT"
        } elseif ($name -in @("acceptance signoff", "risk register", "acceptance delivery index") -and (Read-ReportStrict $file.FullName) -in @("no", "0", "false")) {
            $displayResult = "${reportResult}_LOCAL_ONLY"
        } elseif (Is-ReportWarning $reportResult) {
            $warnings++
        }
        $reportRows += "| $name | $displayResult | $($file.FullName) |"
    }
}

$warnings++
$reportRows += "| external test-server inputs | PENDING | See Remaining External Inputs below. |"

$deliveryValidation = "NOT_RUN"
if ($ValidateDelivery.IsPresent) {
    $deliveryTargets = @()
    if ($null -ne $delivery) { $deliveryTargets += $delivery }
    if ($null -ne $deliveryTarGz) { $deliveryTargets += $deliveryTarGz }
    if ($deliveryTargets.Count -gt 0) {
        $validated = 0
        foreach ($target in $deliveryTargets) {
            try {
                & "$PSScriptRoot\mongoyia-validate-test-server-delivery.ps1" -ArchivePath $target.FullName | Out-Null
                $validated++
            } catch {
                $deliveryValidation = "FAIL: " + $_.Exception.Message
                $warnings++
                break
            }
        }
        if ($deliveryValidation -eq "NOT_RUN") {
            $deliveryValidation = "PASS ($validated archive(s))"
        }
    }
}

$result = if ($warnings -eq 0) { "PASS" } else { "WARN" }
$report = @(
    "# Mongoyia Handoff Status",
    "",
    "- Result: $result",
    "- Warnings: $warnings",
    "- Generated at: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")",
    "- Source root: $Root",
    "- Delivery validation: $deliveryValidation",
    "",
    "## Artifacts",
    "",
    "| Item | Check | File | SHA256 | Updated |",
    "|---|---:|---|---|---|"
) + $artifactRows + @(
    "",
    "## Reports",
    "",
    "| Item | Result | Path |",
    "|---|---:|---|"
) + $reportRows + @(
    "",
    "## Remaining External Inputs",
    "",
    "- Real test-server host and access.",
    "- Real PHP and Python IM `.env` values.",
    "- HTTPS test domain and WSS IM path.",
    "- Test database credentials.",
    "- Payment sandbox credentials and callback secrets.",
    "- Manual QA owner for payment, IM, backend seller operations, and Mongolian content."
)

$report | Set-Content -LiteralPath $outputFull -Encoding UTF8
Write-Output "Handoff status report: $outputFull"
Write-Output "Result: $result ($warnings warning(s))"
