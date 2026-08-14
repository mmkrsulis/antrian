# Automatic Windows clients

## Server IP/domain configuration

Edit `reka-queue-config.ini` before copying the package to the kiosk/display PCs. Change only `SERVER_URL` to the address reachable from those devices, without a trailing slash. It accepts LAN, Tailscale, public-IP, and HTTPS domain addresses. `DISPLAY_KEY` and the horizontal `MONITOR_X` position are configurable in the same file. Keep the INI beside the CMD and EXE files.

The launchers automatically use Microsoft Edge, Google Chrome, or Mozilla Firefox in that order. Edge is not required. Chrome supports the same monitor-position and autoplay flags; Firefox opens in kiosk mode but Windows may choose its monitor position.

Always right-click the downloaded ZIP and choose **Extract All** before running a CMD file. Do not run a launcher from inside the ZIP preview. The startup window now shows the selected server, detected browser, printer-agent status, and connection retries for troubleshooting.

All client computers must have Tailscale installed, signed in, and configured to start with Windows.

## Admin PC: display on monitor 2

1. Set Windows display mode to **Extend these displays**.
2. Confirm monitor 1 is 1920 pixels wide and monitor 2 is positioned to its right.
3. Copy `start-display-monitor-2.cmd` to the PC.
4. Press `Win+R`, enter `shell:startup`, and place a shortcut to the CMD file in that folder.
5. Restart Windows and confirm the display opens fullscreen on monitor 2.

The launcher uses `--window-position=1920,0`. If the primary screen has another width, replace `1920` with that width. If monitor 2 is left of the primary monitor, use a negative X coordinate such as `-1920,0`.

## Entrance PC: kiosk and automatic printing

1. Install the thermal printer and set it as the Windows default printer.
2. Configure the correct receipt paper size and disable print confirmation in the printer driver.
3. Copy `start-entrance-kiosk.cmd` to the PC.
4. Press `Win+R`, enter `shell:startup`, and place a shortcut to the CMD file in that folder.
5. Restart Windows and issue a test ticket.

The launcher starts `RekaThermalPrintAgent.exe`, a windowless local agent that sends readable ESC/POS receipts directly to the Windows default printer. Edge print preview is bypassed completely. The queue number uses a large three-times size; header, service, and footer text are wrapped to the 32-character printable width of 58 mm paper so every line remains centered. The agent feeds enough paper after the custom footer before issuing the cutter command. Keep the CMD and EXE files in the same folder. The browser print button appears only as a fallback if the local agent is unavailable.

When updating the kiosk package, extract the new ZIP over the old folder and run the new CMD once. The launcher automatically stops an older print-agent process before starting the packaged version, ensuring saved ticket header and footer settings are used.

## Operator devices

Operators do not require an automatic launcher. Open and bookmark:

`http://server-address:8090/operator`

Each operator signs in with their own account and sees only assigned services and counters.

For native notifications without an open browser, download **Windows Notification Client**
from the admin Download Client page. Configure the server URL and operator credentials once
in `reka-notifier.ini`, then run `RekaQueueNotifier.exe`. Its device token is stored in Windows
Credential Manager, the password is erased from the INI, and the notifier starts automatically.

## Exit fullscreen during maintenance

Press `Alt+F4`. The launchers run only at Windows sign-in; they do not prevent an administrator from closing the browser.
