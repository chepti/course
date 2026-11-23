$ErrorActionPreference = 'Stop'
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$token = ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
$tmp = Join-Path $env:TEMP 'course-deploy-token.txt'
[System.IO.File]::WriteAllText($tmp, $token)
try {
  gh secret set DEPLOY_TOKEN -R chepti/course --body $token
  scp -F 'T:\.ssh\config' $tmp 'hostinger:/home/u630483490/.course-deploy-token'
  ssh -F 'T:\.ssh\config' hostinger 'chmod 600 ~/.course-deploy-token; wc -c ~/.course-deploy-token'
  scp -F 'T:\.ssh\config' (Join-Path $PSScriptRoot 'course-deploy.php') 'hostinger:/home/u630483490/public_html/tikshuv/course-deploy.php'
} finally {
  Remove-Item -Force $tmp -ErrorAction SilentlyContinue
}
Write-Output 'done'
