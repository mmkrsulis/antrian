@echo off
setlocal
set "QUEUE_URL=http://100.64.131.49:8090/kiosk"
set "EDGE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" set "EDGE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"

:wait_server
powershell -NoProfile -Command "try { $r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 '%QUEUE_URL%'; exit ([int]($r.StatusCode -ne 200)) } catch { exit 1 }"
if errorlevel 1 (
  timeout /t 5 /nobreak >nul
  goto wait_server
)

start "Entrance Kiosk" "%EDGE%" --kiosk "%QUEUE_URL%" --edge-kiosk-type=fullscreen --kiosk-printing --no-first-run --disable-session-crashed-bubble
endlocal
