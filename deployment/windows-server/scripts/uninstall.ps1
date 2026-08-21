param([Parameter(Mandatory=$true)][string]$InstallDir,[switch]$RemoveData)
$ErrorActionPreference='SilentlyContinue'
$dataDir=Join-Path $env:ProgramData 'Reka Queue'
if(Test-Path (Join-Path $InstallDir 'tools\backup.ps1')){& (Join-Path $InstallDir 'tools\backup.ps1') -InstallDir $InstallDir}
foreach($service in @('RekaQueueApache','RekaQueueMariaDB')){Stop-Service $service -Force;sc.exe delete $service|Out-Null}
schtasks /Delete /TN "Reka Queue Daily Backup" /F|Out-Null
netsh advfirewall firewall delete rule name="Reka Queue Server"|Out-Null
$desktop=[Environment]::GetFolderPath('CommonDesktopDirectory')
foreach($name in @('Reka Queue Admin.url','Reka Queue Kiosk.url','Reka Queue Display.url')){Remove-Item (Join-Path $desktop $name) -Force}
Remove-Item (Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs\Reka Queue') -Recurse -Force
if($RemoveData){Remove-Item $dataDir -Recurse -Force}
