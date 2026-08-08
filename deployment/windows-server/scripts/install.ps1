$ErrorActionPreference = 'Stop'
$packageRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

function Read-Ini([string]$path) {
    $values = @{}
    Get-Content $path | ForEach-Object {
        $line = $_.Trim()
        if ($line -and !$line.StartsWith('#') -and !$line.StartsWith(';') -and !$line.StartsWith('[') -and $line.Contains('=')) {
            $parts = $line.Split('=', 2); $values[$parts[0].Trim()] = $parts[1].Trim()
        }
    }
    return $values
}
function Random-Secret([int]$length = 32) {
    $bytes = New-Object byte[] $length
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).Replace('+','A').Replace('/','B').Replace('=','').Substring(0,$length)
}
function Escape-Sql([string]$value) { return $value.Replace("'", "''") }

$config = Read-Ini (Join-Path $packageRoot 'config.ini')
$installDir = $config.INSTALL_DIR
$xamppDir = $config.XAMPP_DIR
$port = [int]$config.SERVER_PORT
$payload = Join-Path $packageRoot 'payload\app'
if (!(Test-Path (Join-Path $payload 'public\index.php'))) { throw 'Application payload is missing. Build the Windows server package first.' }

if (!(Test-Path (Join-Path $xamppDir 'apache\bin\httpd.exe'))) {
    $runtimeZip = Get-ChildItem (Join-Path $packageRoot 'runtime') -Filter '*.zip' -ErrorAction SilentlyContinue | Select-Object -First 1
    if (!$runtimeZip) {
        Start-Process 'https://www.apachefriends.org/download.html'
        throw "XAMPP was not found at $xamppDir. Install XAMPP PHP 8.2 or add its portable ZIP to the runtime folder."
    }
    $runtimeRoot = Join-Path $installDir 'runtime'
    New-Item -ItemType Directory -Force $runtimeRoot | Out-Null
    Expand-Archive $runtimeZip.FullName $runtimeRoot -Force
    $candidate = Get-ChildItem $runtimeRoot -Directory -Recurse | Where-Object { Test-Path (Join-Path $_.FullName 'apache\bin\httpd.exe') } | Select-Object -First 1
    if (!$candidate) { throw 'The bundled runtime ZIP does not contain a valid XAMPP installation.' }
    $xamppDir = $candidate.FullName
}

New-Item -ItemType Directory -Force $installDir | Out-Null
$appDir = Join-Path $installDir 'app'
New-Item -ItemType Directory -Force $appDir | Out-Null
& robocopy $payload $appDir /E /R:2 /W:1 /XD storage\logs storage\sessions public\uploads | Out-Null
if ($LASTEXITCODE -gt 7) { throw "Unable to copy application files (robocopy $LASTEXITCODE)." }
foreach ($folder in @('storage\logs','storage\sessions','public\uploads\header','public\uploads\media\playlist')) { New-Item -ItemType Directory -Force (Join-Path $appDir $folder) | Out-Null }
foreach ($folder in @('backups','tools')) { New-Item -ItemType Directory -Force (Join-Path $installDir $folder) | Out-Null }

$serverIp = $config.SERVER_IP
if (!$serverIp -or $serverIp -eq 'auto') {
    $serverIp = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notmatch 'Loopback|Tailscale' } | Sort-Object InterfaceMetric | Select-Object -ExpandProperty IPAddress -First 1
}
if (!$serverIp) { $serverIp = '127.0.0.1' }
$appUrl = "http://${serverIp}:$port"
$dbPassword = Random-Secret 28
$displayKey = $config.DISPLAY_KEY
if (!$displayKey -or $displayKey -eq 'change-this-before-install') { $displayKey = Random-Secret 32 }
$secureAdmin = Read-Host 'New Reka Queue administrator password (minimum 10 characters)' -AsSecureString
$adminPassword = [Net.NetworkCredential]::new('', $secureAdmin).Password
if ($adminPassword.Length -lt 10) { throw 'Administrator password must contain at least 10 characters.' }

$envText = @"
APP_NAME="Reka Queue Management"
APP_ENV=production
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Asia/Jakarta
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$($config.DB_NAME)
DB_USERNAME=$($config.DB_USER)
DB_PASSWORD=$dbPassword
SESSION_SECURE=false
DISPLAY_ACCESS_KEY=$displayKey
LIVE_ADMIN_PASSWORD=$adminPassword
"@
[IO.File]::WriteAllText((Join-Path $appDir '.env'), $envText, [Text.UTF8Encoding]::new($false))

