@echo off
setlocal
set "SERVER_URL=http://100.64.131.49:8090"
set "CONFIG=%~dp0reka-queue-config.ini"
if exist "%CONFIG%" for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%CONFIG%") do if not "%%A"=="" set "%%A=%%B"
set "QUEUE_URL=%SERVER_URL%/kiosk?directprint=1"
set "BROWSER_KIND=edge"
set "BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if not exist "%BROWSER%" (set "BROWSER_KIND=chrome"& set "BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe")
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" set "BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" (set "BROWSER_KIND=firefox"& set "BROWSER=%ProgramFiles%\Mozilla Firefox\firefox.exe")
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe"
if not exist "%BROWSER%" (powershell -NoProfile -Command "Add-Type -AssemblyName PresentationFramework; [System.Windows.MessageBox]::Show('Install Microsoft Edge, Google Chrome, or Mozilla Firefox first.','Reka Queue')" & exit /b 1)
set "AGENT=%~dp0RekaThermalPrintAgent.exe"

start "Reka Thermal Print Agent" /min "%AGENT%"

:wait_server
powershell -NoProfile -Command "try { $r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 '%QUEUE_URL%'; exit ([int]($r.StatusCode -ne 200)) } catch { exit 1 }"
if errorlevel 1 (
  timeout /t 5 /nobreak >nul
  goto wait_server
)

set "KIOSK_PROFILE=%LOCALAPPDATA%\RekaQueue\KioskProfile"
if "%BROWSER_KIND%"=="firefox" (start "Entrance Kiosk" "%BROWSER%" --kiosk "%QUEUE_URL%") else (start "Entrance Kiosk" "%BROWSER%" --user-data-dir="%KIOSK_PROFILE%" --kiosk "%QUEUE_URL%" --edge-kiosk-type=fullscreen --kiosk-printing --disable-print-preview --no-first-run --no-default-browser-check --disable-session-crashed-bubble)
endlocal
