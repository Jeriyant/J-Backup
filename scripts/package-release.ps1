[CmdletBinding()]
param(
    [string] $SourceRoot = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($SourceRoot)) {
    $SourceRoot = Split-Path -Parent $PSScriptRoot
}

$source = (Resolve-Path -LiteralPath $SourceRoot).Path
$versionFile = Join-Path $source 'version.json'
$distRoot = Join-Path $source 'dist-pack'
$stage = Join-Path $distRoot 'j-backup-release'
$archive = Join-Path $source 'j-backup-dist.zip'
$checksum = "$archive.sha256"

if (-not (Test-Path -LiteralPath $versionFile -PathType Leaf)) {
    throw "Version file not found: $versionFile"
}

$version = [string] ((Get-Content -LiteralPath $versionFile -Raw | ConvertFrom-Json).version)
if ($version -notmatch '^\d+\.\d+\.\d+(?:[.-][A-Za-z0-9.-]+)?$') {
    throw "Invalid release version: $version"
}

New-Item -ItemType Directory -Force -Path $distRoot | Out-Null
$distResolved = (Resolve-Path -LiteralPath $distRoot).Path
$stageFull = [IO.Path]::GetFullPath($stage)
if (-not $stageFull.StartsWith(
    $distResolved + [IO.Path]::DirectorySeparatorChar,
    [StringComparison]::OrdinalIgnoreCase
)) {
    throw "Refusing to clean a staging path outside dist-pack: $stageFull"
}
if (Test-Path -LiteralPath $stageFull) {
    Remove-Item -LiteralPath $stageFull -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $stageFull | Out-Null

foreach ($name in @(
    'index.php',
    'api.php',
    'version.json',
    'og.png',
    '.htaccess',
    'Cara Install.md'
)) {
    Copy-Item -LiteralPath (Join-Path $source $name) -Destination $stageFull -Force
}

foreach ($name in @('src', 'assets', 'bin', 'deploy', 'scripts')) {
    Copy-Item -LiteralPath (Join-Path $source $name) -Destination $stageFull -Recurse -Force
}

if (Test-Path -LiteralPath $archive) {
    Remove-Item -LiteralPath $archive -Force
}
if (Test-Path -LiteralPath $checksum) {
    Remove-Item -LiteralPath $checksum -Force
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipStream = [IO.File]::Open($archive, [IO.FileMode]::CreateNew)
$zip = [IO.Compression.ZipArchive]::new(
    $zipStream,
    [IO.Compression.ZipArchiveMode]::Create,
    $false
)
try {
    foreach ($file in Get-ChildItem -LiteralPath $stageFull -File -Recurse -Force) {
        $relative = $file.FullName.Substring($stageFull.Length + 1).Replace('\', '/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $file.FullName,
            $relative,
            [IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $zip.Dispose()
    $zipStream.Dispose()
}

$hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
$archiveName = Split-Path -Leaf $archive
Set-Content -LiteralPath $checksum -Value "$hash  $archiveName" -Encoding ascii

$item = Get-Item -LiteralPath $archive
[pscustomobject]@{
    Version = $version
    File = $item.Name
    Size = $item.Length
    SHA256 = $hash
    ChecksumFile = (Split-Path -Leaf $checksum)
} | Format-List
