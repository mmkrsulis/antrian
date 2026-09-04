param(
    [Parameter(Mandatory=$true)][string]$PackageRoot,
    [Parameter(Mandatory=$true)][string]$OptionsFile
)
$ErrorActionPreference = 'Stop'

function Read-Ini([string]$path) {
    $values=@{}
    Get-Content -LiteralPath $path | ForEach-Object {
        $line=$_.Trim()
        if($line -and !$line.StartsWith('#') -and !$line.StartsWith(';') -and !$line.StartsWith('[') -and $line.Contains('=')) {
            $parts=$line.Split('=',2);$values[$parts[0].Trim()]=$parts[1].Trim()
        }
    }
    return $values
}
function Random-Secret([int]$length=32) {
    $bytes=New-Object byte[] $length;$rng=[Security.Cryptography.RandomNumberGenerator]::Create()
    try{$rng.GetBytes($bytes)}finally{$rng.Dispose()}
    return [Convert]::ToBase64String($bytes).Replace('+','A').Replace('/','B').Replace('=','').Substring(0,$length)
}
function Invoke-Native([string]$file,[string[]]$arguments) {
    & $file @arguments
    if($LASTEXITCODE -ne 0){throw "Command failed ($LASTEXITCODE): $file"}
}
function Escape-Xml([string]$value){return [Security.SecurityElement]::Escape($value)}
function Ensure-Junction([string]$link,[string]$target) {
    New-Item -ItemType Directory -Force -Path $target | Out-Null
    if(Test-Path -LiteralPath $link){
        $item=Get-Item -LiteralPath $link -Force
        if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){return}
        if((Get-ChildItem -LiteralPath $link -Force -ErrorAction SilentlyContinue|Measure-Object).Count -gt 0){Copy-Item "$link\*" $target -Recurse -Force}
        Remove-Item -LiteralPath $link -Recurse -Force
    }
    cmd /c "mklink /J `"$link`" `"$target`"" | Out-Null
    if($LASTEXITCODE -ne 0){throw "Unable to create data junction: $link"}
}
function Remove-ServiceWrapper([string]$exe) {
    if(Test-Path $exe){& $exe stop 2>$null; & $exe uninstall 2>$null}
}

$options=Read-Ini $OptionsFile
$installDir=$options.INSTALL_DIR
$port=[int]$options.SERVER_PORT
$adminName=$options.ADMIN_NAME
$adminUsername=$options.ADMIN_USERNAME
$adminPassword=$options.ADMIN_PASSWORD
$serverIp=$options.SERVER_IP
if(!$installDir -or $port -lt 1 -or $port -gt 65535){throw 'Invalid installation options.'}
if($adminUsername -notmatch '^[a-zA-Z0-9._-]{3,50}$'){throw 'Administrator username must be 3-50 letters, numbers, dots, dashes, or underscores.'}
$payload=Join-Path $PackageRoot 'payload\app'
if(!(Test-Path (Join-Path $payload 'public\index.php'))){throw 'Application payload is missing.'}

$portOwner=Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue | Select-Object -First 1
if($portOwner){
    $existing=Get-Service RekaQueueWeb -ErrorAction SilentlyContinue
    if(!$existing){throw "Server port $port is already in use. Choose another port."}
}

$dataDir=Join-Path $env:ProgramData 'Reka Queue'
$storageDir=Join-Path $dataDir 'storage'
$uploadsDir=Join-Path $dataDir 'uploads'
$databaseDir=Join-Path $dataDir 'database'
$configDir=Join-Path $dataDir 'config'
$backupDir=Join-Path $dataDir 'backups'
$logDir=Join-Path $dataDir 'logs'
foreach($folder in @($installDir,$dataDir,$storageDir,$uploadsDir,$databaseDir,$configDir,$backupDir,$logDir,(Join-Path $installDir 'tools'),(Join-Path $installDir 'assets'))){New-Item -ItemType Directory -Force -Path $folder|Out-Null}
$iconFile=Join-Path $installDir 'assets\reka-queue.ico'
Copy-Item (Join-Path $PackageRoot 'assets\reka-queue.ico') $iconFile -Force
Copy-Item (Join-Path $PackageRoot 'assets\reka-queue.png') (Join-Path $installDir 'assets\reka-queue.png') -Force

