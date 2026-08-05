# Arsitektur

Aplikasi menggunakan front controller native PHP dengan lapisan Core, Services, Views, migration SQL, serta aset lokal tanpa CDN. `QueueService` mengelola transaksi domain. Nomor harian dibuat menggunakan row lock pada `daily_sequences` dan constraint unik database. Display melakukan incremental polling berdasarkan ID event dan menyimpan ID terakhir di browser untuk mencegah pengumuman ganda.