$apacheConf = Join-Path $xamppDir 'apache\conf\httpd.conf'
$rekaConf = Join-Path $xamppDir 'apache\conf\extra\reka-queue.conf'
$appPublic = (Join-Path $appDir 'public').Replace('\','/')
$vhost = @"
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
[IO.File]::WriteAllText($rekaConf, $vhost, [Text.UTF8Encoding]::new($false))
$includeLine = 'Include conf/extra/reka-queue.conf'
if (!(Select-String -Path $apacheConf -SimpleMatch $includeLine -Quiet)) { Add-Content $apacheConf "`r`n$includeLine" }

$phpIni = Join-Path $xamppDir 'php\php.ini'
$phpAppend = @"

; Reka Queue managed settings
upload_max_filesize=512M
post_max_size=520M
max_execution_time=300
date.timezone=Asia/Jakarta
"@
if (!(Select-String -Path $phpIni -SimpleMatch '; Reka Queue managed settings' -Quiet)) { Add-Content $phpIni $phpAppend }

Push-Location $xamppDir
try {
    if (!(Get-Service mysql -ErrorAction SilentlyContinue)) { & (Join-Path $xamppDir 'mysql_installservice.bat') | Out-Null }
    if (!(Get-Service Apache2.4 -ErrorAction SilentlyContinue)) { & (Join-Path $xamppDir 'apache_installservice.bat') | Out-Null }
} finally { Pop-Location }
Start-Service mysql -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3

$mysql = Join-Path $xamppDir 'mysql\bin\mysql.exe'
$dbName = Escape-Sql $config.DB_NAME; $dbUser = Escape-Sql $config.DB_USER; $dbPass = Escape-Sql $dbPassword
$sql = "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'; CREATE USER IF NOT EXISTS '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass'; ALTER USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'; ALTER USER '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass'; GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'localhost'; GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$dbUser'@'127.0.0.1'; FLUSH PRIVILEGES;"
& $mysql -u root --execute=$sql
if ($LASTEXITCODE -ne 0) { throw 'MariaDB initialization failed. If the root account has a password, use a fresh bundled XAMPP runtime.' }

& (Join-Path $xamppDir 'php\php.exe') (Join-Path $appDir 'bin\install-live.php')
if ($LASTEXITCODE -ne 0) { throw 'Application database migration failed.' }
Restart-Service Apache2.4 -ErrorAction Stop

netsh advfirewall firewall delete rule name="Reka Queue Server" | Out-Null
netsh advfirewall firewall add rule name="Reka Queue Server" dir=in action=allow protocol=TCP localport=$port | Out-Null

Copy-Item (Join-Path $packageRoot 'scripts\backup.ps1') (Join-Path $installDir 'tools\backup.ps1') -Force
Copy-Item (Join-Path $packageRoot 'Start-RekaQueue.cmd') (Join-Path $installDir 'Start-RekaQueue.cmd') -Force
$taskCommand = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$installDir\tools\backup.ps1`""
schtasks /Create /TN "Reka Queue Daily Backup" /SC DAILY /ST $config.BACKUP_TIME /TR $taskCommand /RU SYSTEM /F | Out-Null

$desktop = [Environment]::GetFolderPath('CommonDesktopDirectory')
$shortcuts = @{'Reka Queue Admin.url'="$appUrl/dashboard";'Reka Queue Kiosk.url'="$appUrl/kiosk";'Reka Queue Display.url'="$appUrl/display?key=$displayKey&fullscreen=1"}
foreach ($shortcut in $shortcuts.GetEnumerator()) {
    [IO.File]::WriteAllText((Join-Path $desktop $shortcut.Key), "[InternetShortcut]`r`nURL=$($shortcut.Value)`r`n", [Text.UTF8Encoding]::new($false))
}
[IO.File]::WriteAllText((Join-Path $installDir 'installation.txt'), "URL=$appUrl`r`nDisplay=$appUrl/display?key=$displayKey`r`nXAMPP=$xamppDir`r`nInstalled=$(Get-Date -Format s)`r`n", [Text.UTF8Encoding]::new($false))
Write-Host "`nReka Queue is ready at $appUrl" -ForegroundColor Green
Start-Process $appUrl
