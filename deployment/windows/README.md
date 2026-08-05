# Automatic Windows clients

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

The `--kiosk-printing` flag sends the ticket directly to the default printer without showing print preview.

## Operator devices

Operators do not require an automatic launcher. Open and bookmark:

`http://100.64.131.49:8090/operator`

Each operator signs in with their own account and sees only assigned services and counters.

## Exit fullscreen during maintenance

Press `Alt+F4`. The launchers run only at Windows sign-in; they do not prevent an administrator from closing the browser.