$runtimeDir=Join-Path $installDir 'runtime'
$phpDir=Join-Path $runtimeDir 'php'
$caddyDir=Join-Path $runtimeDir 'caddy'
$serviceDir=Join-Path $runtimeDir 'services'
foreach($folder in @($runtimeDir,$phpDir,$caddyDir,$serviceDir)){New-Item -ItemType Directory -Force -Path $folder|Out-Null}
$phpZip=Get-ChildItem (Join-Path $PackageRoot 'runtime') -Filter 'php-*-nts-Win32-vs17-x64.zip'|Select-Object -First 1
$caddyZip=Get-ChildItem (Join-Path $PackageRoot 'runtime') -Filter 'caddy_*_windows_amd64.zip'|Select-Object -First 1
$winsw=Join-Path $PackageRoot 'runtime\WinSW-x64.exe'
$vcRuntime=Join-Path $PackageRoot 'runtime\vc_redist.x64.exe'
if(!$phpZip -or !$caddyZip -or !(Test-Path $winsw) -or !(Test-Path $vcRuntime)){throw 'A required signed runtime component is missing.'}

$vcInstall=Start-Process -FilePath $vcRuntime -ArgumentList '/install','/quiet','/norestart' -Wait -PassThru
if($vcInstall.ExitCode -notin @(0,1638,3010)){throw "Microsoft Visual C++ runtime installation failed ($($vcInstall.ExitCode))."}
Expand-Archive -LiteralPath $phpZip.FullName -DestinationPath $phpDir -Force
Expand-Archive -LiteralPath $caddyZip.FullName -DestinationPath $caddyDir -Force

$appDir=Join-Path $installDir 'app'
New-Item -ItemType Directory -Force $appDir|Out-Null
& robocopy $payload $appDir /E /R:2 /W:1 /XD storage public\uploads|Out-Null
if($LASTEXITCODE -gt 7){throw "Unable to copy application files (robocopy $LASTEXITCODE)."}
Ensure-Junction (Join-Path $appDir 'storage') $storageDir
New-Item -ItemType Directory -Force (Join-Path $appDir 'public')|Out-Null
Ensure-Junction (Join-Path $appDir 'public\uploads') $uploadsDir
foreach($folder in @('logs','sessions')){New-Item -ItemType Directory -Force (Join-Path $storageDir $folder)|Out-Null}
foreach($folder in @('header','media\playlist','notifications','speech')){New-Item -ItemType Directory -Force (Join-Path $uploadsDir $folder)|Out-Null}

if(!$serverIp -or $serverIp -eq 'auto'){
    $serverIp=Get-NetIPAddress -AddressFamily IPv4|Where-Object{$_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notmatch 'Loopback'}|Sort-Object InterfaceMetric|Select-Object -ExpandProperty IPAddress -First 1
}
if(!$serverIp){$serverIp='127.0.0.1'}
$localUrl="http://localhost:$port"
$networkUrl="http://${serverIp}:$port"
$appUrl=$localUrl
$databaseFile=Join-Path $databaseDir 'reka-queue.sqlite'
$envStore=Join-Path $configDir '.env'
$fresh=!(Test-Path $databaseFile)
if($fresh){
    if($adminPassword.Length -lt 10){throw 'Administrator password must contain at least 10 characters.'}
    $displayKey=Random-Secret 32;$onlineKey=Random-Secret 40
    $envText=@"
APP_NAME="Reka Queue Management"
APP_ENV=production
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=sqlite
DB_DATABASE=$databaseFile
SESSION_SECURE=false
DISPLAY_ACCESS_KEY=$displayKey
ONLINE_API_KEY=$onlineKey
"@
    [IO.File]::WriteAllText($envStore,$envText,[Text.UTF8Encoding]::new($false))
}else{
    if(!(Test-Path $envStore)){throw 'Existing database configuration is missing. Installation stopped to protect data.'}
    $existingEnv=[IO.File]::ReadAllText($envStore)
    $existingEnv=[Text.RegularExpressions.Regex]::Replace($existingEnv,'(?m)^APP_URL=.*$',"APP_URL=$appUrl")
    [IO.File]::WriteAllText($envStore,$existingEnv,[Text.UTF8Encoding]::new($false))
}
Copy-Item $envStore (Join-Path $appDir '.env') -Force
$envValues=Read-Ini $envStore
$displayKey=$envValues.DISPLAY_ACCESS_KEY

