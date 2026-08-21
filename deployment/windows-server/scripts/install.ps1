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
function Escape-Sql([string]$value){return $value.Replace("'","''")}
function Invoke-Native([string]$file,[string[]]$arguments) {
    & $file @arguments
    if($LASTEXITCODE -ne 0){throw "Command failed ($LASTEXITCODE): $file"}
}
function Ensure-Junction([string]$link,[string]$target,[bool]$discardSource=$false) {
    New-Item -ItemType Directory -Force -Path $target | Out-Null
    if(Test-Path -LiteralPath $link){
        $item=Get-Item -LiteralPath $link -Force
        if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){return}
        if(!$discardSource -and (Get-ChildItem -LiteralPath $link -Force -ErrorAction SilentlyContinue|Measure-Object).Count -gt 0){Copy-Item "$link\*" $target -Recurse -Force}
        Remove-Item -LiteralPath $link -Recurse -Force
    }
    cmd /c "mklink /J `"$link`" `"$target`"" | Out-Null
    if($LASTEXITCODE -ne 0){throw "Unable to create data junction: $link"}
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

$dataDir=Join-Path $env:ProgramData 'Reka Queue'
$storageDir=Join-Path $dataDir 'storage'
$uploadsDir=Join-Path $dataDir 'uploads'
$databaseDir=Join-Path $dataDir 'mariadb'
$configDir=Join-Path $dataDir 'config'
$backupDir=Join-Path $dataDir 'backups'
foreach($folder in @($installDir,$dataDir,$storageDir,$uploadsDir,$configDir,$backupDir,(Join-Path $installDir 'tools'))){New-Item -ItemType Directory -Force -Path $folder|Out-Null}

$vcRuntime=Join-Path $PackageRoot 'runtime\vc_redist.x64.exe'
if(!(Test-Path $vcRuntime)){throw 'Bundled Microsoft Visual C++ runtime is missing.'}
$vcInstall=Start-Process -FilePath $vcRuntime -ArgumentList '/install','/quiet','/norestart' -Wait -PassThru
if($vcInstall.ExitCode -notin @(0,1638,3010)){throw "Microsoft Visual C++ runtime installation failed ($($vcInstall.ExitCode))."}

$runtimeRoot=Join-Path $installDir 'runtime'
$xamppDir=Join-Path $runtimeRoot 'xampp'
if(!(Test-Path (Join-Path $xamppDir 'apache\bin\httpd.exe'))){
    $runtimeZip=Get-ChildItem (Join-Path $PackageRoot 'runtime') -Filter 'xampp-portable-windows-*.zip'|Select-Object -First 1
    if(!$runtimeZip){throw 'Bundled Windows runtime is missing.'}
    New-Item -ItemType Directory -Force $runtimeRoot|Out-Null
    Expand-Archive -LiteralPath $runtimeZip.FullName -DestinationPath $runtimeRoot -Force
    $candidate=Get-ChildItem $runtimeRoot -Directory -Recurse|Where-Object{Test-Path (Join-Path $_.FullName 'apache\bin\httpd.exe')}|Select-Object -First 1
    if(!$candidate){throw 'Bundled Windows runtime is invalid.'}
    if($candidate.FullName -ne $xamppDir){Move-Item -LiteralPath $candidate.FullName -Destination $xamppDir -Force}
    Push-Location $xamppDir
    try{Invoke-Native (Join-Path $xamppDir 'php\php.exe') @('-n','-d','output_buffering=0','-q','install\install.php','usb')}finally{Pop-Location}
}

$appDir=Join-Path $installDir 'app'
New-Item -ItemType Directory -Force $appDir|Out-Null
& robocopy $payload $appDir /E /R:2 /W:1 /XD storage public\uploads|Out-Null
if($LASTEXITCODE -gt 7){throw "Unable to copy application files (robocopy $LASTEXITCODE)."}
Ensure-Junction (Join-Path $appDir 'storage') $storageDir
New-Item -ItemType Directory -Force (Join-Path $appDir 'public')|Out-Null
Ensure-Junction (Join-Path $appDir 'public\uploads') $uploadsDir
foreach($folder in @('logs','sessions')){New-Item -ItemType Directory -Force (Join-Path $storageDir $folder)|Out-Null}
foreach($folder in @('header','media\playlist','notifications')){New-Item -ItemType Directory -Force (Join-Path $uploadsDir $folder)|Out-Null}

