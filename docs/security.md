# Keamanan

- PDO native prepared statements dan output escaping digunakan untuk input/output dinamis.
- Seluruh mutasi browser dilindungi CSRF token.
- Password disimpan memakai `password_hash`; session ID diregenerasi saat login.
- Cookie bersifat HttpOnly dan SameSite Lax; aktifkan `SESSION_SECURE=true` di HTTPS.
- Display memerlukan access key. Gunakan nilai acak yang unik pada setiap deployment.
- `.env`, `.env.live`, upload, database volume, session, dan log tidak boleh dimasukkan ke version control.
- Distribusi publik tidak menyertakan URL server, password, API key, atau konfigurasi instansi.
- Jika sebuah credential pernah masuk ke Git, anggap credential tersebut bocor dan lakukan rotasi meskipun commit sudah dihapus.
- Stack trace disembunyikan saat `APP_DEBUG=false` dan dicatat ke `storage/logs`.
- Hak akses operator/admin diperiksa pada endpoint server, bukan hanya antarmuka.
- Operator service assignments are enforced server-side through `user_services`; hiding a service in the browser is not the authorization boundary.
