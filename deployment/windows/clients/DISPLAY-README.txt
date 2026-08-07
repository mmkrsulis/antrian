REKA QUEUE - DISPLAY CLIENT
===========================

1. Extract every file from the ZIP into a permanent folder.
2. Edit reka-queue-config.ini once:
   - SERVER_URL: queue server address
   - DISPLAY_KEY: display access key
   - MONITOR_X: 1920 for a second monitor to the right, -1920 when it is left
3. Run RekaDisplayClient.exe.

The client opens the display in fullscreen with a dedicated persistent browser
profile. AUTO_START=1 registers the EXE for the current Windows user so the display
opens automatically after sign-in to Windows. Video position and display state are
retained by the dedicated profile until changed by the administrator.

Set AUTO_START=0 before running the EXE if automatic Windows startup is not wanted.
Microsoft Edge is preferred; Chrome and Firefox are supported as fallbacks.