$runtimeDatabase=Join-Path $xamppDir 'mysql\data'
$databaseExists=(Test-Path $databaseDir) -and (Get-ChildItem $databaseDir -Force -ErrorAction SilentlyContinue|Measure-Object).Count -gt 0
if(!$databaseExists){
    New-Item -ItemType Directory -Force $databaseDir|Out-Null
    if(Test-Path $runtimeDatabase){Copy-Item "$runtimeDatabase\*" $databaseDir -Recurse -Force}
}
Ensure-Junction $runtimeDatabase $databaseDir $databaseExists

if(!$serverIp -or $serverIp -eq 'auto'){
    $serverIp=Get-NetIPAddress -AddressFamily IPv4|Where-Object{$_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notmatch 'Loopback'}|Sort-Object InterfaceMetric|Select-Object -ExpandProperty IPAddress -First 1
}
if(!$serverIp){$serverIp='127.0.0.1'}
$appUrl="http://${serverIp}:$port"
$envStore=Join-Path $configDir '.env'
$fresh=!(Test-Path $envStore)
if($fresh){
    if($adminPassword.Length -lt 10){throw 'Administrator password must contain at least 10 characters.'}
    $dbPassword=Random-Secret 28;$displayKey=Random-Secret 32;$onlineKey=Random-Secret 40
    $envText=@"
APP_NAME="Reka Queue Management"
APP_ENV=production
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Asia/Jakarta
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reka_queue
DB_USERNAME=reka_queue
DB_PASSWORD=$dbPassword
SESSION_SECURE=false
DISPLAY_ACCESS_KEY=$displayKey
ONLINE_API_KEY=$onlineKey
"@
    [IO.File]::WriteAllText($envStore,$envText,[Text.UTF8Encoding]::new($false))
}else{
    $existingEnv=[IO.File]::ReadAllText($envStore)
    if($existingEnv -match '(?m)^APP_URL='){$existingEnv=[Text.RegularExpressions.Regex]::Replace($existingEnv,'(?m)^APP_URL=.*$',"APP_URL=$appUrl")}
    else{$existingEnv+="`r`nAPP_URL=$appUrl`r`n"}
    [IO.File]::WriteAllText($envStore,$existingEnv,[Text.UTF8Encoding]::new($false))
}
Copy-Item $envStore (Join-Path $appDir '.env') -Force
$envValues=Read-Ini $envStore
$displayKey=$envValues.DISPLAY_ACCESS_KEY

