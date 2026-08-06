@echo off
setlocal
set "SERVER_URL=http://100.64.131.49:8090"
set "DISPLAY_KEY=reka-display-wonogiri"
set "MONITOR_X=1920"
set "CONFIG=%~dp0reka-queue-config.ini"
if exist "%CONFIG%" for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%CONFIG%") do if not "%%A"=="" set "%%A=%%B"
set "QUEUE_URL=%SERVER_URL%/display?key=%DISPLAY_KEY%"
set "EDGE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" set "EDGE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"

:wait_server
powershell -NoProfile -Command "try { $r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 '%QUEUE_URL%'; exit ([int]($r.StatusCode -ne 200)) } catch { exit 1 }"
if errorlevel 1 (
  timeout /t 5 /nobreak >nul
  goto wait_server
)

start "Queue Display" "%EDGE%" --kiosk "%QUEUE_URL%" --edge-kiosk-type=fullscreen --autoplay-policy=no-user-gesture-required --no-first-run --disable-session-crashed-bubble --window-position=%MONITOR_X%,0
endlocal
