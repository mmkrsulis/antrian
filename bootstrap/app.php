<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
});

foreach ([dirname(__DIR__) . '/.env', dirname(__DIR__) . '/.env.live'] as $envFile) {
    if (!is_file($envFile)) continue;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) !== false) continue;
        $value = trim($value, "\"'");
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');
ini_set('display_errors', (getenv('APP_DEBUG') === 'true') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');

$sessionLifetime=60*60*24*30;
ini_set('session.gc_maxlifetime',(string)$sessionLifetime);
$sessionPath=dirname(__DIR__).'/storage/sessions';
if(!is_dir($sessionPath))mkdir($sessionPath,0775,true);
session_save_path($sessionPath);
session_name('queue_session');
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'httponly' => true,
    'secure' => getenv('SESSION_SECURE') === 'true',
    'samesite' => 'Lax',
    'path' => '/',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function env(string $key, mixed $default = null): mixed {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function csrf_token(): string {
    return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
}

function e(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(string $key, mixed $default = null): mixed {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try { $stmt=\App\Core\Database::connection()->prepare('SELECT `value` FROM settings WHERE `key`=?'); $stmt->execute([$key]); $value=$stmt->fetchColumn(); return $cache[$key]=$value===false?$default:$value; }
    catch (Throwable) { return $default; }
}

function app_name(): string { return (string) setting('app_name', env('APP_NAME','Reka Queue Management')); }
function app_timezone(): string {
    $timezone=(string)setting('app_timezone',env('APP_TIMEZONE','Asia/Jakarta'));
    return in_array($timezone,['Asia/Jakarta','Asia/Makassar','Asia/Jayapura','UTC'],true)?$timezone:'Asia/Jakarta';
}
date_default_timezone_set(app_timezone());
function indonesian_date(?int $timestamp = null): string {
    $timestamp ??= time();
    $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return $days[(int) date('w', $timestamp)].', '.date('d', $timestamp).' '.$months[(int) date('n', $timestamp)].' '.date('Y', $timestamp);
}
function theme_css_vars(): string {
    $color=static function(string $key,string $fallback): string { $value=(string)setting($key,$fallback); return preg_match('/^#[0-9a-fA-F]{6}$/',$value)?$value:$fallback; };
    return ':root{--brand-primary:'.$color('primary_color','#075f91').';--brand-secondary:'.$color('secondary_color','#1478c8').';--brand-accent:'.$color('accent_color','#ffd94f').';}';
}

function image_header_enabled(): bool { return setting('header_mode','text')==='image' && header_image_url()!==''; }
function header_image_url(): string {
    $url=(string)setting('header_image_url','');
    if($url!=='' && (str_starts_with($url,'/') || filter_var($url,FILTER_VALIDATE_URL))) return str_replace(['"','\\'], '', $url);
    return '';
}
function header_background_style(): string {
    if(!image_header_enabled()) return '';
    $ratio=7.68; $url=header_image_url();
    if(str_starts_with($url,'/uploads/header/')) { $path=dirname(__DIR__).'/public'.$url; $size=is_file($path)?@getimagesize($path):false; if($size&&$size[1]>0)$ratio=$size[0]/$size[1]; }
    $mode=(string)setting('header_height_mode','fixed'); $height=max(60,min(300,(int)setting('header_height_px','100')));
    $sizeStyle=$mode==='fixed'?';height:'.$height.'px!important;aspect-ratio:auto':'';
    return 'background-image:url("'.$url.'");--header-aspect:'.number_format($ratio,4,'.','').$sizeStyle;
}
