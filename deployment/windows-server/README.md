# Reka Queue Windows Server

`RekaQueueServerSetup.exe` is a self-contained offline Windows installer. End users do not install Docker, XAMPP, PHP, Apache, MariaDB, or the Visual C++ runtime separately.

The setup wizard asks for the installation directory, initial administrator identity/password, and server port. It then installs dedicated `RekaQueueApache` and `RekaQueueMariaDB` Windows services, configures Windows Firewall, initializes the application, schedules daily backups, registers Start Menu/Desktop shortcuts, and appears in Windows Installed Apps with an uninstaller.

Mutable data is stored under `%ProgramData%\Reka Queue`:

- `storage` — sessions, logs, and the installation lock;
- `uploads` — headers, videos, notification audio, and speech assets;
- `mariadb` — database files;
- `config` — private server environment configuration;
- `backups` — retained daily SQL backups.

An upgrade detects this data and leaves accounts, settings, uploads, and queue history unchanged. Uninstall attempts a final backup and offers to retain or permanently remove the operational data.

## Build

Run from the repository root:

```bash
bash deployment/build-windows-server-package.sh
```

The build script downloads the pinned official Apache Friends portable Windows runtime when needed, validates its SHA-256 checksum, compiles the NSIS wizard, and produces `deployment/RekaQueueServerSetup.exe`. The runtime and generated EXE are release artifacts and are intentionally excluded from Git.

## Signing

The generated installer is functional without a certificate, but Windows SmartScreen can show **Unknown publisher**. Sign the release EXE with an Authenticode code-signing certificate before distributing it broadly.
