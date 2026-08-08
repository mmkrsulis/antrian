@echo off
net start mysql >nul 2>&1
net start Apache2.4 >nul 2>&1
for /f "tokens=2 delims==" %%A in ('findstr /b "APP_URL=" "C:\RekaQueue\app\.env"') do start "" "%%A"

