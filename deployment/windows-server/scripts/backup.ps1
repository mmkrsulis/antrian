param([string]$InstallDir='C:\RekaQueue')
$ErrorActionPreference='Stop'
$envFile=Join-Path $env:ProgramData 'Reka Queue\config\.env'
if(!(Test-Path $envFile)){exit 1}
$settings=@{};Get-Content $envFile|ForEach-Object{if($_ -match '^([^#][^=]*)=(.*)$'){$settings[$matches[1].Trim()]=$matches[2].Trim('"')}}
$backupDir=Join-Path $env:ProgramData 'Reka Queue\backups';New-Item -ItemType Directory -Force $backupDir|Out-Null
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss';$target=Join-Path $backupDir "reka-queue-$stamp.sql"
$mysql=Join-Path $InstallDir 'runtime\xampp\mysql\bin\mysqldump.exe'
$arguments=@('-h','127.0.0.1','-u',$settings.DB_USERNAME,"--password=$($settings.DB_PASSWORD)",'--single-transaction','--routines','--events',$settings.DB_DATABASE)
& $mysql @arguments|Set-Content -Encoding UTF8 $target
Get-ChildItem $backupDir -Filter '*.sql'|Sort-Object LastWriteTime -Descending|Select-Object -Skip 30|Remove-Item -Force
