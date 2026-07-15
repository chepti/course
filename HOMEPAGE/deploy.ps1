# Full deploy of HOMEPAGE/site to Hostinger (chepti.com/home/)
# Usage: .\deploy.ps1
# Optional: .\deploy.ps1 -SkipData

param(
  [switch]$SkipData
)

$ErrorActionPreference = 'Stop'
$sshConfig = 'T:\.ssh\config'
$knownHosts = 'T:\.ssh\known_hosts'
$remote = 'hostinger:/home/u630483490/public_html/home'
$site = Join-Path $PSScriptRoot 'site'
# Hebrew Windows usernames break OpenSSH path parsing; keep known_hosts on T:\.ssh
$sshOpts = @('-F', $sshConfig, '-o', "UserKnownHostsFile=$knownHosts")

if (-not (Test-Path -LiteralPath $sshConfig)) {
  throw "Missing SSH config: $sshConfig"
}
if (-not (Test-Path -LiteralPath $knownHosts)) {
  $userKh = Join-Path $env:USERPROFILE '.ssh\known_hosts'
  if (Test-Path -LiteralPath $userKh) {
    New-Item -ItemType Directory -Force -Path (Split-Path $knownHosts) | Out-Null
    Copy-Item -Force $userKh $knownHosts
  } else {
    throw "Missing known_hosts: $knownHosts"
  }
}
if (-not (Test-Path -LiteralPath (Join-Path $site 'index.html'))) {
  throw 'Missing site\index.html'
}

$scp = $null
foreach ($candidate in @(
  'C:\Windows\System32\OpenSSH\scp.exe',
  'T:\תוכנות\Git\usr\bin\scp.exe',
  'C:\Program Files\Git\usr\bin\scp.exe',
  'scp'
)) {
  if ($candidate -eq 'scp' -or (Test-Path -LiteralPath $candidate)) {
    $scp = $candidate
    break
  }
}
if (-not $scp) { throw 'scp not found' }

Write-Host '=== Full deploy to chepti.com/home/ ===' -ForegroundColor Cyan

$core = @(
  (Join-Path $site 'index.html'),
  (Join-Path $site 'api.php')
)
if (-not $SkipData) {
  $core += (Join-Path $site 'data.json')
  Write-Host 'Including data.json (overwrites server)' -ForegroundColor Yellow
} else {
  Write-Host 'Skipping data.json (-SkipData)' -ForegroundColor DarkYellow
}

& $scp @sshOpts @core ($remote + '/')
if ($LASTEXITCODE -ne 0) { throw "scp failed for core files, exit=$LASTEXITCODE" }

$htaccess = Join-Path $site 'home.htaccess'
if (Test-Path -LiteralPath $htaccess) {
  & $scp @sshOpts $htaccess ($remote + '/.htaccess')
  if ($LASTEXITCODE -ne 0) { throw "scp failed for .htaccess, exit=$LASTEXITCODE" }
}

$imgDir = Join-Path $site 'img'
$images = @(Get-ChildItem -LiteralPath $imgDir -Filter '*.jpg' -ErrorAction SilentlyContinue)
if ($images.Count -gt 0) {
  $imgArgs = $sshOpts + ($images | ForEach-Object { $_.FullName }) + @($remote + '/img/')
  & $scp @imgArgs
  if ($LASTEXITCODE -ne 0) { throw "scp failed for images, exit=$LASTEXITCODE" }
}

Write-Host '=== Deploy complete ===' -ForegroundColor Green
Write-Host 'https://chepti.com/'
Write-Host 'https://chepti.com/?space=works'
