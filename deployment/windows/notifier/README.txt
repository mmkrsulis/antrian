REKA QUEUE WINDOWS NOTIFIER
===========================

1. Run RekaQueueNotifierSetup.exe.
2. Complete the setup wizard with the server URL, username, and password.
3. Select Save & Connect. The notifier then stays in the Windows system tray.

The password is used once and automatically removed from the INI after registration.
The generated device token is stored in Windows Credential Manager. The notifier
starts automatically at Windows sign-in and keeps working when the browser is closed.

Tray menu:
- Click the tray icon to view connection, account, server, and device status.
- Open operator console: open the web operator page.
- Settings: edit the local server and account configuration.
- Change operator account: remove the saved Windows credential and register again.
- Exit: close the notifier until the next Windows sign-in.

Windows notifications must be enabled for Reka Queue Notifier in Windows Settings.
To remove it, open Windows Settings > Apps > Installed apps, find Reka Queue
Notifier, open its menu, and choose Uninstall.
