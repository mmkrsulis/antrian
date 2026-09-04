<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Database;
use App\Core\View;
use App\Services\QueueService;
use App\Services\OnlineRegistrationService;
use App\Services\TicketPdf;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$json = static function(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
$redirect = static function(string $to): never { header("Location: {$to}"); exit; };
$input = static function(): array { $raw=json_decode(file_get_contents('php://input'),true); return is_array($raw)?$raw:$_POST; };
$csrf = static function(array $data) use ($json): void { if (!hash_equals(csrf_token(), (string)($data['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) $json(['error'=>'Token keamanan tidak valid.'],419); };
$publicApiAuth = static function() use ($json): string {
    $key=trim((string)($_SERVER['HTTP_X_API_KEY']??''));if($key==='')$json(['error'=>'X-API-Key wajib dikirim.'],401);
    $configured=(string)env('ONLINE_API_KEY','');$db=Database::connection();$client='env';$valid=$configured!==''&&hash_equals($configured,$key);
    if(!$valid){$stmt=$db->prepare('SELECT id,name,allowed_origin FROM api_clients WHERE key_hash=? AND active=1');$stmt->execute([hash('sha256',$key)]);$row=$stmt->fetch();if($row){$origin=(string)($_SERVER['HTTP_ORIGIN']??'');if($row['allowed_origin']&&$origin!==''&&!hash_equals((string)$row['allowed_origin'],$origin))$json(['error'=>'Origin tidak diizinkan.'],403);$valid=true;$client='db-'.$row['id'];$db->prepare('UPDATE api_clients SET last_used_at=NOW() WHERE id=?')->execute([$row['id']]);}}
    if(!$valid)$json(['error'=>'API key tidak valid.'],401);
    $window=date('Y-m-d H:i:00');$bucket=hash('sha256',$client.'|'.($_SERVER['REMOTE_ADDR']??'unknown'));$db->prepare('INSERT INTO api_rate_limits(client_key,window_start,request_count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1')->execute([$bucket,$window]);$count=$db->prepare('SELECT request_count FROM api_rate_limits WHERE client_key=? AND window_start=?');$count->execute([$bucket,$window]);if((int)$count->fetchColumn()>60)$json(['error'=>'Batas permintaan terlampaui. Coba kembali satu menit lagi.'],429);return $client;
};
$notificationDeviceAuth = static function() use ($json): array {
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');$deviceToken=trim((string)($_SERVER['HTTP_X_DEVICE_TOKEN']??''));
    if($deviceToken===''&&preg_match('/^Bearer\s+([a-f0-9]{64})$/i',$authorization,$match))$deviceToken=$match[1];
    if(!preg_match('/^[a-f0-9]{64}$/i',$deviceToken))$json(['error'=>'Token perangkat wajib dikirim.'],401);
    $db=Database::connection();$stmt=$db->prepare('SELECT nd.id device_id,u.id user_id,u.name,u.role FROM notification_devices nd JOIN users u ON u.id=nd.user_id WHERE nd.token_hash=? AND nd.expires_at>NOW() AND u.active=1 LIMIT 1');$stmt->execute([hash('sha256',strtolower($deviceToken))]);$device=$stmt->fetch();
    if(!$device)$json(['error'=>'Token perangkat tidak valid atau sudah kedaluwarsa.'],401);
    $db->prepare('UPDATE notification_devices SET last_used_at=NOW() WHERE id=?')->execute([$device['device_id']]);return $device;
};
$configuredClientDownloads=['reka-queue-windows-startup.zip','reka-display-startup.zip','reka-kiosk-printer.zip','reka-operator-client.zip','reka-display-client.zip'];
$requestServerUrl=static function(): string {
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]);
    $scheme=in_array($forwarded,['http','https'],true)?$forwarded:((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http');
    $host=trim((string)($_SERVER['HTTP_HOST']??''));
    if($host===''||!preg_match('/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\])(?::\d{1,5})?$/',$host)){
        $fallback=(string)env('APP_URL','http://127.0.0.1:8090');
        return rtrim($fallback,'/');
    }
    return $scheme.'://'.$host;
};
$configuredClientArchive=static function(string $source,string $serverUrl): string {
    if(!class_exists(ZipArchive::class))throw new RuntimeException('ZIP extension is unavailable.');
    $temporary=tempnam(sys_get_temp_dir(),'reka-client-');
    if($temporary===false||!copy($source,$temporary))throw new RuntimeException('Unable to prepare client download.');
    $zip=new ZipArchive();
    if($zip->open($temporary)!==true){@unlink($temporary);throw new RuntimeException('Unable to open client package.');}
    $config="[client]\r\nSERVER_URL=".rtrim($serverUrl,'/')."\r\nDISPLAY_KEY=".(string)env('DISPLAY_ACCESS_KEY','')."\r\nMONITOR_X=1920\r\nAUTO_START=1\r\n";
    if(!$zip->addFromString('reka-queue-config.ini',$config)){$zip->close();@unlink($temporary);throw new RuntimeException('Unable to configure client package.');}
    $zip->close();return $temporary;
};

try {
    if ($path === '/health') { try { Database::connection()->query('SELECT 1'); $json(['status'=>'ok']); } catch (Throwable) { $json(['status'=>'starting'],503); } }
    $downloadFiles=['/downloads/RekaQueueServerSetup.exe'=>'RekaQueueServerSetup.exe','/downloads/reka-queue-windows-startup.zip'=>'reka-queue-windows-startup.zip','/downloads/reka-display-startup.zip'=>'reka-display-startup.zip','/downloads/reka-kiosk-printer.zip'=>'reka-kiosk-printer.zip','/downloads/reka-operator-client.zip'=>'reka-operator-client.zip','/downloads/RekaQueueNotifierSetup.exe'=>'RekaQueueNotifierSetup.exe','/downloads/RekaQueueNotifier.apk'=>'RekaQueueNotifier.apk','/downloads/reka-queue-notifier-linux.deb'=>'reka-queue-notifier-linux.deb','/downloads/reka-windows-notifier.zip'=>'reka-windows-notifier.zip','/downloads/reka-display-client.zip'=>'reka-display-client.zip','/downloads/reka-queue-online-wordpress.zip'=>'reka-queue-online-wordpress.zip'];
    if (isset($downloadFiles[$path]) && in_array($method, ['GET','HEAD'], true)) {
        $downloadName=$downloadFiles[$path];$file=dirname(__DIR__).'/deployment/'.$downloadName;
        if (!is_file($file)) { http_response_code(404); exit('Download tidak ditemukan.'); }
        $temporary=null;
        if(in_array($downloadName,$configuredClientDownloads,true)){
            Auth::require(['super_admin','admin']);
            $temporary=$configuredClientArchive($file,$requestServerUrl());$file=$temporary;
        }
        $mime=match(pathinfo($downloadName,PATHINFO_EXTENSION)){'exe'=>'application/vnd.microsoft.portable-executable','apk'=>'application/vnd.android.package-archive','deb'=>'application/vnd.debian.binary-package',default=>'application/zip'};
        header('Content-Type: '.$mime);
        header('Content-Disposition: attachment; filename="'.$downloadName.'"');
        header('Content-Length: '.filesize($file));
        header('Cache-Control: no-store');
        if ($method === 'GET') { set_time_limit(0); readfile($file); }
        if($temporary!==null)@unlink($temporary);
        exit;
    }
    if ($path === '/install') {
        if (is_file(dirname(__DIR__).'/storage/install.lock')) $redirect('/login');
        $requirements=['PHP >= 8.1'=>version_compare(PHP_VERSION,'8.1','>='),'PDO MySQL'=>extension_loaded('pdo_mysql'),'Storage writable'=>is_writable(dirname(__DIR__).'/storage')];
        if ($method==='POST') {
            $csrf($_POST); $db=Database::connection();
            foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) $db->exec(file_get_contents($file));
            foreach (glob(dirname(__DIR__).'/database/seeds/*.sql') as $file) $db->exec(file_get_contents($file));
            $username=trim($_POST['username']??'admin'); $password=(string)($_POST['password']??'');
            if (strlen($password)<10) throw new RuntimeException('Password minimal 10 karakter.');
            $stmt=$db->prepare("INSERT INTO users(name,username,password_hash,role) VALUES (?,?,?,'super_admin') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)");
            $stmt->execute([trim($_POST['name']??'Administrator'),$username,password_hash($password,PASSWORD_DEFAULT)]);
            file_put_contents(dirname(__DIR__).'/storage/install.lock',date(DATE_ATOM)); $redirect('/login?installed=1');
        }
        View::render('install',compact('requirements')); exit;
    }
    if (!is_file(dirname(__DIR__).'/storage/install.lock')) $redirect('/install');

    if($path==='/api/client/notifications/register'&&$method==='POST'){
        $data=$input();$username=trim((string)($data['username']??''));$password=(string)($data['password']??'');$deviceId=substr(preg_replace('/[^a-zA-Z0-9._-]/','',(string)($data['device_id']??'')),0,100);$deviceName=substr(trim((string)($data['device_name']??'Windows Operator')),0,150);
        if($username===''||$password===''||$deviceId==='')$json(['error'=>'Username, password, dan ID perangkat wajib diisi.'],422);
        $db=Database::connection();$stmt=$db->prepare("SELECT id,name,password_hash FROM users WHERE username=? AND active=1 AND role IN ('super_admin','admin','operator') LIMIT 1");$stmt->execute([$username]);$user=$stmt->fetch();if(!$user||!password_verify($password,$user['password_hash'])){usleep(500000);$json(['error'=>'Username atau password salah.'],401);}
        $token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',time()+365*86400);$save=$db->prepare('INSERT INTO notification_devices(user_id,device_id,device_name,token_hash,expires_at,last_used_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE device_name=VALUES(device_name),token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),last_used_at=NOW()');$save->execute([$user['id'],$deviceId,$deviceName,hash('sha256',$token),$expires]);$cursor=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM queue_events')->fetchColumn();
        $json(['data'=>['token'=>$token,'cursor'=>$cursor,'operator'=>$user['name'],'expires_at'=>$expires]]);
    }
    if($path==='/api/client/notifications'&&$method==='GET'){
        $device=$notificationDeviceAuth();$after=max(0,(int)($_GET['after']??0));$db=Database::connection();$cursor=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM queue_events')->fetchColumn();
        if($device['role']==='operator'){$stmt=$db->prepare("SELECT e.id,t.id ticket_id,t.ticket_number,t.service_id,t.sub_service_name,s.name service_name,t.status FROM queue_events e JOIN tickets t ON t.id=e.ticket_id JOIN services s ON s.id=t.service_id JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE e.id>? AND e.event_type='issued' AND t.queue_date=CURDATE() ORDER BY e.id LIMIT 50");$stmt->execute([$device['user_id'],$after]);$counts=$db->prepare("SELECT t.service_id,s.name service_name,COUNT(*) waiting FROM tickets t JOIN services s ON s.id=t.service_id JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') GROUP BY t.service_id,s.name ORDER BY s.name");$counts->execute([$device['user_id']]);}
        else{$stmt=$db->prepare("SELECT e.id,t.id ticket_id,t.ticket_number,t.service_id,t.sub_service_name,s.name service_name,t.status FROM queue_events e JOIN tickets t ON t.id=e.ticket_id JOIN services s ON s.id=t.service_id WHERE e.id>? AND e.event_type='issued' AND t.queue_date=CURDATE() ORDER BY e.id LIMIT 50");$stmt->execute([$after]);$counts=$db->query("SELECT t.service_id,s.name service_name,COUNT(*) waiting FROM tickets t JOIN services s ON s.id=t.service_id WHERE t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') GROUP BY t.service_id,s.name ORDER BY s.name");}
        $settingsStmt=$db->prepare('SELECT enabled,sound_type,sound_url,volume,play_mode FROM user_notification_settings WHERE user_id=?');$settingsStmt->execute([$device['user_id']]);$settings=$settingsStmt->fetch()?:['enabled'=>1,'sound_type'=>'chime','sound_url'=>'','volume'=>'0.80','play_mode'=>'auto'];$json(['cursor'=>$cursor,'tickets'=>$stmt->fetchAll(),'waiting_counts'=>$counts->fetchAll(),'settings'=>$settings]);
    }
    if($path==='/api/client/operator-state'&&$method==='GET'){
        $device=$notificationDeviceAuth();$db=Database::connection();
        if($device['role']==='operator'){$services=$db->prepare('SELECT s.id,s.name,s.code FROM services s JOIN user_services us ON us.service_id=s.id WHERE us.user_id=? AND s.active=1 ORDER BY s.name');$services->execute([$device['user_id']]);$counters=$db->prepare('SELECT DISTINCT c.id,c.name FROM counters c JOIN counter_services cs ON cs.counter_id=c.id JOIN user_services us ON us.service_id=cs.service_id AND us.user_id=? JOIN users u ON u.id=? WHERE c.active=1 AND (u.assigned_counter_id IS NULL OR u.assigned_counter_id=c.id) ORDER BY c.name');$counters->execute([$device['user_id'],$device['user_id']]);}
        else{$services=$db->query('SELECT id,name,code FROM services WHERE active=1 ORDER BY name');$counters=$db->query('SELECT id,name FROM counters WHERE active=1 ORDER BY name');}
        $serviceId=max(0,(int)($_GET['service_id']??0));$counterId=max(0,(int)($_GET['counter_id']??0));$current=null;$waiting=0;
        if($serviceId){if($device['role']==='operator'){$allowed=$db->prepare('SELECT COUNT(*) FROM user_services WHERE user_id=? AND service_id=?');$allowed->execute([$device['user_id'],$serviceId]);if(!(int)$allowed->fetchColumn())$json(['error'=>'Layanan tidak diizinkan.'],403);}$wait=$db->prepare("SELECT COUNT(*) FROM tickets WHERE service_id=? AND queue_date=CURDATE() AND status IN ('waiting','skipped')");$wait->execute([$serviceId]);$waiting=(int)$wait->fetchColumn();$active=$db->prepare("SELECT t.id,t.ticket_number,t.sub_service_name,t.status,s.name service_name,c.name counter_name FROM tickets t JOIN services s ON s.id=t.service_id LEFT JOIN counters c ON c.id=t.counter_id WHERE t.service_id=? AND t.queue_date=CURDATE() AND t.status IN ('called','serving')".($counterId?' AND t.counter_id=?':'').' ORDER BY t.updated_at DESC LIMIT 1');$active->execute($counterId?[$serviceId,$counterId]:[$serviceId]);$current=$active->fetch()?:null;}
        $json(['data'=>['services'=>$services->fetchAll(),'counters'=>$counters->fetchAll(),'waiting'=>$waiting,'current'=>$current]]);
    }
    if($path==='/api/client/operator-action'&&$method==='POST'){
        $device=$notificationDeviceAuth();$data=$input();$action=(string)($data['action']??'');$serviceId=(int)($data['service_id']??0);$counterId=(int)($data['counter_id']??0);$ticketId=(int)($data['ticket_id']??0);$db=Database::connection();
        if($device['role']==='operator'){$allowed=$db->prepare('SELECT COUNT(*) FROM user_services WHERE user_id=? AND service_id=?');$allowed->execute([$device['user_id'],$serviceId]);if(!(int)$allowed->fetchColumn())$json(['error'=>'Layanan tidak diizinkan.'],403);$counter=$db->prepare('SELECT COUNT(*) FROM counters c JOIN counter_services cs ON cs.counter_id=c.id JOIN users u ON u.id=? WHERE c.id=? AND cs.service_id=? AND c.active=1 AND (u.assigned_counter_id IS NULL OR u.assigned_counter_id=c.id)');$counter->execute([$device['user_id'],$counterId,$serviceId]);if(!(int)$counter->fetchColumn())$json(['error'=>'Loket tidak diizinkan.'],403);}
        $queue=new QueueService;if($action==='next')$ticket=$queue->callNext($counterId,$serviceId,(int)$device['user_id']);else{if(!in_array($action,['recall','serve','complete','skip','cancel'],true))$json(['error'=>'Aksi tidak valid.'],422);$belongs=$db->prepare('SELECT COUNT(*) FROM tickets WHERE id=? AND service_id=? AND queue_date=CURDATE()');$belongs->execute([$ticketId,$serviceId]);if(!(int)$belongs->fetchColumn())$json(['error'=>'Antrean tidak ditemukan.'],404);$ticket=$queue->transition($ticketId,$action,$counterId,(int)$device['user_id']);}$json(['data'=>$ticket]);
    }
    if($path==='/api/client/notification-audio'&&$method==='POST'){
        $device=$notificationDeviceAuth();$db=Database::connection();
        if(!isset($_FILES['sound_file'])||($_FILES['sound_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)$json(['error'=>'Pilih file audio yang valid.'],422);
        if(($_FILES['sound_file']['size']??0)>10*1024*1024)$json(['error'=>'Ukuran audio maksimal 10 MB.'],422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['sound_file']['tmp_name']);$formats=['audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg','application/ogg'=>'ogg'];
        if(!isset($formats[$mime]))$json(['error'=>'Audio harus berformat MP3, WAV, atau OGG.'],422);
        $dir=dirname(__DIR__).'/public/uploads/notifications';if(!is_dir($dir))mkdir($dir,0775,true);$hash=substr(hash_file('sha256',$_FILES['sound_file']['tmp_name']),0,16);$target=$dir.'/user-'.$device['user_id'].'-'.$hash.'.'.$formats[$mime];
        if(!is_file($target)&&!move_uploaded_file($_FILES['sound_file']['tmp_name'],$target))$json(['error'=>'Audio custom gagal disimpan.'],500);
        foreach(glob($dir.'/user-'.$device['user_id'].'-*.*')?:[] as $old)if(is_file($old)&&$old!==$target)unlink($old);foreach(glob($dir.'/user-'.$device['user_id'].'.*')?:[] as $legacy)if(is_file($legacy)&&$legacy!==$target)unlink($legacy);
        $soundUrl='/uploads/notifications/'.basename($target);$save=$db->prepare("INSERT INTO user_notification_settings(user_id,enabled,sound_type,sound_url,volume,play_mode) VALUES (?,1,'custom',?,0.8,'auto') ON DUPLICATE KEY UPDATE sound_type='custom',sound_url=VALUES(sound_url)");$save->execute([$device['user_id'],$soundUrl]);
        $json(['data'=>['sound_type'=>'custom','sound_url'=>$soundUrl]]);
    }
    if($path==='/api/client/notification-settings'){
        $device=$notificationDeviceAuth();$db=Database::connection();$stmt=$db->prepare('SELECT enabled,sound_type,sound_url,volume,play_mode FROM user_notification_settings WHERE user_id=?');
        if($method==='POST'){$data=$input();$enabled=filter_var($data['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0;$soundType=(string)($data['sound_type']??'chime');$volume=max(0,min(1,(float)($data['volume']??.8)));$playMode=(string)($data['play_mode']??'auto');if(!in_array($soundType,['chime','bell','beep','custom'],true))$json(['error'=>'Jenis suara tidak valid.'],422);if(!in_array($playMode,['auto','persistent'],true))$json(['error'=>'Mode notifikasi tidak valid.'],422);$stmt->execute([$device['user_id']]);$existing=$stmt->fetch()?:[];$soundUrl=(string)($existing['sound_url']??'');if($soundType==='custom'&&$soundUrl==='')$json(['error'=>'Upload suara custom terlebih dahulu melalui pengaturan web.'],422);$save=$db->prepare('INSERT INTO user_notification_settings(user_id,enabled,sound_type,sound_url,volume,play_mode) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),sound_type=VALUES(sound_type),sound_url=VALUES(sound_url),volume=VALUES(volume),play_mode=VALUES(play_mode)');$save->execute([$device['user_id'],$enabled,$soundType,$soundUrl,$volume,$playMode]);}
        $stmt->execute([$device['user_id']]);$settings=$stmt->fetch()?:['enabled'=>1,'sound_type'=>'chime','sound_url'=>'','volume'=>'0.80','play_mode'=>'auto'];$json(['data'=>$settings]);
    }

    if ($path==='/api/kiosk/session'&&$method==='GET') {$json(['csrf'=>csrf_token(),'expires_in'=>2592000]);}

    if ($path==='/api/public/services'&&$method==='GET') {$publicApiAuth();$json(['data'=>(new OnlineRegistrationService)->services()]);}
    if ($path==='/api/public/availability'&&$method==='GET') {$publicApiAuth();$json(['data'=>(new OnlineRegistrationService)->availability((int)($_GET['service_id']??0),(string)($_GET['date']??''))]);}
    if ($path==='/api/public/registrations'&&$method==='POST') {$client=$publicApiAuth();$registration=(new OnlineRegistrationService)->register($input(),'api_'.$client);$json(['data'=>$registration],201);}
    if (preg_match('#^/api/public/registrations/(RQ-[A-Z0-9]{8})$#',$path,$m)&&$method==='GET') {$publicApiAuth();$json(['data'=>(new OnlineRegistrationService)->find($m[1])]);}
    if ($path==='/api/public/check-in'&&$method==='POST') {$publicApiAuth();$data=$input();$json(['data'=>(new OnlineRegistrationService)->checkIn((string)($data['registration_code']??''))]);}

    if ($path==='/online-registration') {
        $service=new OnlineRegistrationService;$services=$service->services();$error=null;
        if($method==='POST'){try{$csrf($_POST);$registration=$service->register($_POST);$redirect('/online-registration/success/'.rawurlencode($registration['registration_code']));}catch(Throwable $e){$error=$e->getMessage();}}
        View::render('online-registration',compact('services','error'),false);exit;
    }
    if(preg_match('#^/online-registration/success/(RQ-[A-Z0-9]{8})$#',$path,$m)){$registration=(new OnlineRegistrationService)->find($m[1]);View::render('online-registration-success',compact('registration'),false);exit;}
    if($path==='/online-check-in'){$service=new OnlineRegistrationService;$error=null;$registration=null;if($method==='POST'){try{$csrf($_POST);$registration=$service->checkIn((string)($_POST['registration_code']??''));}catch(Throwable $e){$error=$e->getMessage();}}View::render('online-check-in',compact('registration','error'),false);exit;}

    if ($path === '/login') {
        $operatorClient=(string)($_POST['client']??$_GET['client']??'')==='operator';
        if ($method==='POST') { $csrf($_POST); if(Auth::attempt(trim($_POST['username']??''),(string)($_POST['password']??''),isset($_POST['remember']))) $redirect($operatorClient?'/operator':'/dashboard'); $error='Username atau password salah.'; }
        View::render('login',compact('error','operatorClient')); exit;
    }
    if ($path === '/logout' && $method==='POST') { $csrf($_POST); Auth::logout(); $redirect('/login'); }
    if ($path === '/') $redirect('/kiosk');

    if ($path === '/kiosk') {
        $db=Database::connection();$services=$db->query("SELECT s.*,COUNT(t.id) waiting FROM services s LEFT JOIN tickets t ON t.service_id=s.id AND t.queue_date=CURDATE() AND t.status IN ('waiting','called') WHERE s.active=1 GROUP BY s.id ORDER BY s.id")->fetchAll();
        $subRows=$db->query('SELECT ss.id,ss.service_id,ss.name,ss.description FROM sub_services ss JOIN services s ON s.id=ss.service_id WHERE ss.active=1 AND s.active=1 ORDER BY ss.service_id,ss.sort_order,ss.name')->fetchAll();$subServices=[];foreach($subRows as $row)$subServices[(string)$row['service_id']][]=$row;
        View::render('kiosk',compact('services','subServices'),false); exit;
    }
    if ($path === '/api/tickets' && $method==='POST') { $data=$input(); $csrf($data); $subServiceId=(int)($data['sub_service_id']??0);$ticket=(new QueueService)->issue((int)($data['service_id']??0),$subServiceId?:null,true); $json(['data'=>$ticket],201); }
    if (preg_match('#^/ticket/([a-f0-9-]+)\.pdf$#',$path,$m)) { $ticket=(new QueueService)->ticket($m[1]);$pdf=TicketPdf::render($ticket);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Z0-9-]/i','',(string)$ticket['ticket_number']).'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;exit; }
    if (preg_match('#^/ticket/([a-f0-9-]+)$#',$path,$m)) { $ticket=(new QueueService)->ticket($m[1]); View::render('ticket',compact('ticket'),false); exit; }
    if ($path === '/display' || $path === '/operator/display') {
        $displayScope='all';
        if($path==='/operator/display'){$displayUser=Auth::require(['super_admin','admin','operator']);$displayScope=($displayUser['role']==='operator'&&($_GET['scope']??'')==='mine')?'mine':'all';$key=(string)env('DISPLAY_ACCESS_KEY');}
        else{$key=(string)($_GET['key']??'');if(!hash_equals((string)env('DISPLAY_ACCESS_KEY'),$key)){http_response_code(403);exit('Kunci display tidak valid.');}}
        View::render('display',['displayKey'=>$key,'displayScope'=>$displayScope],false); exit;
    }
    if ($path === '/api/display/events') {
        $scopeMine=($_GET['scope']??'')==='mine';$scopeIds=[];$scopeUser=null;
        if($scopeMine){$scopeUser=Auth::require(['super_admin','admin','operator']);if($scopeUser['role']==='operator'){$allowed=Database::connection()->prepare('SELECT service_id FROM user_services WHERE user_id=?');$allowed->execute([$scopeUser['id']]);$scopeIds=array_map('intval',$allowed->fetchAll(PDO::FETCH_COLUMN));}}
        else{$key=(string)($_GET['key']??'');if(!hash_equals((string)env('DISPLAY_ACCESS_KEY'),$key))$json(['error'=>'Unauthorized'],401);}
        $after=max(0,(int)($_GET['after']??0));
        $scopeClause='';$scopeParams=[];if($scopeMine&&$scopeUser['role']==='operator'){$scopeClause=$scopeIds?' AND t.service_id IN ('.implode(',',array_fill(0,count($scopeIds),'?')).')':' AND 1=0';$scopeParams=$scopeIds;}
        $stmt=Database::connection()->prepare("SELECT e.id,e.event_type,e.created_at,t.ticket_number,t.service_id,c.name counter_name FROM queue_events e JOIN tickets t ON t.id=e.ticket_id LEFT JOIN counters c ON c.id=e.counter_id WHERE e.id>? AND e.event_type IN ('called','recall')$scopeClause ORDER BY e.id ASC LIMIT 50");$stmt->execute(array_merge([$after],$scopeParams));
        $recentStmt=Database::connection()->prepare("SELECT t.ticket_number,t.service_id,c.name counter_name,t.called_at FROM tickets t JOIN counters c ON c.id=t.counter_id WHERE t.queue_date=CURDATE() AND t.called_at IS NOT NULL$scopeClause ORDER BY t.called_at DESC LIMIT 8");$recentStmt->execute($scopeParams);$recent=$recentStmt->fetchAll();
        $summaryScope='';$summaryParams=[];if($scopeMine&&$scopeUser['role']==='operator'){$summaryScope=$scopeIds?' AND s.id IN ('.implode(',',array_fill(0,count($scopeIds),'?')).')':' AND 1=0';$summaryParams=$scopeIds;}
        $summaryStmt=Database::connection()->prepare("SELECT s.id,s.name,s.code,s.color,COUNT(t.id) total,COALESCE(SUM(t.status IN ('waiting','skipped')),0) waiting,(SELECT t2.ticket_number FROM tickets t2 WHERE t2.service_id=s.id AND t2.queue_date=CURDATE() AND t2.called_at IS NOT NULL ORDER BY t2.called_at DESC,t2.id DESC LIMIT 1) current_number,(SELECT c2.name FROM tickets t3 JOIN counters c2 ON c2.id=t3.counter_id WHERE t3.service_id=s.id AND t3.queue_date=CURDATE() AND t3.called_at IS NOT NULL ORDER BY t3.called_at DESC,t3.id DESC LIMIT 1) counter_name FROM services s LEFT JOIN tickets t ON t.service_id=s.id AND t.queue_date=CURDATE() WHERE s.active=1$summaryScope GROUP BY s.id ORDER BY s.id");$summaryStmt->execute($summaryParams);$summary=$summaryStmt->fetchAll();
        $settingRows=Database::connection()->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('display_media_type','display_media_url','display_media_muted','header_mode','header_image_url','header_title','header_subtitle','footer_text')")->fetchAll();
        $screenSettings=array_column($settingRows,'value','key');
        $playlistFiles=[];foreach(glob(dirname(__DIR__).'/public/uploads/media/playlist/*.{mp4,webm,ogv}',GLOB_BRACE)?:[] as $file)$playlistFiles[]='/uploads/media/playlist/'.rawurlencode(basename($file));sort($playlistFiles);
        $json(['events'=>$stmt->fetchAll(),'recent'=>$recent,'summary'=>$summary,'scope'=>$scopeMine?'mine':'all','media'=>['type'=>$screenSettings['display_media_type']??'none','url'=>$screenSettings['display_media_url']??'','muted'=>($screenSettings['display_media_muted']??'1')==='1','playlist'=>$playlistFiles],'header'=>['mode'=>$screenSettings['header_mode']??'text','image_url'=>$screenSettings['header_image_url']??'','title'=>$screenSettings['header_title']??app_name(),'subtitle'=>$screenSettings['header_subtitle']??'Sistem Antrean Digital'],'footer_text'=>$screenSettings['footer_text']??'Mohon menunggu nomor antrean Anda dipanggil']);
    }
    if ($path === '/api/display/speech' && $method === 'GET') {
        $key=(string)($_GET['key']??'');if(!hash_equals((string)env('DISPLAY_ACCESS_KEY'),$key)){http_response_code(401);exit;}
        $ticket=strtoupper(trim((string)($_GET['ticket']??'')));$counterName=trim((string)($_GET['counter']??'Loket pelayanan'));
        if(!preg_match('/^[A-Z0-9 -]{1,20}$/',$ticket)||$counterName===''||mb_strlen($counterName)>80){http_response_code(422);exit;}
        $spokenTicket=implode(', ',array_map(static fn(string $char): string => ['0'=>'nol','1'=>'satu','2'=>'dua','3'=>'tiga','4'=>'empat','5'=>'lima','6'=>'enam','7'=>'tujuh','8'=>'delapan','9'=>'sembilan','A'=>'a','B'=>'be','C'=>'ce','D'=>'de','E'=>'e','F'=>'ef','G'=>'ge','H'=>'ha','I'=>'i','J'=>'je','K'=>'ka','L'=>'el','M'=>'em','N'=>'en','O'=>'o','P'=>'pe','Q'=>'ki','R'=>'er','S'=>'es','T'=>'te','U'=>'u','V'=>'ve','W'=>'we','X'=>'eks','Y'=>'ye','Z'=>'zet'][$char]??$char,str_split(str_replace(['-',' '],'',$ticket))));
        $text='Nomor antrean, '.$spokenTicket.', silakan menuju '.$counterName.'.';$cacheDir=dirname(__DIR__).'/public/uploads/speech';$cacheFile=$cacheDir.'/'.hash('sha256',$text).'.wav';
        if(!is_file($cacheFile)){$temporary=$cacheFile.'.tmp-'.bin2hex(random_bytes(4));$command='espeak-ng -v id -s 132 -p 48 -a 170 -w '.escapeshellarg($temporary).' '.escapeshellarg($text);exec($command,$output,$exitCode);if($exitCode!==0||!is_file($temporary)||filesize($temporary)<1000){@unlink($temporary);http_response_code(503);exit;}rename($temporary,$cacheFile);}
        header('Content-Type: audio/wav');header('Content-Length: '.filesize($cacheFile));header('Cache-Control: public, max-age=31536000, immutable');readfile($cacheFile);exit;
    }

    if ($path === '/dashboard') {
        $user=Auth::require(); $db=Database::connection(); $allowedServices=[];
        if($user['role']==='operator'){
            $statsStmt=$db->prepare("SELECT COUNT(*) total,COALESCE(SUM(t.status='waiting'),0) waiting,COALESCE(SUM(t.status='serving'),0) serving,COALESCE(SUM(t.status='completed'),0) completed FROM tickets t JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE()");
            $statsStmt->execute([$user['id']]);$stats=$statsStmt->fetch();
            $servicesStmt=$db->prepare('SELECT s.name FROM services s JOIN user_services us ON us.service_id=s.id WHERE us.user_id=? AND s.active=1 ORDER BY s.name');
            $servicesStmt->execute([$user['id']]);$allowedServices=$servicesStmt->fetchAll(PDO::FETCH_COLUMN);
        }else{$stats=$db->query("SELECT COUNT(*) total,COALESCE(SUM(status='waiting'),0) waiting,COALESCE(SUM(status='serving'),0) serving,COALESCE(SUM(status='completed'),0) completed FROM tickets WHERE queue_date=CURDATE()")->fetch();}
        View::render('dashboard',compact('user','stats','allowedServices')); exit;
    }
    if ($path === '/operator') {
        $user=Auth::require(['super_admin','admin','operator']);
        $db=Database::connection();
        if($user['role']==='operator'){
            $counterStmt=$db->prepare('SELECT c.*,GROUP_CONCAT(cs.service_id ORDER BY cs.service_id) service_ids FROM counters c LEFT JOIN counter_services cs ON cs.counter_id=c.id WHERE c.active=1 AND (? IS NULL OR c.id=?) GROUP BY c.id ORDER BY c.name');$counterStmt->execute([$user['assigned_counter_id'],$user['assigned_counter_id']]);$counters=$counterStmt->fetchAll();
            $serviceStmt=$db->prepare('SELECT s.* FROM services s JOIN user_services us ON us.service_id=s.id WHERE us.user_id=? AND s.active=1 ORDER BY s.name');$serviceStmt->execute([$user['id']]);$services=$serviceStmt->fetchAll();
        }else{$counters=$db->query('SELECT c.*,GROUP_CONCAT(cs.service_id ORDER BY cs.service_id) service_ids FROM counters c LEFT JOIN counter_services cs ON cs.counter_id=c.id WHERE c.active=1 GROUP BY c.id ORDER BY c.name')->fetchAll();$services=$db->query('SELECT * FROM services WHERE active=1 ORDER BY name')->fetchAll();}
        if($user['role']==='operator'){$currentStmt=$db->prepare("SELECT t.*,s.name service_name,c.name counter_name FROM tickets t JOIN services s ON s.id=t.service_id JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? LEFT JOIN counters c ON c.id=t.counter_id WHERE t.queue_date=CURDATE() AND t.status IN ('called','serving') AND (? IS NULL OR t.counter_id=?) ORDER BY t.updated_at DESC LIMIT 20");$currentStmt->execute([$user['id'],$user['assigned_counter_id'],$user['assigned_counter_id']]);$current=$currentStmt->fetchAll();}
        else{$current=$db->query("SELECT t.*,s.name service_name,c.name counter_name FROM tickets t JOIN services s ON s.id=t.service_id LEFT JOIN counters c ON c.id=t.counter_id WHERE t.queue_date=CURDATE() AND t.status IN ('called','serving') ORDER BY t.updated_at DESC LIMIT 20")->fetchAll();}
        if($user['role']==='operator'){$waitingStmt=$db->prepare("SELECT t.service_id,COUNT(*) waiting FROM tickets t JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') GROUP BY t.service_id");$waitingStmt->execute([$user['id']]);}
        else{$waitingStmt=$db->query("SELECT service_id,COUNT(*) waiting FROM tickets WHERE queue_date=CURDATE() AND status IN ('waiting','skipped') GROUP BY service_id");}
        $waitingCounts=array_column($waitingStmt->fetchAll(),'waiting','service_id');
        if($user['role']==='operator'){$subCountStmt=$db->prepare("SELECT ss.service_id,ss.id sub_service_id,ss.name sub_service_name,COUNT(t.id) waiting FROM sub_services ss JOIN user_services us ON us.service_id=ss.service_id AND us.user_id=? LEFT JOIN tickets t ON t.sub_service_id=ss.id AND t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') WHERE ss.active=1 GROUP BY ss.service_id,ss.id,ss.name,ss.sort_order ORDER BY ss.service_id,ss.sort_order,ss.name");$subCountStmt->execute([$user['id']]);}
        else $subCountStmt=$db->query("SELECT ss.service_id,ss.id sub_service_id,ss.name sub_service_name,COUNT(t.id) waiting FROM sub_services ss LEFT JOIN tickets t ON t.sub_service_id=ss.id AND t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') WHERE ss.active=1 GROUP BY ss.service_id,ss.id,ss.name,ss.sort_order ORDER BY ss.service_id,ss.sort_order,ss.name");
        $subServiceCounts=[];foreach($subCountStmt->fetchAll() as $row)$subServiceCounts[(string)$row['service_id']][]=['id'=>$row['sub_service_id'],'name'=>$row['sub_service_name'],'waiting'=>(int)$row['waiting']];
        $notificationStmt=$db->prepare('SELECT enabled,sound_type,sound_url,volume,play_mode FROM user_notification_settings WHERE user_id=?');$notificationStmt->execute([$user['id']]);$notificationSettings=$notificationStmt->fetch()?:['enabled'=>1,'sound_type'=>'chime','sound_url'=>'','volume'=>'0.80','play_mode'=>'auto'];$notificationCursor=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM tickets')->fetchColumn();
        View::render('operator',compact('user','counters','services','current','waitingCounts','subServiceCounts','notificationSettings','notificationCursor'),false); exit;
    }
    if ($path === '/operator/notifications') {
        $user=Auth::require(['super_admin','admin','operator']);$stmt=Database::connection()->prepare('SELECT enabled,sound_type,sound_url,volume,play_mode FROM user_notification_settings WHERE user_id=?');$stmt->execute([$user['id']]);$notificationSettings=$stmt->fetch()?:['enabled'=>1,'sound_type'=>'chime','sound_url'=>'','volume'=>'0.80','play_mode'=>'auto'];View::render('operator-notifications',compact('user','notificationSettings'),false);exit;
    }
    if ($path === '/operator/apps') {
        $user=Auth::require(['super_admin','admin','operator']);if(in_array($user['role'],['super_admin','admin'],true))$redirect('/admin/downloads');View::render('operator-apps',compact('user'));exit;
    }
    if ($path === '/api/operator/session' && $method==='GET') {$user=Auth::user();if(!$user)$json(['error'=>'Sesi operator berakhir. Silakan masuk kembali.'],401);$json(['authenticated'=>true,'csrf'=>csrf_token(),'expires_in'=>2592000]);}
    if ($path === '/api/operator/next' && $method==='POST') {
        $user=Auth::require(['super_admin','admin','operator']); $data=$input(); $csrf($data);
        $ticket=(new QueueService)->callNext((int)$data['counter_id'],(int)$data['service_id'],(int)$user['id']);
        $json(['data'=>$ticket]);
    }
    if ($path === '/api/operator/notifications') {
        $user=Auth::require(['super_admin','admin','operator']);$after=max(0,(int)($_GET['after']??0));$db=Database::connection();$cursor=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM tickets')->fetchColumn();
        if($user['role']==='operator'){$stmt=$db->prepare("SELECT t.id,t.service_id,t.ticket_number,t.sub_service_name,t.created_at,s.name service_name FROM tickets t JOIN services s ON s.id=t.service_id JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.id>? AND t.queue_date=CURDATE() AND t.status='waiting' ORDER BY t.id LIMIT 20");$stmt->execute([$user['id'],$after]);$countsStmt=$db->prepare("SELECT t.service_id,COUNT(*) waiting FROM tickets t JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') GROUP BY t.service_id");$countsStmt->execute([$user['id']]);$subCountsStmt=$db->prepare("SELECT ss.service_id,ss.id sub_service_id,ss.name sub_service_name,COUNT(t.id) waiting FROM sub_services ss JOIN user_services us ON us.service_id=ss.service_id AND us.user_id=? LEFT JOIN tickets t ON t.sub_service_id=ss.id AND t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') WHERE ss.active=1 GROUP BY ss.service_id,ss.id,ss.name,ss.sort_order ORDER BY ss.service_id,ss.sort_order,ss.name");$subCountsStmt->execute([$user['id']]);}
        else{$stmt=$db->prepare("SELECT t.id,t.service_id,t.ticket_number,t.sub_service_name,t.created_at,s.name service_name FROM tickets t JOIN services s ON s.id=t.service_id WHERE t.id>? AND t.queue_date=CURDATE() AND t.status='waiting' ORDER BY t.id LIMIT 20");$stmt->execute([$after]);$countsStmt=$db->query("SELECT service_id,COUNT(*) waiting FROM tickets WHERE queue_date=CURDATE() AND status IN ('waiting','skipped') GROUP BY service_id");$subCountsStmt=$db->query("SELECT ss.service_id,ss.id sub_service_id,ss.name sub_service_name,COUNT(t.id) waiting FROM sub_services ss LEFT JOIN tickets t ON t.sub_service_id=ss.id AND t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') WHERE ss.active=1 GROUP BY ss.service_id,ss.id,ss.name,ss.sort_order ORDER BY ss.service_id,ss.sort_order,ss.name");}
        $subServiceCounts=[];foreach($subCountsStmt->fetchAll() as $row)$subServiceCounts[(string)$row['service_id']][]=['id'=>$row['sub_service_id'],'name'=>$row['sub_service_name'],'waiting'=>(int)$row['waiting']];$json(['cursor'=>$cursor,'tickets'=>$stmt->fetchAll(),'waiting_counts'=>array_column($countsStmt->fetchAll(),'waiting','service_id'),'sub_service_counts'=>$subServiceCounts]);
    }
    if ($path === '/api/operator/notification-settings' && $method==='POST') {
        $user=Auth::require(['super_admin','admin','operator']);$csrf($_POST);$enabled=isset($_POST['enabled'])?1:0;$soundType=(string)($_POST['sound_type']??'chime');$volume=max(0,min(1,(float)($_POST['volume']??0.8)));$playMode=(string)($_POST['play_mode']??'auto');if(!in_array($soundType,['chime','bell','beep','custom'],true))throw new RuntimeException('Notification sound is invalid.');if(!in_array($playMode,['auto','persistent'],true))throw new RuntimeException('Notification play mode is invalid.');$db=Database::connection();$stmt=$db->prepare('SELECT sound_url FROM user_notification_settings WHERE user_id=?');$stmt->execute([$user['id']]);$soundUrl=(string)($stmt->fetchColumn()?:'');
        $hasUpload=isset($_FILES['sound_file'])&&($_FILES['sound_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;$trimStart=trim((string)($_POST['trim_start']??''));$trimEnd=trim((string)($_POST['trim_end']??''));$wantsTrim=$trimStart!==''||$trimEnd!=='';
        if($hasUpload||$wantsTrim){$dir=dirname(__DIR__).'/public/uploads/notifications';if(!is_dir($dir))mkdir($dir,0775,true);$source=null;$temporarySource=false;
            if($hasUpload){if($_FILES['sound_file']['error']!==UPLOAD_ERR_OK||$_FILES['sound_file']['size']>10*1024*1024)throw new RuntimeException('Sound upload failed or exceeds 10 MB.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['sound_file']['tmp_name']);$formats=['audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg'];if(!isset($formats[$mime]))throw new RuntimeException('Custom sound must be MP3, WAV, or OGG.');$source=$_FILES['sound_file']['tmp_name'];}
            elseif($soundUrl!==''&&preg_match('#^/uploads/notifications/(user-'.$user['id'].'-[a-f0-9]{16}\.(?:mp3|wav|ogg))$#',$soundUrl,$match)&&is_file($dir.'/'.$match[1]))$source=$dir.'/'.$match[1];
            if(!$source)throw new RuntimeException('Upload a sound file before cropping.');
            if($wantsTrim){if($trimStart===''||$trimEnd===''||!is_numeric($trimStart)||!is_numeric($trimEnd))throw new RuntimeException('Crop start and end must both be filled in seconds.');$start=max(0,(float)$trimStart);$end=(float)$trimEnd;if($end<=$start)throw new RuntimeException('Crop end must be greater than crop start.');if($end-$start>300)throw new RuntimeException('Cropped audio may not exceed 5 minutes.');$probe=shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '.escapeshellarg($source));$duration=(float)trim((string)$probe);if($duration<=0||$start>=$duration||$end>$duration+0.05)throw new RuntimeException('Crop range exceeds the audio duration of '.number_format($duration,2).' seconds.');$processed=$dir.'/.crop-'.$user['id'].'-'.bin2hex(random_bytes(6)).'.mp3';$command='ffmpeg -v error -y -ss '.escapeshellarg(number_format($start,3,'.','')).' -to '.escapeshellarg(number_format($end,3,'.','')).' -i '.escapeshellarg($source).' -vn -codec:a libmp3lame -q:a 3 '.escapeshellarg($processed).' 2>&1';exec($command,$output,$exitCode);if($exitCode!==0||!is_file($processed)||filesize($processed)<100)throw new RuntimeException('Audio cropping failed.');$source=$processed;$temporarySource=true;$extension='mp3';}else $extension=$formats[$mime];
            $hash=substr(hash_file('sha256',$source),0,16);$target=$dir.'/user-'.$user['id'].'-'.$hash.'.'.$extension;if($source!==$target){if(is_file($target)){if($temporarySource)unlink($source);}elseif($temporarySource){if(!rename($source,$target))throw new RuntimeException('Unable to store cropped sound.');}elseif(!move_uploaded_file($source,$target))throw new RuntimeException('Unable to store custom sound.');}foreach(glob($dir.'/user-'.$user['id'].'-*.*')?:[] as $old)if(is_file($old)&&$old!==$target)unlink($old);foreach(glob($dir.'/user-'.$user['id'].'.*')?:[] as $legacy)if(is_file($legacy)&&$legacy!==$target)unlink($legacy);$soundUrl='/uploads/notifications/'.basename($target);$soundType='custom';}
        $save=$db->prepare('INSERT INTO user_notification_settings(user_id,enabled,sound_type,sound_url,volume,play_mode) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),sound_type=VALUES(sound_type),sound_url=VALUES(sound_url),volume=VALUES(volume),play_mode=VALUES(play_mode)');$save->execute([$user['id'],$enabled,$soundType,$soundUrl,$volume,$playMode]);$json(['data'=>['enabled'=>(bool)$enabled,'sound_type'=>$soundType,'sound_url'=>$soundUrl,'volume'=>$volume,'play_mode'=>$playMode]]);
    }
    if ($path === '/api/admin/display-settings' && $method==='POST') {
        Auth::require(['super_admin','admin']); $csrf($_POST);
        if(!array_key_exists('media_type',$_POST)) throw new RuntimeException('Media source is required; existing settings were not changed.');
        $mediaType=(string)$_POST['media_type']; $headerMode=(string)($_POST['header_mode']??'text');
        if(!in_array($mediaType,['none','local','playlist','youtube','obs'],true)) throw new RuntimeException('Media type is invalid.');
        if(!in_array($headerMode,['text','image'],true)) throw new RuntimeException('Header mode is invalid.');
        $mediaUrl=trim((string)($_POST['media_url']??'')); $headerImageUrl=trim((string)($_POST['header_image_url']??''));
        $storeUpload=static function(string $field,string $folder,array $mimes,int $maxBytes): ?string {
            if(!isset($_FILES[$field])||($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
            if($_FILES[$field]['error']!==UPLOAD_ERR_OK) throw new RuntimeException("Upload {$field} failed.");
            if($_FILES[$field]['size']>$maxBytes) throw new RuntimeException("Uploaded {$field} is too large.");
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
            if(!isset($mimes[$mime])) throw new RuntimeException("Uploaded {$field} format is not supported.");
            $dir=dirname(__DIR__)."/public/uploads/{$folder}"; if(!is_dir($dir)) mkdir($dir,0775,true);
            foreach(glob($dir.'/current.*')?:[] as $old) if(is_file($old)) unlink($old);
            $target=$dir.'/current.'.$mimes[$mime];
            if(!move_uploaded_file($_FILES[$field]['tmp_name'],$target)) throw new RuntimeException("Unable to store {$field}.");
            return "/uploads/{$folder}/".basename($target);
        };
        $videoUpload=$storeUpload('media_file','media',['video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv'],512*1024*1024); if($videoUpload)$mediaUrl=$videoUpload;
        $playlistDir=dirname(__DIR__).'/public/uploads/media/playlist';if(!is_dir($playlistDir))mkdir($playlistDir,0775,true);if(isset($_POST['clear_playlist']))foreach(glob($playlistDir.'/*.{mp4,webm,ogv}',GLOB_BRACE)?:[] as $old)if(is_file($old))unlink($old);
        if(isset($_FILES['playlist_files']['name'])&&is_array($_FILES['playlist_files']['name'])){foreach($_FILES['playlist_files']['name'] as $i=>$name){$error=$_FILES['playlist_files']['error'][$i]??UPLOAD_ERR_NO_FILE;if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('A playlist video failed to upload.');if(($_FILES['playlist_files']['size'][$i]??0)>512*1024*1024)throw new RuntimeException('A playlist video exceeds 512 MB.');$tmp=$_FILES['playlist_files']['tmp_name'][$i];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$formats=['video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv'];if(!isset($formats[$mime]))throw new RuntimeException('Playlist supports MP4, WebM, and OGG only.');$safe=preg_replace('/[^a-zA-Z0-9_-]+/','-',pathinfo((string)$name,PATHINFO_FILENAME));$target=$playlistDir.'/'.trim($safe,'-').'-'.substr(hash_file('sha256',$tmp),0,8).'.'.$formats[$mime];if(!move_uploaded_file($tmp,$target))throw new RuntimeException('Unable to store a playlist video.');}}
        if(in_array($mediaType,['youtube','obs'],true)&&$mediaUrl!==''&&!filter_var($mediaUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('A valid media URL is required.');
        if($mediaType==='local'&&$mediaUrl!==''&&!str_starts_with($mediaUrl,'/')&&!filter_var($mediaUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('Local media path is invalid.');
        if(in_array($mediaType,['youtube','obs','local'],true)&&$mediaUrl==='') throw new RuntimeException('The selected media source requires a URL or uploaded file; existing settings were not changed.');
        if($mediaType==='playlist'&&!(glob($playlistDir.'/*.{mp4,webm,ogv}',GLOB_BRACE)?:[])) throw new RuntimeException('Upload at least one playlist video; existing settings were not changed.');
        if($headerImageUrl!==''&&!str_starts_with($headerImageUrl,'/')&&!filter_var($headerImageUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('Header image path is invalid.');
        $values=['display_media_type'=>$mediaType,'display_media_url'=>$mediaUrl,'display_media_muted'=>isset($_POST['media_muted'])?'1':'0'];
        $db=Database::connection();$previousStmt=$db->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('display_media_type','display_media_url','display_media_muted')");$previous=array_column($previousStmt->fetchAll(),'value','key');
        $stmt=$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)'); foreach($values as $key=>$value)$stmt->execute([$key,$value]);
        Audit::log('settings.display_media_updated','settings',null,['previous'=>$previous,'current'=>$values]);
        $json(['data'=>$values]);
    }
    if (preg_match('#^/api/operator/tickets/(\d+)/(recall|serve|complete|skip|cancel)$#',$path,$m) && $method==='POST') {
        $user=Auth::require(['super_admin','admin','operator']); $data=$input(); $csrf($data);
        $ticket=(new QueueService)->transition((int)$m[1],$m[2],(int)$data['counter_id'],(int)$user['id'],$data['reason']??null); $json(['data'=>$ticket]);
    }
    if ($path === '/admin/services') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'){$csrf($_POST);$action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);if($action==='delete'){$record=$db->prepare('SELECT id,name FROM services WHERE id=?');$record->execute([$id]);$record=$record->fetch();if(!$record)$redirect('/admin/services?error=not_found');$tickets=$db->prepare('SELECT COUNT(*) FROM tickets WHERE service_id=?');$tickets->execute([$id]);$users=$db->prepare('SELECT COUNT(*) FROM user_services WHERE service_id=?');$users->execute([$id]);if((int)$tickets->fetchColumn()>0)$redirect('/admin/services?error=has_history');if((int)$users->fetchColumn()>0)$redirect('/admin/services?error=assigned_user');$db->prepare('DELETE FROM services WHERE id=?')->execute([$id]);Audit::log('service.deleted','service',$id,['name'=>$record['name']]);$redirect('/admin/services?deleted=1');}$name=trim((string)($_POST['name']??''));$code=strtoupper(trim((string)($_POST['code']??'')));$description=trim((string)($_POST['description']??''));$color=(string)($_POST['color']??'#2563eb');$minutes=max(1,(int)($_POST['minutes']??5));$active=isset($_POST['active'])?1:0;if($name===''||$code==='')throw new RuntimeException('Nama dan kode layanan wajib diisi.');if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color))throw new RuntimeException('Warna layanan tidak valid.');if($id){$stmt=$db->prepare('UPDATE services SET name=?,code=?,description=?,color=?,avg_service_minutes=?,active=? WHERE id=?');$stmt->execute([$name,$code,$description,$color,$minutes,$active,$id]);Audit::log('service.updated','service',$id,['name'=>$name]);}else{$stmt=$db->prepare('INSERT INTO services(name,code,description,color,avg_service_minutes,active) VALUES (?,?,?,?,?,?)');$stmt->execute([$name,$code,$description,$color,$minutes,$active]);$id=(int)$db->lastInsertId();Audit::log('service.created','service',$id,['name'=>$name]);}$redirect('/admin/services?saved=1');}
        $services=$db->query('SELECT * FROM services ORDER BY id')->fetchAll();View::render('services',compact('services'));exit;
    }
    if ($path === '/admin/sub-services') {
        $user=Auth::require(['super_admin','admin','operator']);$db=Database::connection();
        if($user['role']==='operator'){$allowedStmt=$db->prepare('SELECT s.* FROM services s JOIN user_services us ON us.service_id=s.id WHERE us.user_id=? ORDER BY s.name');$allowedStmt->execute([$user['id']]);$services=$allowedStmt->fetchAll();}
        else $services=$db->query('SELECT * FROM services ORDER BY name')->fetchAll();
        $allowedIds=array_map('intval',array_column($services,'id'));$assertAllowed=static function(int $serviceId)use($allowedIds):void{if(!in_array($serviceId,$allowedIds,true))throw new RuntimeException('Anda tidak memiliki akses ke layanan tersebut.');};
        if($method==='POST'){$csrf($_POST);$action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);
            if($action==='delete'){$recordStmt=$db->prepare('SELECT * FROM sub_services WHERE id=?');$recordStmt->execute([$id]);$record=$recordStmt->fetch();if(!$record)$redirect('/admin/sub-services?error=not_found');$assertAllowed((int)$record['service_id']);$history=$db->prepare('SELECT COUNT(*) FROM tickets WHERE sub_service_id=? OR (sub_service_name=? AND service_id=?)');$history->execute([$id,$record['name'],$record['service_id']]);if((int)$history->fetchColumn()>0)$redirect('/admin/sub-services?error=has_history');$db->prepare('DELETE FROM sub_services WHERE id=?')->execute([$id]);Audit::log('sub_service.deleted','sub_service',$id,['name'=>$record['name']]);$redirect('/admin/sub-services?deleted=1');}
            $serviceId=(int)($_POST['service_id']??0);$assertAllowed($serviceId);$name=trim((string)($_POST['name']??''));$description=trim((string)($_POST['description']??''));$sortOrder=max(0,(int)($_POST['sort_order']??0));$active=isset($_POST['active'])?1:0;if($name===''||mb_strlen($name)>150)throw new RuntimeException('Nama sublayanan wajib diisi dan maksimal 150 karakter.');if(mb_strlen($description)>255)throw new RuntimeException('Deskripsi maksimal 255 karakter.');
            if($id){$recordStmt=$db->prepare('SELECT service_id FROM sub_services WHERE id=?');$recordStmt->execute([$id]);$existingService=(int)$recordStmt->fetchColumn();if(!$existingService)throw new RuntimeException('Sublayanan tidak ditemukan.');$assertAllowed($existingService);$stmt=$db->prepare('UPDATE sub_services SET service_id=?,name=?,description=?,sort_order=?,active=? WHERE id=?');$stmt->execute([$serviceId,$name,$description?:null,$sortOrder,$active,$id]);Audit::log('sub_service.updated','sub_service',$id,['name'=>$name]);}
            else{$stmt=$db->prepare('INSERT INTO sub_services(service_id,name,description,sort_order,active) VALUES (?,?,?,?,?)');$stmt->execute([$serviceId,$name,$description?:null,$sortOrder,$active]);$id=(int)$db->lastInsertId();Audit::log('sub_service.created','sub_service',$id,['name'=>$name]);}$redirect('/admin/sub-services?saved=1');
        }
        if($allowedIds){$placeholders=implode(',',array_fill(0,count($allowedIds),'?'));$itemsStmt=$db->prepare("SELECT ss.*,s.name service_name,(SELECT COUNT(*) FROM tickets t WHERE t.sub_service_id=ss.id) ticket_count FROM sub_services ss JOIN services s ON s.id=ss.service_id WHERE ss.service_id IN ($placeholders) ORDER BY s.name,ss.sort_order,ss.name");$itemsStmt->execute($allowedIds);$subServices=$itemsStmt->fetchAll();}else $subServices=[];
        View::render('sub-services',compact('user','services','subServices'));exit;
    }
    if ($path === '/admin/counters') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'){$csrf($_POST);$action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);if($action==='delete'){$record=$db->prepare('SELECT id,name FROM counters WHERE id=?');$record->execute([$id]);$record=$record->fetch();if(!$record)$redirect('/admin/counters?error=not_found');$tickets=$db->prepare('SELECT COUNT(*) FROM tickets WHERE counter_id=?');$tickets->execute([$id]);$users=$db->prepare('SELECT COUNT(*) FROM users WHERE assigned_counter_id=?');$users->execute([$id]);if((int)$tickets->fetchColumn()>0)$redirect('/admin/counters?error=has_history');if((int)$users->fetchColumn()>0)$redirect('/admin/counters?error=assigned_user');$db->prepare('DELETE FROM counters WHERE id=?')->execute([$id]);Audit::log('counter.deleted','counter',$id,['name'=>$record['name']]);$redirect('/admin/counters?deleted=1');}$name=trim((string)($_POST['name']??''));$code=strtoupper(trim((string)($_POST['code']??'')));$active=isset($_POST['active'])?1:0;$serviceIds=array_values(array_unique(array_filter(array_map('intval',$_POST['service_ids']??[]))));if($name===''||$code==='')throw new RuntimeException('Nama dan kode loket wajib diisi.');if(!$serviceIds)throw new RuntimeException('Pilih minimal satu layanan.');$db->beginTransaction();try{if($id){$stmt=$db->prepare('UPDATE counters SET name=?,code=?,active=? WHERE id=?');$stmt->execute([$name,$code,$active,$id]);Audit::log('counter.updated','counter',$id,['name'=>$name]);}else{$stmt=$db->prepare('INSERT INTO counters(name,code,active) VALUES (?,?,?)');$stmt->execute([$name,$code,$active]);$id=(int)$db->lastInsertId();Audit::log('counter.created','counter',$id,['name'=>$name]);}$db->prepare('DELETE FROM counter_services WHERE counter_id=?')->execute([$id]);$link=$db->prepare('INSERT INTO counter_services(counter_id,service_id) VALUES (?,?)');foreach($serviceIds as $serviceId)$link->execute([$id,$serviceId]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}$redirect('/admin/counters?saved=1');}
        $counters=$db->query('SELECT c.*,GROUP_CONCAT(cs.service_id ORDER BY cs.service_id) service_ids,GROUP_CONCAT(s.name ORDER BY s.id SEPARATOR ", ") service_names FROM counters c LEFT JOIN counter_services cs ON cs.counter_id=c.id LEFT JOIN services s ON s.id=cs.service_id GROUP BY c.id ORDER BY c.id')->fetchAll();$services=$db->query('SELECT * FROM services WHERE active=1 ORDER BY id')->fetchAll();View::render('counters',compact('counters','services'));exit;
    }
    if ($path === '/admin/users') {
        $currentUser=Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'&&($_POST['action']??'')==='delete'){$csrf($_POST);$id=(int)($_POST['id']??0);if($id===(int)$currentUser['id'])$redirect('/admin/users?error=self');$record=$db->prepare("SELECT id,name,role FROM users WHERE id=? AND role<>'super_admin'");$record->execute([$id]);$record=$record->fetch();if(!$record)$redirect('/admin/users?error=protected');$db->prepare("DELETE FROM users WHERE id=? AND role<>'super_admin'")->execute([$id]);Audit::log('user.deleted','user',$id,['name'=>$record['name'],'role'=>$record['role']]);$redirect('/admin/users?deleted=1');}
        if($method==='POST'){$csrf($_POST);$id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$role=(string)($_POST['role']??'operator');$counterId=(int)($_POST['assigned_counter_id']??0)?:null;$active=isset($_POST['active'])?1:0;$serviceIds=array_values(array_unique(array_filter(array_map('intval',$_POST['service_ids']??[]))));if($name===''||$username==='')throw new RuntimeException('Name and username are required.');if(!in_array($role,['admin','operator'],true))throw new RuntimeException('Role is invalid.');if($role==='operator'&&!$serviceIds)throw new RuntimeException('Assign at least one service to an operator.');if(!$id&&strlen($password)<10)throw new RuntimeException('New user password must contain at least 10 characters.');$db->beginTransaction();try{if($id){if($password!==''){if(strlen($password)<10)throw new RuntimeException('Password must contain at least 10 characters.');$stmt=$db->prepare('UPDATE users SET name=?,username=?,password_hash=?,role=?,assigned_counter_id=?,active=? WHERE id=? AND role<>\'super_admin\'');$stmt->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role,$counterId,$active,$id]);}else{$stmt=$db->prepare('UPDATE users SET name=?,username=?,role=?,assigned_counter_id=?,active=? WHERE id=? AND role<>\'super_admin\'');$stmt->execute([$name,$username,$role,$counterId,$active,$id]);}}else{$stmt=$db->prepare('INSERT INTO users(name,username,password_hash,role,assigned_counter_id,active) VALUES (?,?,?,?,?,?)');$stmt->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role,$counterId,$active]);$id=(int)$db->lastInsertId();}$db->prepare('DELETE FROM user_services WHERE user_id=?')->execute([$id]);if($role==='operator'){$link=$db->prepare('INSERT INTO user_services(user_id,service_id) VALUES (?,?)');foreach($serviceIds as $serviceId)$link->execute([$id,$serviceId]);}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}$redirect('/admin/users?saved=1');}
        $users=$db->query("SELECT u.id,u.name,u.username,u.role,u.assigned_counter_id,u.active,c.name counter_name,GROUP_CONCAT(us.service_id ORDER BY us.service_id) service_ids,GROUP_CONCAT(s.name ORDER BY s.id SEPARATOR ', ') service_names FROM users u LEFT JOIN counters c ON c.id=u.assigned_counter_id LEFT JOIN user_services us ON us.user_id=u.id LEFT JOIN services s ON s.id=us.service_id WHERE u.role<>'super_admin' GROUP BY u.id ORDER BY u.id")->fetchAll();$services=$db->query('SELECT * FROM services WHERE active=1 ORDER BY id')->fetchAll();$counters=$db->query('SELECT * FROM counters WHERE active=1 ORDER BY name')->fetchAll();View::render('users',compact('users','services','counters'));exit;
    }
    if ($path === '/api/admin/header-height' && $method==='POST') {
        Auth::require(['super_admin','admin']);$data=$input();$csrf($data);$mode=(string)($data['mode']??'fixed');$height=max(60,min(300,(int)($data['height']??100)));if(!in_array($mode,['auto','fixed'],true))throw new RuntimeException('Header height mode is invalid.');$stmt=Database::connection()->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');$stmt->execute(['header_height_mode',$mode]);$stmt->execute(['header_height_px',(string)$height]);$json(['data'=>['mode'=>$mode,'height'=>$height]]);
    }
    if ($path === '/admin/timezone' && $method==='POST') {
        Auth::require(['super_admin','admin']);$csrf($_POST);$timezone=(string)($_POST['app_timezone']??'Asia/Jakarta');
        if(!in_array($timezone,['Asia/Jakarta','Asia/Makassar','Asia/Jayapura','UTC'],true))throw new RuntimeException('Time zone is invalid.');
        $stmt=Database::connection()->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');$stmt->execute(['app_timezone',$timezone]);
        $redirect('/admin/settings?timezone_saved=1');
    }
    if ($path === '/admin/settings') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'){$csrf($_POST);$values=['app_name'=>trim((string)($_POST['app_name']??'')),'header_mode'=>(string)($_POST['header_mode']??'text'),'header_title'=>trim((string)($_POST['header_title']??'')),'header_subtitle'=>trim((string)($_POST['header_subtitle']??'')),'header_image_url'=>trim((string)($_POST['header_image_url']??'')),'primary_color'=>(string)($_POST['primary_color']??'#075f91'),'secondary_color'=>(string)($_POST['secondary_color']??'#1478c8'),'accent_color'=>(string)($_POST['accent_color']??'#ffd94f'),'footer_text'=>trim((string)($_POST['footer_text']??'')),'ticket_header'=>trim((string)($_POST['ticket_header']??'')),'ticket_footer'=>trim((string)($_POST['ticket_footer']??''))];if($values['app_name']===''||$values['footer_text']===''||$values['ticket_header']===''||$values['ticket_footer']==='')throw new RuntimeException('Application, display footer, and ticket header/footer are required.');if(mb_strlen($values['ticket_header'])>300||mb_strlen($values['ticket_footer'])>300)throw new RuntimeException('Ticket header and footer are limited to 300 characters.');if(!in_array($values['header_mode'],['text','image'],true))throw new RuntimeException('Header mode is invalid.');if($values['header_image_url']!==''&&!str_starts_with($values['header_image_url'],'/')&&!filter_var($values['header_image_url'],FILTER_VALIDATE_URL))throw new RuntimeException('Header image URL is invalid.');foreach(['primary_color','secondary_color','accent_color'] as $color)if(!preg_match('/^#[0-9a-fA-F]{6}$/',$values[$color]))throw new RuntimeException('Theme color is invalid.');if(isset($_FILES['header_image'])&&($_FILES['header_image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if($_FILES['header_image']['error']!==UPLOAD_ERR_OK||$_FILES['header_image']['size']>10*1024*1024)throw new RuntimeException('Header image upload failed or exceeds 10 MB.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['header_image']['tmp_name']);$formats=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($formats[$mime]))throw new RuntimeException('Header image must be JPG, PNG, or WebP.');$dir=dirname(__DIR__).'/public/uploads/header';if(!is_dir($dir))mkdir($dir,0775,true);foreach(glob($dir.'/header-*.*')?:[] as $old)if(is_file($old))unlink($old);$name='header-'.substr(hash_file('sha256',$_FILES['header_image']['tmp_name']),0,12).'.'.$formats[$mime];$target=$dir.'/'.$name;if(!move_uploaded_file($_FILES['header_image']['tmp_name'],$target))throw new RuntimeException('Unable to store header image.');$values['header_image_url']='/uploads/header/'.$name;}$stmt=$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');foreach($values as $key=>$value)$stmt->execute([$key,$value]);$redirect('/admin/settings?saved=1');}
        $rows=$db->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('app_name','header_mode','header_title','header_subtitle','header_image_url','header_height_mode','header_height_px','primary_color','secondary_color','accent_color','footer_text','ticket_header','ticket_footer','display_media_type','display_media_url','display_media_muted')")->fetchAll();$branding=array_column($rows,'value','key');$playlistFiles=[];foreach(glob(dirname(__DIR__).'/public/uploads/media/playlist/*.{mp4,webm,ogv}',GLOB_BRACE)?:[] as $file)$playlistFiles[]=basename($file);sort($playlistFiles);View::render('settings',compact('branding','playlistFiles'));exit;
    }
    if ($path === '/admin/downloads') { Auth::require(['super_admin','admin']);View::render('downloads');exit; }
    if ($path === '/admin/registrations') {
        Auth::require(['super_admin','admin']);$db=Database::connection();$date=(string)($_GET['date']??date('Y-m-d'));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=date('Y-m-d');$status=(string)($_GET['status']??'');$allowed=['registered','checked_in','cancelled','expired'];$sql="SELECT r.*,s.name service_name,t.ticket_number FROM online_registrations r JOIN services s ON s.id=r.service_id LEFT JOIN tickets t ON t.id=r.ticket_id WHERE r.visit_date=?";$params=[$date];if(in_array($status,$allowed,true)){$sql.=' AND r.status=?';$params[]=$status;}$sql.=' ORDER BY r.created_at DESC LIMIT 500';$stmt=$db->prepare($sql);$stmt->execute($params);$registrations=$stmt->fetchAll();View::render('registrations',compact('registrations','date','status'));exit;
    }
    if ($path === '/reports') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        $period=(string)($_GET['period']??'daily');if(!in_array($period,['daily','monthly','range'],true))$period='daily';
        $today=new DateTimeImmutable('today');$selectedDate=(string)($_GET['date']??$today->format('Y-m-d'));$selectedMonth=(string)($_GET['month']??$today->format('Y-m'));$dateFrom=(string)($_GET['from']??$today->format('Y-m-d'));$dateTo=(string)($_GET['to']??$today->format('Y-m-d'));
        $validDate=static fn(string $value):bool=>(bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)&&DateTimeImmutable::createFromFormat('!Y-m-d',$value)?->format('Y-m-d')===$value;
        if($period==='daily'){if(!$validDate($selectedDate))$selectedDate=$today->format('Y-m-d');$start=$end=$selectedDate;$periodLabel='Harian · '.(new DateTimeImmutable($start))->format('d/m/Y');}
        elseif($period==='monthly'){if(!preg_match('/^\d{4}-\d{2}$/',$selectedMonth))$selectedMonth=$today->format('Y-m');$monthDate=DateTimeImmutable::createFromFormat('!Y-m',$selectedMonth)?:$today;$start=$monthDate->format('Y-m-01');$end=$monthDate->format('Y-m-t');$periodLabel='Bulanan · '.$monthDate->format('m/Y');}
        else{if(!$validDate($dateFrom))$dateFrom=$today->format('Y-m-d');if(!$validDate($dateTo))$dateTo=$today->format('Y-m-d');if($dateFrom>$dateTo)[$dateFrom,$dateTo]=[$dateTo,$dateFrom];$start=$dateFrom;$end=$dateTo;$periodLabel='Periode · '.(new DateTimeImmutable($start))->format('d/m/Y').' – '.(new DateTimeImmutable($end))->format('d/m/Y');}
        $stmt=$db->prepare("SELECT t.queue_date,s.name service_name,t.sub_service_name,COUNT(*) total,SUM(t.status='waiting') waiting,SUM(t.status IN ('called','serving')) active,SUM(t.status='completed') completed,SUM(t.status='skipped') skipped,SUM(t.status='cancelled') cancelled,ROUND(AVG(CASE WHEN t.called_at IS NOT NULL AND t.completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,t.called_at,t.completed_at) END),1) avg_minutes FROM tickets t JOIN services s ON s.id=t.service_id WHERE t.queue_date BETWEEN ? AND ? GROUP BY t.queue_date,s.id,s.name,t.sub_service_name ORDER BY t.queue_date DESC,s.name,t.sub_service_name");$stmt->execute([$start,$end]);$rows=$stmt->fetchAll();
        $summary=['total'=>0,'waiting'=>0,'active'=>0,'completed'=>0,'skipped'=>0,'cancelled'=>0];foreach($rows as $row)foreach(array_keys($summary) as $key)$summary[$key]+=(int)$row[$key];
        if (($_GET['format']??'')==='csv') { header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="rekap-antrean-'.$start.'-'.$end.'.csv"');$o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,['Tanggal','Layanan','Sublayanan','Total','Menunggu','Aktif','Selesai','Dilewati','Dibatalkan','Rata-rata layanan (menit)']);foreach($rows as $r)fputcsv($o,[$r['queue_date'],$r['service_name'],$r['sub_service_name']??'',$r['total'],$r['waiting'],$r['active'],$r['completed'],$r['skipped'],$r['cancelled'],$r['avg_minutes']??'']);exit;}
        View::render('reports',compact('rows','summary','period','periodLabel','selectedDate','selectedMonth','dateFrom','dateTo','start','end'));exit;
    }
    http_response_code(404); View::render('404');
} catch (Throwable $e) {
    error_log($e->__toString());
    if (str_starts_with($path,'/api/')) $json(['error'=>$e->getMessage()],422);
    http_response_code(500); View::render('error',['message'=>env('APP_DEBUG')==='true'?$e->getMessage():'Terjadi kesalahan. Silakan coba kembali.']);
}
