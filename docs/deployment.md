# Deployment

## Docker live-test

Jalankan perintah di README. Database berada di named volume `queue-management-live_queue_db_data` dan tidak hilang saat restart biasa. Konfigurasi live-test mengikat port `8090` ke IP Tailscale `100.64.131.49`; ubah `QUEUE_BIND_IP` bila alamat node berubah.

The verified development endpoint is `http://100.64.131.49:8090`, available only to devices connected to the same tailnet.

For automatic printing without a browser print dialog, start the kiosk browser with `--kiosk --kiosk-printing` and set the thermal printer as the operating-system default printer. The application triggers `window.print()` immediately after ticket issuance and returns to the kiosk after printing.

## Display media and branding

Administrators configure display media under **Pengaturan**. Supported sources are a single uploaded MP4/WebM/OGG file, every supported video in the local playlist folder, a YouTube URL, or a browser-compatible OBS/stream URL. Playlist files are stored under `public/uploads/media/playlist` and play in filename order. The branding panel switches the display header between text and a full-width JPG/PNG/WebP image. Press F11 on kiosk, operator, or display devices for browser full-screen mode; no full-screen control is rendered in the application.

Administrators manage counters under **Loket**. Each counter has a custom name/code, active status, and one or more assigned services. The operator service selector follows the selected counter assignment, and the API rejects calls outside that assignment.

## Apache/XAMPP

1. Salin project ke `htdocs/antrian` dan pastikan `mod_rewrite` aktif.
2. Untuk subdirectory, arahkan Alias/VirtualHost ke `antrian/public`; jangan mengekspos folder aplikasi lain.
3. Buat database utf8mb4, salin `.env.example` menjadi `.env`, dan ubah kredensial.
4. Pastikan `storage` writable, lalu buka `/install`.

## Nginx + PHP-FPM

Arahkan `root` ke folder `public`, gunakan `try_files $uri $uri/ /index.php?$query_string`, dan kirim file PHP ke PHP-FPM 8.1+. Aktifkan HTTPS, set `APP_URL`, `SESSION_SECURE=true`, serta access key display acak.

## DNS publik

Buat record A/AAAA subdomain menuju IP server, pasang TLS, lalu reverse-proxy ke port container. Kredensial DNS dan nama domain diperlukan sebelum langkah ini dapat dilakukan.
