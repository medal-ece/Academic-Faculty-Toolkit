param(
    [string]$OutputDir = "..\..\releases"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$packageName = "academic-student-directory"
$outputPath = [System.IO.Path]::GetFullPath((Join-Path $root $OutputDir))
$buildPath = Join-Path $outputPath "build-$packageName"
$stagePath = Join-Path $buildPath $packageName
$zipPath = Join-Path $outputPath "$packageName.zip"

function Copy-CleanDirectory {
    param(
        [string]$Source,
        [string]$Destination,
        [string[]]$ExcludeRelative = @()
    )

    $sourcePath = Resolve-Path -LiteralPath $Source
    New-Item -ItemType Directory -Force -Path $Destination | Out-Null

    Get-ChildItem -LiteralPath $sourcePath -Recurse -Force | ForEach-Object {
        $relative = $_.FullName.Substring($sourcePath.Path.Length).TrimStart("\", "/")
        $normalized = $relative -replace "\\", "/"

        foreach ($exclude in $ExcludeRelative) {
            if ($normalized -eq $exclude -or $normalized -like $exclude) {
                return
            }
        }

        $target = Join-Path $Destination $relative
        if ($_.PSIsContainer) {
            New-Item -ItemType Directory -Force -Path $target | Out-Null
        } else {
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
            Copy-Item -LiteralPath $_.FullName -Destination $target -Force
        }
    }
}

function New-ZipFromDirectory {
    param(
        [string]$SourceRoot,
        [string]$ZipPath
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    if (Test-Path -LiteralPath $ZipPath) {
        Remove-Item -LiteralPath $ZipPath -Force
    }

    $sourceRootPath = Resolve-Path -LiteralPath $SourceRoot
    $zipArchive = [System.IO.Compression.ZipFile]::Open($ZipPath, [IO.Compression.ZipArchiveMode]::Create)

    try {
        Get-ChildItem -LiteralPath $sourceRootPath -Recurse -File -Force | ForEach-Object {
            $relative = $_.FullName.Substring($sourceRootPath.Path.Length).TrimStart("\", "/")
            $entryName = $relative -replace "\\", "/"
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $_.FullName, $entryName, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally {
        $zipArchive.Dispose()
    }
}

New-Item -ItemType Directory -Force -Path $outputPath | Out-Null
if (Test-Path -LiteralPath $buildPath) {
    $resolvedBuild = Resolve-Path -LiteralPath $buildPath
    $resolvedOutput = Resolve-Path -LiteralPath $outputPath
    if ($resolvedBuild.Path.StartsWith($resolvedOutput.Path)) {
        Remove-Item -LiteralPath $resolvedBuild.Path -Recurse -Force
    } else {
        throw "Refusing to remove unexpected build path: $($resolvedBuild.Path)"
    }
}

New-Item -ItemType Directory -Force -Path $buildPath | Out-Null
Copy-CleanDirectory -Source $root -Destination $stagePath -ExcludeRelative @(
    ".git",
    ".git/*",
    ".agents",
    ".agents/*",
    ".codex",
    ".codex/*",
    "node_modules",
    "node_modules/*",
    "vendor",
    "vendor/*",
    "releases",
    "releases/*",
    "data/*.csv",
    "data/backups/*",
    "data/private/*",
    "*.map",
    "*.zip"
)

New-ZipFromDirectory -SourceRoot $buildPath -ZipPath $zipPath
Remove-Item -LiteralPath $buildPath -Recurse -Force

Write-Host "Created: $zipPath"
