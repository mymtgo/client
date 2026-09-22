# Publishes the sidecar on Windows and copies it into the app's resources.
# Usage: .\sidecar\publish.ps1 -Version 0.30.0
param([Parameter(Mandatory = $true)][string]$Version)
if ($Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$') {
    throw "version must look like 0.30.0, got '$Version'"
}
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot
$Out = Join-Path ".." "resources/sidecar"
$PkgId = "mtgosdk"
$PkgVersion = "1.7.0.20260903"

dotnet publish MyMtgo.Sidecar -c Release -r win-x64 --self-contained `
  -p:PublishSingleFile=true -p:PublishTrimmed=false "-p:Version=$Version" `
  -o artifacts/publish
if ($LASTEXITCODE -ne 0) { throw "publish failed" }

$Pkg = Join-Path $env:USERPROFILE ".nuget/packages/$PkgId/$PkgVersion"
$Nupkg = Join-Path $Pkg "$PkgId.$PkgVersion.nupkg"

# Everything is assembled in a staging folder and only moved into $Out once all three files
# exist. Copying straight into $Out would leave a bundle holding an exe with no attribution
# whenever a package revision drops its NOTICE, because Stop aborts after the exe is in place.
$Stage = Join-Path "artifacts" "stage"
if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
New-Item -ItemType Directory -Force -Path $Stage | Out-Null

Copy-Item artifacts/publish/mymtgo-sidecar.exe (Join-Path $Stage "mymtgo-sidecar.exe") -Force

$NoticePath = Join-Path $Pkg "NOTICE"
if (-not (Test-Path $NoticePath)) {
    throw "no NOTICE in $Pkg; refusing to ship the exe without attribution"
}
Copy-Item $NoticePath (Join-Path $Stage "MTGOSDK-NOTICE.txt") -Force

$LicensePath = Join-Path $Pkg "LICENSE"
$LicenseOut = Join-Path $Stage "MTGOSDK-LICENSE.txt"
if (Test-Path $LicensePath) {
    Copy-Item $LicensePath $LicenseOut -Force
} else {
    # MTGOSDK 1.7.0.20260903 ships no LICENSE file in the package; try the .nupkg zip,
    # then fall back to the license expression recorded in its nuspec.
    # Windows PowerShell 5.1 needs the assembly loaded; PowerShell 7 already has ZipFile.
    if (-not ('System.IO.Compression.ZipFile' -as [type])) {
        Add-Type -AssemblyName System.IO.Compression.FileSystem
    }
    $zip = [System.IO.Compression.ZipFile]::OpenRead($Nupkg)
    $entry = $zip.Entries | Where-Object { $_.FullName -eq "LICENSE" }
    if ($entry) {
        $stream = New-Object System.IO.StreamReader($entry.Open())
        $stream.ReadToEnd() | Set-Content -Path $LicenseOut -NoNewline
        $stream.Close()
        $zip.Dispose()
    } else {
        $zip.Dispose()
        [xml]$nuspec = Get-Content (Join-Path $Pkg "MTGOSDK.nuspec")
        $licenseExpr = $nuspec.package.metadata.license.'#text'
        $licenseUrl = $nuspec.package.metadata.licenseUrl
        @(
            "MTGOSDK $PkgVersion does not bundle a LICENSE file."
            "Its NuGet package declares license: $licenseExpr"
            "See: $licenseUrl"
        ) | Set-Content -Path $LicenseOut
    }
}

New-Item -ItemType Directory -Force -Path $Out | Out-Null
Move-Item (Join-Path $Stage "mymtgo-sidecar.exe") (Join-Path $Out "mymtgo-sidecar.exe") -Force
Move-Item (Join-Path $Stage "MTGOSDK-NOTICE.txt") (Join-Path $Out "MTGOSDK-NOTICE.txt") -Force
Move-Item (Join-Path $Stage "MTGOSDK-LICENSE.txt") (Join-Path $Out "MTGOSDK-LICENSE.txt") -Force
Remove-Item $Stage -Recurse -Force

Get-ChildItem $Out