$phpIni=Join-Path $phpDir 'php.ini'
Copy-Item (Join-Path $phpDir 'php.ini-production') $phpIni -Force
$phpExt=(Join-Path $phpDir 'ext').Replace('\','/')
$phpSettings=@"

; Reka Queue managed settings
extension_dir="$phpExt"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
upload_max_filesize=512M
post_max_size=520M
max_execution_time=300
date.timezone=Asia/Jakarta
expose_php=Off
session.cookie_httponly=1
"@
Add-Content -LiteralPath $phpIni -Value $phpSettings -Encoding UTF8

$phpPort=19070
$caddyFile=Join-Path $caddyDir 'Caddyfile'
$publicPath=(Join-Path $appDir 'public').Replace('\','/')
$caddyConfig=@"
:$port {
    root * "$publicPath"
    encode zstd gzip
    php_fastcgi 127.0.0.1:$phpPort
    file_server
    header {
        -Server
        X-Content-Type-Options nosniff
        X-Frame-Options SAMEORIGIN
        Referrer-Policy same-origin
    }
    log {
        output file "$(($logDir+'\access.log').Replace('\','/'))" {
            roll_size 20MiB
            roll_keep 5
        }
    }
}
"@
[IO.File]::WriteAllText($caddyFile,$caddyConfig,[Text.UTF8Encoding]::new($false))
Invoke-Native (Join-Path $caddyDir 'caddy.exe') @('validate','--config',$caddyFile,'--adapter','caddyfile')

$phpService=Join-Path $serviceDir 'RekaQueuePHP.exe'
$webService=Join-Path $serviceDir 'RekaQueueWeb.exe'
Copy-Item $winsw $phpService -Force;Copy-Item $winsw $webService -Force
$phpExe=(Join-Path $phpDir 'php-cgi.exe')
$phpExeXml=Escape-Xml $phpExe;$phpIniXml=Escape-Xml $phpIni;$appDirXml=Escape-Xml $appDir
$caddyExeXml=Escape-Xml (Join-Path $caddyDir 'caddy.exe');$caddyFileXml=Escape-Xml $caddyFile;$caddyDirXml=Escape-Xml $caddyDir;$logDirXml=Escape-Xml $logDir
$phpXml=@"
<service><id>RekaQueuePHP</id><name>Reka Queue PHP</name><description>PHP FastCGI runtime for Reka Queue Management</description><executable>$phpExeXml</executable><arguments>-b 127.0.0.1:$phpPort -c &quot;$phpIniXml&quot;</arguments><workingdirectory>$appDirXml</workingdirectory><env name="PHP_FCGI_CHILDREN" value="4"/><env name="PHP_FCGI_MAX_REQUESTS" value="500"/><startmode>Automatic</startmode><onfailure action="restart" delay="5 sec"/><resetfailure>1 hour</resetfailure><logpath>$logDirXml</logpath><log mode="roll-by-size"><sizeThreshold>10240</sizeThreshold><keepFiles>5</keepFiles></log></service>
"@
$webXml=@"
<service><id>RekaQueueWeb</id><name>Reka Queue Web Server</name><description>Caddy web server for Reka Queue Management</description><executable>$caddyExeXml</executable><arguments>run --config &quot;$caddyFileXml&quot; --adapter caddyfile</arguments><workingdirectory>$caddyDirXml</workingdirectory><startmode>Automatic</startmode><depend>RekaQueuePHP</depend><stopexecutable>$caddyExeXml</stopexecutable><stoparguments>stop --address localhost:2019</stoparguments><onfailure action="restart" delay="5 sec"/><resetfailure>1 hour</resetfailure><logpath>$logDirXml</logpath><log mode="roll-by-size"><sizeThreshold>10240</sizeThreshold><keepFiles>5</keepFiles></log></service>
"@
[IO.File]::WriteAllText((Join-Path $serviceDir 'RekaQueuePHP.xml'),$phpXml,[Text.UTF8Encoding]::new($false))
[IO.File]::WriteAllText((Join-Path $serviceDir 'RekaQueueWeb.xml'),$webXml,[Text.UTF8Encoding]::new($false))

if($fresh){$env:LIVE_ADMIN_PASSWORD=$adminPassword;$env:ADMIN_NAME=$adminName;$env:ADMIN_USERNAME=$adminUsername}
try{Invoke-Native (Join-Path $phpDir 'php.exe') @(Join-Path $appDir 'bin\install-live.php')}finally{Remove-Item Env:LIVE_ADMIN_PASSWORD -ErrorAction SilentlyContinue;Remove-Item Env:ADMIN_NAME -ErrorAction SilentlyContinue;Remove-Item Env:ADMIN_USERNAME -ErrorAction SilentlyContinue}

Remove-ServiceWrapper $webService;Remove-ServiceWrapper $phpService
Invoke-Native $phpService @('install');Invoke-Native $webService @('install')
Invoke-Native $phpService @('start');Invoke-Native $webService @('start')

$healthUrl="http://127.0.0.1:$port/login"
$ready=$false
for($attempt=0;$attempt -lt 30;$attempt++){
    try{$response=Invoke-WebRequest -UseBasicParsing -Uri $healthUrl -TimeoutSec 3;if($response.StatusCode -eq 200){$ready=$true;break}}catch{}
    Start-Sleep -Seconds 1
}
if(!$ready){Remove-ServiceWrapper $webService;Remove-ServiceWrapper $phpService;throw 'The local web health check failed. Services were rolled back.'}

netsh advfirewall firewall delete rule name="Reka Queue Server"|Out-Null
netsh advfirewall firewall add rule name="Reka Queue Server" dir=in action=allow protocol=TCP localport=$port|Out-Null
Copy-Item (Join-Path $PackageRoot 'scripts\backup.ps1') (Join-Path $installDir 'tools\backup.ps1') -Force
Copy-Item (Join-Path $PackageRoot 'scripts\uninstall.ps1') (Join-Path $installDir 'tools\uninstall.ps1') -Force
$taskCommand="powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$installDir\tools\backup.ps1`" -InstallDir `"$installDir`""
schtasks /Create /TN "Reka Queue Daily Backup" /SC DAILY /ST 23:30 /TR $taskCommand /RU SYSTEM /F|Out-Null

$desktop=[Environment]::GetFolderPath('CommonDesktopDirectory');$programs=Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs\Reka Queue'
New-Item -ItemType Directory -Force $programs|Out-Null
$shortcuts=@{'Reka Queue Admin.url'="$localUrl/dashboard";'Reka Queue Kiosk.url'="$localUrl/kiosk";'Reka Queue Display.url'="$localUrl/display?key=$displayKey&fullscreen=1"}
foreach($shortcut in $shortcuts.GetEnumerator()){$text="[InternetShortcut]`r`nURL=$($shortcut.Value)`r`nIconFile=$iconFile`r`nIconIndex=0`r`n";[IO.File]::WriteAllText((Join-Path $desktop $shortcut.Key),$text,[Text.UTF8Encoding]::new($false));[IO.File]::WriteAllText((Join-Path $programs $shortcut.Key),$text,[Text.UTF8Encoding]::new($false))}
[IO.File]::WriteAllText((Join-Path $installDir 'installation.txt'),"URL=$localUrl`r`nNETWORK_URL=$networkUrl`r`nRuntime=PHP+Caddy`r`nDatabase=$databaseFile`r`nData=$dataDir`r`nInstalled=$(Get-Date -Format s)`r`n",[Text.UTF8Encoding]::new($false))
Remove-Item -LiteralPath $OptionsFile -Force -ErrorAction SilentlyContinue
Write-Output "READY_URL=$localUrl"
