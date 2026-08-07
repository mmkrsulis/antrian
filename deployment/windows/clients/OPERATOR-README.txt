REKA QUEUE - OPERATOR CLIENT
============================

1. Extract every file from the ZIP into a permanent folder.
2. Edit reka-queue-config.ini once and set SERVER_URL to the queue server address.
3. Run RekaOperatorClient.exe.
4. Sign in once and leave "remember this device" enabled.

The client uses a dedicated browser profile and renews a revocable one-year device
token. It never stores the username or password in a file. Logging out from the app
revokes the token. AUTO_START=1 registers the EXE for the current Windows user so
the operator console opens automatically after sign-in to Windows.

Set AUTO_START=0 before running the EXE if automatic Windows startup is not wanted.
Microsoft Edge is preferred; Chrome and Firefox are supported as fallbacks.
