<?php
$currentPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$layoutUser=\App\Core\Auth::user();
$isAdmin=$layoutUser&&in_array($layoutUser['role'],['super_admin','admin'],true);
$active=static fn(string $path): string => $currentPath===$path?' active':'';
?>
<!doctype html><html lang="id" translate="no" class="notranslate"><head><meta charset="utf-8"><meta name="google" content="notranslate"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e(app_name())?></title><link rel="stylesheet" href="/assets/app.css?v=<?=e((string)filemtime(__DIR__.'/../../public/assets/app.css'))?>"><link rel="stylesheet" href="/assets/creator-credit.css?v=<?=e((string)filemtime(__DIR__.'/../../public/assets/creator-credit.css'))?>"><link rel="stylesheet" href="/assets/admin-shell.css?v=<?=e((string)filemtime(__DIR__.'/../../public/assets/admin-shell.css'))?>"><style><?=theme_css_vars()?></style></head><body class="admin-shell">
<?php if($layoutUser):?>
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a href="/dashboard" class="admin-sidebar-brand"><span>RQ</span><div><b><?=e(setting('header_title',app_name()))?></b><small><?=e(setting('header_subtitle','Sistem Antrean Digital'))?></small></div></a>
        <nav class="admin-sidebar-nav">
            <p>UTAMA</p>
            <a class="<?=$active('/dashboard')?>" href="/dashboard"><span>⌂</span>Dashboard</a>
            <a class="<?=$active('/operator')?>" href="/operator"><span>◉</span>Konsol Operator</a>
            <a href="/display?key=<?=e(env('DISPLAY_ACCESS_KEY'))?>&fullscreen=1" target="_blank"><span>▣</span>Buka Display</a>
            <a class="<?=$active('/operator/apps')?>" href="/operator/apps"><span>⇩</span>Aplikasi Client</a>
            <?php if($isAdmin):?>
            <p>MANAJEMEN</p>
            <a class="<?=$active('/admin/services')?>" href="/admin/services"><span>▦</span>Layanan</a>
            <a class="<?=$active('/admin/registrations')?>" href="/admin/registrations"><span>▤</span>Pendaftaran Online</a>
            <a class="<?=$active('/admin/counters')?>" href="/admin/counters"><span>▥</span>Loket</a>
            <a class="<?=$active('/admin/users')?>" href="/admin/users"><span>♙</span>Pengguna</a>
            <p>SISTEM</p>
            <a class="<?=$active('/admin/settings')?>" href="/admin/settings"><span>⚙</span>Pengaturan</a>
            <a class="<?=$active('/reports')?>" href="/reports"><span>▤</span>Laporan</a>
            <a class="<?=$active('/admin/downloads')?>" href="/admin/downloads"><span>⇩</span>Download Client</a>
            <?php endif?>
        </nav>
        <div class="admin-sidebar-user"><div><b><?=e($layoutUser['name'])?></b><small><?=e(ucwords(str_replace('_',' ',$layoutUser['role'])))?></small></div><form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><button type="submit" title="Keluar">↪</button></form></div>
    </aside>
    <div class="admin-workspace"><main class="container admin-content"><?=$content?></main><footer class="creator-credit">Created by Sulis Setiyawan — rekakarsa</footer></div>
</div>
<?php else:?><main class="container"><?=$content?></main><footer class="creator-credit">Created by Sulis Setiyawan — rekakarsa</footer><?php endif?>
</body></html>
