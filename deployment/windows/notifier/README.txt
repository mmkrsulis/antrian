REKA QUEUE WINDOWS NOTIFIER
===========================

1. Extract this ZIP into a permanent folder, for example C:\RekaQueueNotifier.
2. Open reka-notifier.ini with Notepad.
3. Set SERVER_URL, USERNAME, and PASSWORD, then save it.
4. Run RekaQueueNotifier.exe.

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
