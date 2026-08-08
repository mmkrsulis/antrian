# Reka Queue Windows Server

This package installs Reka Queue on a normal Windows clinic/hospital PC without Docker commands.

## Build modes

- Existing runtime: install XAMPP 8.2 to `C:\xampp`, then run `Install-RekaQueue.cmd`.
- Offline clinic package: put the official XAMPP Windows portable ZIP inside `runtime/`, run the repository build script, and distribute the resulting ZIP.

Run `Install-RekaQueue.cmd` as Administrator. The installer configures Apache on port 8090, MariaDB, firewall access, application migrations, Windows services, daily backups, and desktop shortcuts. It asks for the first administrator password during installation.

After installation, other LAN devices open `http://SERVER-IP:8090`. Keep the server PC on, use a static/reserved LAN IP, and copy `C:\RekaQueue\backups` to external storage periodically.

