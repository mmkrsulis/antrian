# Reka Queue Management

Aplikasi antrean web berbasis PHP 8.3 dan MariaDB. Alur inti mencakup pengambilan tiket dari kiosk, pemanggilan dan pemrosesan oleh operator, display realtime dengan suara Bahasa Indonesia, administrasi layanan, dashboard, audit trail, dan laporan CSV.

## Live test dengan Docker

```bash
docker compose up -d --build
docker compose exec -T web php /var/www/html/bin/install-live.php
```

Buka `http://your_ip:8090` dari perangkat yang sudah login ke tailnet yang sama. Port web di-bind hanya ke IP Tailscale server agar tidak ikut terbuka pada interface publik/LAN.

Untuk memakai nama lokal `http://antrian.test:8090`, tambahkan baris berikut ke hosts komputer yang membuka aplikasi:

```text
your_ip: antrian.test
```

Akun awal: `admin` / `AdminLive123!`. Ganti password segera. URL display mengikuti nilai `DISPLAY_ACCESS_KEY` pada konfigurasi server.

Stop tanpa menghapus database:

```bash
docker compose down
```

Reset total data live-test:

```bash
docker compose down -v
```

## Instalasi browser

Salin `.env.example` menjadi `.env`, isi kredensial MySQL/MariaDB, arahkan document root Apache/Nginx ke folder `public`, lalu buka `/install`. Installer memeriksa runtime, menjalankan migration, membuat data awal, dan mengunci instalasi.

## Pengujian

```bash
BASE_URL=http://your_ip::8090 sh tests/critical_path.sh
```

## Pendaftaran online dan integrasi website

- Formulir pengunjung: `/online-registration`
- Check-in kode pendaftaran: `/online-check-in`
- API untuk website PHP/WordPress: [docs/online-registration-api.md](docs/online-registration-api.md)
- PHP client siap pakai: [integrations/php/RekaQueueClient.php](integrations/php/RekaQueueClient.php)

Set `ONLINE_API_KEY` dengan nilai acak yang kuat di `.env`. API key hanya digunakan pada server website dan tidak boleh ditempatkan di JavaScript browser.

Lihat [dokumentasi deployment](docs/deployment.md), [arsitektur](docs/architecture.md), [keamanan](docs/security.md), dan [asumsi](docs/assumptions.md).

Development progress is tracked in [docs/ROADMAP.md](docs/ROADMAP.md), with executed checks recorded under `docs/verification/`.

Live-test scoped operator account: `operator-ptk` / `OperatorPTK123!`. It is assigned only to Loket PTK and the two PTK services. Replace or disable this development credential before any non-development deployment.