$apacheConf=Join-Path $xamppDir 'apache\conf\httpd.conf'
$rekaConf=Join-Path $xamppDir 'apache\conf\extra\reka-queue.conf'
$appPublic=(Join-Path $appDir 'public').Replace('\','/')
$vhost=@"
Listen $port
<VirtualHost *:$port>
    ServerName reka-queue.local
    DocumentRoot "$appPublic"
    <Directory "$appPublic">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog "logs/reka-queue-error.log"
    CustomLog "logs/reka-queue-access.log" common
</VirtualHost>
"@
[IO.File]::WriteAllText($rekaConf,$vhost,[Text.UTF8Encoding]::new($false))
$includeLine='Include conf/extra/reka-queue.conf'
if(!(Select-String -Path $apacheConf -SimpleMatch $includeLine -Quiet)){Add-Content $apacheConf "`r`n$includeLine"}
$phpIni=Join-Path $xamppDir 'php\php.ini'
if(!(Select-String -Path $phpIni -SimpleMatch '; Reka Queue managed settings' -Quiet)){Add-Content $phpIni "`r`n; Reka Queue managed settings`r`nupload_max_filesize=512M`r`npost_max_size=520M`r`nmax_execution_time=300`r`ndate.timezone=Asia/Jakarta`r`n"}

$apacheService='RekaQueueApache';$databaseService='RekaQueueMariaDB'
if(!(Get-Service $databaseService -ErrorAction SilentlyContinue)){Invoke-Native (Join-Path $xamppDir 'mysql\bin\mysqld.exe') @("--defaults-file=$(Join-Path $xamppDir 'mysql\bin\my.ini')",'--install',$databaseService)}
if(!(Get-Service $apacheService -ErrorAction SilentlyContinue)){Invoke-Native (Join-Path $xamppDir 'apache\bin\httpd.exe') @('-k','install','-n',$apacheService)}
sc.exe config $databaseService start= auto|Out-Null
sc.exe config $apacheService start= auto|Out-Null
Start-Service $databaseService -ErrorAction SilentlyContinue
$deadline=(Get-Date).AddSeconds(30);$mysql=Join-Path $xamppDir 'mysql\bin\mysql.exe'
do{Start-Sleep -Milliseconds 800;& $mysql -u root --execute='SELECT 1' 2>$null;if($LASTEXITCODE -eq 0){break}}while((Get-Date)-lt $deadline)
if($LASTEXITCODE -ne 0){throw 'MariaDB did not become ready.'}

if($fresh){
    $dbName='reka_queue';$dbUser='reka_queue';$dbPass=Escape-Sql $envValues.DB_PASSWORD
    $sql="CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'; CREATE USER IF NOT EXISTS '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass'; ALTER USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'; ALTER USER '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass'; GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'localhost'; GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'127.0.0.1'; FLUSH PRIVILEGES;"
    Invoke-Native $mysql @('-u','root',"--execute=$sql")
    $env:LIVE_ADMIN_PASSWORD=$adminPassword;$env:ADMIN_NAME=$adminName;$env:ADMIN_USERNAME=$adminUsername
}
try{Invoke-Native (Join-Path $xamppDir 'php\php.exe') @(Join-Path $appDir 'bin\install-live.php')}finally{Remove-Item Env:LIVE_ADMIN_PASSWORD -ErrorAction SilentlyContinue;Remove-Item Env:ADMIN_NAME -ErrorAction SilentlyContinue;Remove-Item Env:ADMIN_USERNAME -ErrorAction SilentlyContinue}
Restart-Service $apacheService -ErrorAction Stop

netsh advfirewall firewall delete rule name="Reka Queue Server"|Out-Null
netsh advfirewall firewall add rule name="Reka Queue Server" dir=in action=allow protocol=TCP localport=$port|Out-Null
Copy-Item (Join-Path $PackageRoot 'scripts\backup.ps1') (Join-Path $installDir 'tools\backup.ps1') -Force
Copy-Item (Join-Path $PackageRoot 'scripts\uninstall.ps1') (Join-Path $installDir 'tools\uninstall.ps1') -Force
$taskCommand="powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$installDir\tools\backup.ps1`" -InstallDir `"$installDir`""
schtasks /Create /TN "Reka Queue Daily Backup" /SC DAILY /ST 23:30 /TR $taskCommand /RU SYSTEM /F|Out-Null

$desktop=[Environment]::GetFolderPath('CommonDesktopDirectory');$programs=Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs\Reka Queue'
New-Item -ItemType Directory -Force $programs|Out-Null
$shortcuts=@{'Reka Queue Admin.url'="$appUrl/dashboard";'Reka Queue Kiosk.url'="$appUrl/kiosk";'Reka Queue Display.url'="$appUrl/display?key=$displayKey&fullscreen=1"}
foreach($shortcut in $shortcuts.GetEnumerator()){$text="[InternetShortcut]`r`nURL=$($shortcut.Value)`r`n";[IO.File]::WriteAllText((Join-Path $desktop $shortcut.Key),$text,[Text.UTF8Encoding]::new($false));[IO.File]::WriteAllText((Join-Path $programs $shortcut.Key),$text,[Text.UTF8Encoding]::new($false))}
[IO.File]::WriteAllText((Join-Path $installDir 'installation.txt'),"URL=$appUrl`r`nRuntime=$xamppDir`r`nData=$dataDir`r`nInstalled=$(Get-Date -Format s)`r`n",[Text.UTF8Encoding]::new($false))
Remove-Item -LiteralPath $OptionsFile -Force -ErrorAction SilentlyContinue
Write-Output "READY_URL=$appUrl"
