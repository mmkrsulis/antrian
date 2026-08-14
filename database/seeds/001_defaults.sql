INSERT IGNORE INTO settings (`key`, `value`) VALUES
('institution_name', 'Reka Queue Management'), ('institution_address', 'Alamat instansi'),
('announcement_template', 'Nomor antrean {ticket}, silakan menuju {counter}'), ('voice_rate', '0.9'), ('voice_pitch', '1'),
('display_media_type', 'none'), ('display_media_url', ''), ('display_media_muted', '1'),
('header_mode', 'text'), ('header_image_url', ''), ('header_title', 'Reka Queue Management'), ('header_subtitle', 'Sistem Antrean Digital'),
('footer_text', 'Mohon menunggu nomor antrean Anda dipanggil'), ('app_name', 'Reka Queue Management'),
('ticket_header', 'NAMA INSTANSI\nSISTEM ANTREAN DIGITAL'),
('ticket_footer', 'Mohon menunggu nomor Anda dipanggil.\nTerima kasih.'),
('primary_color', '#075f91'), ('secondary_color', '#1478c8'), ('accent_color', '#ffd94f');
INSERT IGNORE INTO settings (`key`,`value`) VALUES ('header_height_mode','fixed'),('header_height_px','100');
INSERT IGNORE INTO services (id, name, code, description, color, avg_service_minutes) VALUES
(1, 'Layanan Umum', 'A', 'Layanan umum dan informasi', '#1677b8', 7),
(2, 'Pendaftaran', 'B', 'Pendaftaran dan penerimaan pengunjung', '#0f9f78', 7),
(3, 'Administrasi', 'C', 'Pelayanan administrasi', '#7456b8', 8),
(4, 'Konsultasi', 'D', 'Pelayanan konsultasi', '#d97706', 8),
(5, 'Perizinan', 'E', 'Pelayanan perizinan', '#2563eb', 7),
(6, 'Pengaduan', 'F', 'Pelayanan pengaduan masyarakat', '#be185d', 7),
(7, 'Pembayaran', 'G', 'Pelayanan pembayaran', '#0891b2', 7),
(8, 'Dokumen', 'H', 'Pengambilan dan penyerahan dokumen', '#4d7c0f', 7),
(9, 'Prioritas', 'P', 'Pelayanan antrean prioritas', '#b45309', 8),
(10, 'Layanan Lainnya', 'Z', 'Layanan tambahan', '#7c3aed', 8);
INSERT IGNORE INTO counters (id, name, code) VALUES (1, 'Loket 1', 'L1'), (2, 'Loket 2', 'L2'), (3, 'Loket 3', 'L3');
INSERT IGNORE INTO counter_services (counter_id, service_id) VALUES (1,1), (1,2), (2,1), (2,2);
INSERT IGNORE INTO counter_services (counter_id, service_id) VALUES (3,1), (3,2);
