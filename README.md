# Reka Queue Management

Aplikasi antrean web berbasis PHP 8.3 dan MariaDB. Alur inti mencakup pengambilan tiket dari kiosk, pemanggilan dan pemrosesan oleh operator, display realtime dengan suara Bahasa Indonesia, administrasi layanan, dashboard, audit trail, dan laporan CSV.

## Instalasi cepat dari GitHub

Persyaratan: Linux dengan Git, OpenSSL, Docker Engine, dan Docker Compose v2.

```bash
curl -fsSL https://raw.githubusercontent.com/mmkrsulis/antrian/main/install.sh | sudo bash
```

Installer mengunduh source ke `/opt/reka-queue`, membuat seluruh password dan
kunci secara acak, menjalankan container, melakukan migrasi, lalu menampilkan
URL serta akun administrator. Installer tidak akan menimpa instalasi lama.

Untuk memeriksa script sebelum menjalankannya:

```bash
curl -fsSLO https://raw.githubusercontent.com/mmkrsulis/antrian/main/install.sh
less install.sh
sudo bash install.sh
```

Konfigurasi opsional dapat diberikan sebelum `sudo bash install.sh`:

```bash
sudo REKA_QUEUE_INSTALL_DIR=/srv/reka-queue \
  REKA_QUEUE_PORT=8090 \
  REKA_QUEUE_ADMIN_USERNAME=admin \
  bash install.sh
```

## Instalasi Docker manual

Salin `.env.example` menjadi `.env.live`, lalu isi URL, alamat bind, password database, kunci display, API key, dan `LIVE_ADMIN_PASSWORD` dengan nilai privat yang unik.

```bash
cp .env.example .env.live
docker compose --env-file .env.live up -d --build
docker compose --env-file .env.live exec -T web php /var/www/html/bin/install-live.php
```

Buka `http://server-address:8090` dari perangkat yang diizinkan. Gunakan firewall atau Tailscale ACL agar port hanya dapat diakses dari jaringan yang diperlukan.

Untuk memakai nama lokal `http://antrian.test:8090`, tambahkan baris berikut ke hosts komputer yang membuka aplikasi:

```text
server_ip antrian.test
```

Sebelum instalasi, set `LIVE_ADMIN_PASSWORD` dengan password administrator yang kuat dan unik. URL display mengikuti nilai `DISPLAY_ACCESS_KEY` pada konfigurasi server. Tidak ada password bawaan di repositori.

Stop tanpa menghapus database:

```bash
docker compose --env-file .env.live down
```

Reset total data live-test:

```bash
docker compose --env-file .env.live down -v
```

## Instalasi browser

Salin `.env.example` menjadi `.env`, isi kredensial MySQL/MariaDB, arahkan document root Apache/Nginx ke folder `public`, lalu buka `/install`. Installer memeriksa runtime, menjalankan migration, membuat data awal, dan mengunci instalasi.

## Instalasi Windows tanpa Docker atau XAMPP

Administrator dapat mengunduh `RekaQueueServerSetup.exe` dari menu **Aplikasi Client**. Edisi Windows mandiri tersebut menggunakan PHP, Caddy, dan SQLite tanpa XAMPP, Docker, atau MariaDB. Wizard menyediakan konfigurasi, Windows services, aturan firewall, backup harian, shortcut, upgrade, dan uninstaller. Data operasional disimpan terpisah di `%ProgramData%\Reka Queue` agar tetap aman ketika program diperbarui atau dihapus dengan opsi penyimpanan data.

Untuk membangun installer dari source di Linux, jalankan `bash deployment/build-windows-server-package.sh`. Build akan mengambil runtime resmi Apache Friends bila belum tersedia, memverifikasi checksum yang dipin, lalu menghasilkan `deployment/RekaQueueServerSetup.exe`.

## Pengujian

```bash
BASE_URL=http://server-address:8090 DISPLAY_ACCESS_KEY=your-private-key sh tests/critical_path.sh
```

## Pendaftaran online dan integrasi website

- Formulir pengunjung: `/online-registration`
- Check-in kode pendaftaran: `/online-check-in`
- Referensi API terpusat: [docs/api-reference.md](docs/api-reference.md)
- API untuk website PHP/WordPress: [docs/online-registration-api.md](docs/online-registration-api.md)
- PHP client siap pakai: [integrations/php/RekaQueueClient.php](integrations/php/RekaQueueClient.php)
- Plugin WordPress siap instal: `deployment/reka-queue-online-wordpress.zip`

Set `ONLINE_API_KEY` dengan nilai acak yang kuat di `.env`. API key hanya digunakan pada server website dan tidak boleh ditempatkan di JavaScript browser.

Lihat [dokumentasi deployment](docs/deployment.md), [arsitektur](docs/architecture.md), [keamanan](docs/security.md), dan [asumsi](docs/assumptions.md).

Development progress is tracked in [docs/ROADMAP.md](docs/ROADMAP.md), with executed checks recorded under `docs/verification/`.

Create operator accounts from the administrator interface and assign only the required services and counters. Development and production credentials must be supplied privately and must never be committed.

## Proyek open source

Reka Queue Management adalah perangkat lunak bebas dan open source berdasarkan
[GNU General Public License v3.0 atau versi setelahnya](LICENSE)
(`GPL-3.0-or-later`). Anda boleh menggunakan, mempelajari, memodifikasi, dan
mendistribusikannya kembali sesuai ketentuan lisensi tersebut.

Panduan kontribusi tersedia di [CONTRIBUTING.md](CONTRIBUTING.md), sedangkan
pelaporan kerentanan dijelaskan di [SECURITY.md](SECURITY.md).

Copyright © 2026 Sulis Setiyawan.
