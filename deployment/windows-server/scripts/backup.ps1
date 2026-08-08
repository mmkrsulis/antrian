$ErrorActionPreference='Stop'
$installDir=Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path);$envFile=Join-Path $installDir 'app\.env'
if(!(Test-Path $envFile)){exit 1}
$settings=@{};Get-Content $envFile|ForEach-Object{if($_ -match '^([^#][^=]*)=(.*)$'){$settings[$matches[1].Trim()]=$matches[2].Trim('"')}}
$xampp=(Get-Content (Join-Path $installDir 'installation.txt') -ErrorAction SilentlyContinue|Where-Object{$_ -like 'XAMPP=*'}|ForEach-Object{$_.Substring(6)})
if(!$xampp){$xampp='C:\xampp'}
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss';$target=Join-Path $installDir "backups\reka-queue-$stamp.sql"
$arguments=@('-h','127.0.0.1','-u',$settings.DB_USERNAME,"--password=$($settings.DB_PASSWORD)",'--single-transaction','--routines','--events',$settings.DB_DATABASE)
& (Join-Path $xampp 'mysql\bin\mysqldump.exe') @arguments | Set-Content -Encoding UTF8 $target
Get-ChildItem (Join-Path $installDir 'backups') -Filter '*.sql'|Sort-Object LastWriteTime -Descending|Select-Object -Skip 30|Remove-Item -Force
