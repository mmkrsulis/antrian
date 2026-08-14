@echo off
setlocal
title Reka Queue - Display Startup
color 1F
echo =============================================
echo   REKA QUEUE - DISPLAY STARTUP
echo =============================================
set "SERVER_URL=http://server-address:8090"
set "DISPLAY_KEY=change-this-display-key"
set "MONITOR_X=1920"
set "CONFIG=%~dp0reka-queue-config.ini"
if exist "%CONFIG%" for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%CONFIG%") do if not "%%A"=="" set "%%A=%%B"
set "QUEUE_URL=%SERVER_URL%/display?key=%DISPLAY_KEY%"
set "BROWSER_KIND=edge"
set "BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if not exist "%BROWSER%" (set "BROWSER_KIND=chrome"& set "BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe")
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" set "BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" (set "BROWSER_KIND=firefox"& set "BROWSER=%ProgramFiles%\Mozilla Firefox\firefox.exe")
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles(x86)%\Mozilla Firefox\firefox.exe"
if not exist "%BROWSER%" (powershell -NoProfile -Command "Add-Type -AssemblyName PresentationFramework; [System.Windows.MessageBox]::Show('Install Microsoft Edge, Google Chrome, or Mozilla Firefox first.','Reka Queue')" & exit /b 1)

echo Server  : %SERVER_URL%
echo Browser : %BROWSER_KIND% - %BROWSER%
echo Monitor : X position %MONITOR_X%

:wait_server
echo Checking server %QUEUE_URL% ...
powershell -NoProfile -Command "try { $r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 '%QUEUE_URL%'; exit ([int]($r.StatusCode -ne 200)) } catch { exit 1 }"
if errorlevel 1 (
  echo Server is not reachable. Check SERVER_URL and DISPLAY_KEY in reka-queue-config.ini.
  echo Retrying in 5 seconds. Press Ctrl+C to stop.
  timeout /t 5 /nobreak >nul
  goto wait_server
)

echo Server connected. Opening display fullscreen...
if "%BROWSER_KIND%"=="firefox" (start "Queue Display" "%BROWSER%" --kiosk "%QUEUE_URL%") else (start "Queue Display" "%BROWSER%" --kiosk "%QUEUE_URL%" --edge-kiosk-type=fullscreen --autoplay-policy=no-user-gesture-required --no-first-run --disable-session-crashed-bubble --window-position=%MONITOR_X%,0)
if errorlevel 1 (echo Failed to launch browser. & pause & exit /b 1)
endlocal
