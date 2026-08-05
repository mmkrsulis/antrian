# Keamanan

- PDO native prepared statements dan output escaping digunakan untuk input/output dinamis.
- Seluruh mutasi browser dilindungi CSRF token.
- Password disimpan memakai `password_hash`; session ID diregenerasi saat login.
- Cookie bersifat HttpOnly dan SameSite Lax; aktifkan `SESSION_SECURE=true` di HTTPS.
- Display memerlukan access key. Ganti nilai bawaan untuk deployment selain live-test lokal.
- Stack trace disembunyikan saat `APP_DEBUG=false` dan dicatat ke `storage/logs`.
- Hak akses operator/admin diperiksa pada endpoint server, bukan hanya antarmuka.
- Operator service assignments are enforced server-side through `user_services`; hiding a service in the browser is not the authorization boundary.
