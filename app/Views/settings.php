<div class="settings-heading">
    <div><h1>Pengaturan</h1><p>Kelola identitas aplikasi, tampilan, printer tiket, dan media display.</p></div>
    <?php if(isset($_GET['saved'])):?><span class="settings-saved">Pengaturan berhasil disimpan</span><?php endif?>
</div>

<div class="settings-shell">
    <aside class="settings-sidebar" aria-label="Kategori pengaturan">
        <b>MENU PENGATURAN</b>
        <nav>
            <a href="#general"><span>⚙</span><span>Umum<small>Nama aplikasi dan waktu</small></span></a>
            <a href="#appearance"><span>◫</span><span>Tampilan &amp; Header<small>Logo, gambar, dan warna</small></span></a>
            <a href="#printer"><span>▤</span><span>Printer Tiket<small>Header dan footer struk</small></span></a>
            <a href="#display-media"><span>▶</span><span>Video Display<small>Video, YouTube, dan playlist</small></span></a>
        </nav>
    </aside>

    <div class="settings-content">
        <form method="post" enctype="multipart/form-data" class="settings-category-form">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">

            <section id="general" class="card settings-section">
                <header><span>⚙</span><div><h2>Pengaturan Umum</h2><p>Identitas utama aplikasi antrean.</p></div></header>
                <div class="settings-fields">
                    <label>Nama aplikasi<input name="app_name" value="<?=e($branding['app_name']??app_name())?>" required></label>
                    <label>Judul header<input name="header_title" value="<?=e($branding['header_title']??app_name())?>"></label>
                    <label>Subjudul header<input name="header_subtitle" value="<?=e($branding['header_subtitle']??'Sistem Antrean Digital')?>"></label>
                    <label class="settings-wide">Running text display<textarea name="footer_text" rows="3" maxlength="300" required><?=e($branding['footer_text']??'Mohon menunggu nomor antrean Anda dipanggil')?></textarea></label>
                </div>
            </section>

            <section id="appearance" class="card settings-section">
                <header><span>◫</span><div><h2>Tampilan &amp; Header</h2><p>Atur gambar header dan warna aplikasi.</p></div></header>
                <div class="settings-fields">
                    <label>Mode header<select name="header_mode"><option value="text" <?=($branding['header_mode']??'text')==='text'?'selected':''?>>Teks</option><option value="image" <?=($branding['header_mode']??'')==='image'?'selected':''?>>Gambar penuh</option></select></label>
                    <label>URL/path gambar saat ini<input name="header_image_url" value="<?=e($branding['header_image_url']??'')?>"></label>
                    <label class="settings-wide">Upload gambar header<input type="file" name="header_image" accept="image/png,image/jpeg,image/webp"><small>Format JPG, PNG, atau WebP. Maksimal 10 MB.</small></label>
                    <label>Warna utama<input type="color" name="primary_color" value="<?=e($branding['primary_color']??'#075f91')?>"></label>
                    <label>Warna sekunder<input type="color" name="secondary_color" value="<?=e($branding['secondary_color']??'#1478c8')?>"></label>
                    <label>Warna aksen<input type="color" name="accent_color" value="<?=e($branding['accent_color']??'#ffd94f')?>"></label>
                </div>
            </section>

            <section id="printer" class="card settings-section">
                <header><span>▤</span><div><h2>Printer Tiket</h2><p>Konten yang dicetak pada printer thermal.</p></div></header>
                <div class="settings-fields">
                    <label class="settings-wide">Header tiket<textarea name="ticket_header" rows="4" maxlength="300" required><?=e($branding['ticket_header']??"PEMERINTAH KABUPATEN WONOGIRI\nDINAS PENDIDIKAN DAN KEBUDAYAAN")?></textarea><small>Dicetak di atas nama layanan dan nomor antrean. Mendukung beberapa baris.</small></label>
                    <label class="settings-wide">Footer tiket<textarea name="ticket_footer" rows="4" maxlength="300" required><?=e($branding['ticket_footer']??"Mohon menunggu nomor Anda dipanggil.\nTerima kasih.")?></textarea><small>Dicetak pada bagian bawah tiket sebelum kertas dipotong.</small></label>
                </div>
            </section>

            <div class="settings-savebar"><span>Perubahan umum, tampilan, dan printer disimpan bersama.</span><button type="submit">Simpan Pengaturan</button></div>
        </form>

        <section class="card settings-section compact-settings">
            <header><span>◷</span><div><h2>Jam &amp; Zona Waktu</h2><p>Digunakan oleh tiket, kiosk, display, dan antrean harian.</p></div></header>
            <form method="post" action="/admin/timezone" class="settings-inline-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><label>Zona waktu<select name="app_timezone"><option value="Asia/Jakarta" <?=app_timezone()==='Asia/Jakarta'?'selected':''?>>WIB — Jakarta (UTC+7)</option><option value="Asia/Makassar" <?=app_timezone()==='Asia/Makassar'?'selected':''?>>WITA — Makassar (UTC+8)</option><option value="Asia/Jayapura" <?=app_timezone()==='Asia/Jayapura'?'selected':''?>>WIT — Jayapura (UTC+9)</option><option value="UTC" <?=app_timezone()==='UTC'?'selected':''?>>UTC / GMT+0</option></select></label><button type="submit">Simpan Waktu</button></form>
            <?php if(isset($_GET['timezone_saved'])):?><p class="settings-success">Zona waktu berhasil disimpan.</p><?php endif?>
        </section>

        <section class="card settings-section compact-settings">
            <header><span>↕</span><div><h2>Tinggi Header</h2><p>Gunakan mode ringkas untuk layar operator dan kiosk.</p></div></header>
            <form id="header-height-settings" class="settings-inline-form"><label>Mode tinggi<select name="mode"><option value="fixed" <?=($branding['header_height_mode']??'fixed')==='fixed'?'selected':''?>>Tinggi tetap ringkas</option><option value="auto" <?=($branding['header_height_mode']??'')==='auto'?'selected':''?>>Otomatis mengikuti gambar</option></select></label><label>Tinggi tetap (60–300 px)<input type="number" name="height" min="60" max="300" value="<?=e($branding['header_height_px']??'100')?>"></label><button type="submit">Terapkan</button></form><p id="header-height-status"></p>
        </section>

        <form id="admin-display-settings" class="card settings-section" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <header id="display-media"><span>▶</span><div><h2>Video Display</h2><p>Atur sumber video yang diputar terus-menerus pada display utama.</p></div></header>
            <div class="settings-fields">
                <label>Sumber media<select name="media_type"><option value="none" <?=($branding['display_media_type']??'none')==='none'?'selected':''?>>Tanpa media</option><option value="local" <?=($branding['display_media_type']??'')==='local'?'selected':''?>>Satu video lokal</option><option value="playlist" <?=($branding['display_media_type']??'')==='playlist'?'selected':''?>>Playlist folder lokal</option><option value="youtube" <?=($branding['display_media_type']??'')==='youtube'?'selected':''?>>YouTube</option><option value="obs" <?=($branding['display_media_type']??'')==='obs'?'selected':''?>>OBS / URL streaming</option></select></label>
                <label>URL media atau path lokal<input name="media_url" value="<?=e($branding['display_media_url']??'')?>"></label>
                <label>Upload satu video lokal<input type="file" name="media_file" accept="video/mp4,video/webm,video/ogg"></label>
                <label class="settings-check"><input type="checkbox" name="media_muted" <?=($branding['display_media_muted']??'1')==='1'?'checked':''?>> Matikan suara video</label>
                <label class="settings-wide">Tambahkan video ke playlist<input type="file" name="playlist_files[]" accept="video/mp4,video/webm,video/ogg" multiple></label>
                <label class="settings-check settings-wide"><input type="checkbox" name="clear_playlist"> Hapus playlist lama sebelum upload</label>
            </div>
            <div class="playlist-files"><b>Playlist saat ini (<?=count($playlistFiles)?>)</b><?php if($playlistFiles):?><ol><?php foreach($playlistFiles as $file):?><li><?=e($file)?></li><?php endforeach?></ol><?php else:?><p>Belum ada video lokal di playlist.</p><?php endif?></div>
            <div class="settings-section-actions"><p id="admin-media-status">Playlist memutar MP4, WebM, dan OGG berdasarkan urutan nama file.</p><button type="submit">Simpan Video Display</button></div>
        </form>
    </div>
</div>

<script>window.ADMIN_CSRF=<?=json_encode(csrf_token())?>;</script><script src="/assets/admin-settings.js?v=<?=e((string)filemtime(__DIR__.'/../../public/assets/admin-settings.js'))?>"></script>
