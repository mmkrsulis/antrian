# Reka Queue Windows Server

`RekaQueueServerSetup.exe` is a standalone, offline Windows installer. It does **not** use or require XAMPP, Docker, MariaDB, IIS, or another preinstalled web stack.

The Windows edition bundles official PHP NTS, Caddy, WinSW, and the Microsoft Visual C++ runtime. Application data is stored in SQLite WAL mode under `%ProgramData%\Reka Queue`, while `RekaQueuePHP` and `RekaQueueWeb` run as automatic Windows services.

The wizard configures the administrator account and network port. It validates the port and Caddy configuration, initializes the database, installs both services, performs an HTTP health check, creates firewall and shortcut entries, and schedules consistent daily SQLite backups. A failed health check rolls the services back.

Mutable data is separated from program files:

- `database\reka-queue.sqlite` — operational database;
- `storage` — sessions and PHP logs;
- `uploads` — headers, videos, notification audio, and speech assets;
- `config\.env` — private server configuration;
- `backups` — the latest 30 consistent database backups;
- `logs` — Caddy and service wrapper logs.

An upgrade preserves all of these directories. Uninstall offers to keep or remove them.

## Build

From the repository root:

```bash
bash deployment/build-windows-server-package.sh
```

The build downloads pinned official components, verifies every SHA-256 checksum, and builds the NSIS installer. Runtime archives and generated executables are release artifacts and are excluded from Git.

## Release gate

Do not publish a build until it passes clean Windows 10/11 installation, reboot, upgrade, uninstall/data retention, occupied-port, simultaneous queue action, and backup/restore tests. Sign the final EXE with an Authenticode certificate to replace the Windows “Unknown publisher” warning.
